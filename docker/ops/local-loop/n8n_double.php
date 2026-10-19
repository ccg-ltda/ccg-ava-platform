<?php

// LOCAL TEST DOUBLE of the platform's n8n (NOT n8n, NOT a real integration).
// It verifies Ava's signature like a correct workflow must and then behaves according to the words of the message.
$secret = getenv('N8N_EXECUTION_SECRET');
$log = '/tmp/e2e/n8n_double.log';

function note(string $log, array $line): void
{
    file_put_contents($log, json_encode($line, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
}

function call(string $method, string $url, string $token, ?array $json, array $extra = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => array_merge(['Authorization: Bearer '.$token, 'Accept: application/json', 'Content-Type: application/json'], $extra),
        CURLOPT_POSTFIELDS => $json === null ? null : json_encode($json),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$code, json_decode((string) $body, true) ?? []];
}

$raw = file_get_contents('php://input');
$h = array_change_key_case(getallheaders(), CASE_LOWER);
$execution = $h['x-ava-execution'] ?? '';
$ts = $h['x-ava-timestamp'] ?? '0';
$sig = $h['x-ava-signature'] ?? '';
$valid = hash_equals('sha256='.hash_hmac('sha256', $ts.'.'.$raw, (string) $secret), $sig) && abs(time() - (int) $ts) < 300;
if (! $valid) {
    http_response_code(401);
    note($log, ['event' => 'rejected_signature']);
    exit;
}

$p = json_decode($raw, true);
$text = mb_strtolower($p['message']['text'] ?? '');
$token = $p['ava']['token'] ?? '';
$api = $p['ava']['api_url'] ?? '';
$reply = function (string $r, array $x = []) use ($execution) {
    echo json_encode(['execution_id' => $execution] + ['reply' => $r] + $x, JSON_UNESCAPED_UNICODE);
};
header('Content-Type: application/json');
note($log, ['event' => 'received', 'execution' => $execution, 'text' => $text, 'booking_tool' => $p['ava']['tools']['booking'] ?? null, 'history' => count($p['history'] ?? []), 'payload_has_contact_phone' => str_contains($raw, '5730000'), 'payload_has_meta_token' => str_contains($raw, 'LOCAL-DOUBLE-TOKEN')]);

$healthy = file_exists('/tmp/e2e/healthy');
if (! $healthy && str_contains($text, 'caido')) {
    http_response_code(503);
    exit;
}
if (! $healthy && str_contains($text, 'lento')) {
    sleep(8);
    $reply('tarde');
    exit;
}
if (str_contains($text, 'colgado')) { // the first call hangs long enough for the harness to kill the worker; later calls (the recovered run) answer at once
    if (! file_exists('/tmp/e2e/colgado_seen')) {
        touch('/tmp/e2e/colgado_seen');
        sleep(25);
    }
}
if (str_contains($text, 'invalido')) {
    echo json_encode(['execution_id' => 'otro', 'reply' => 'x']);
    exit;
}
if (str_contains($text, 'lista')) {
    echo json_encode([['execution_id' => $execution, 'reply' => 'x']]);
    exit;
}
if (str_contains($text, 'humano')) {
    $reply('Te paso con una persona.', ['handoff' => true]);
    exit;
}

if (str_contains($text, 'tokenmuerto')) {
    [$c] = call('GET', "$api/booking/services", $token.'x', null);
    $reply("token alterado: $c");
    exit;
}

if (str_contains($text, 'disponibilidad') || str_contains($text, 'reservar')) {
    [, $services] = call('GET', "$api/booking/services", $token, null);
    $serviceId = $services['services'][0]['id'] ?? null;
    $from = str_contains($text, 'sinhorario') ? '2026-01-01' : date('Y-m-d', strtotime('+1 day'));
    [$c, $av] = call('GET', "$api/booking/availability?service_id=$serviceId&from=$from&days=7", $token, null);
    $slot = $av['slots'][0]['startsAt'] ?? null;
    note($log, ['event' => 'availability', 'http' => $c, 'slots' => count($av['slots'] ?? [])]);
    if (! $slot) {
        $reply('No hay horarios disponibles.');
        exit;
    }
    if (str_contains($text, 'disponibilidad')) {
        $reply("Primer horario: $slot");
        exit;
    }

    $want = preg_match('/slot=(\S+)/', $p['message']['text'] ?? '', $m) ? $m[1] : $slot;
    $book = ['service_id' => $serviceId, 'starts_at' => $want, 'customer' => ['name' => 'Cliente Prueba']]; // D-TEL: like the real contract, the workflow does NOT send a phone (Ava never gives it one); Ava uses the WhatsApp number of the conversation
    [$c1, $r1] = call('POST', "$api/booking/appointments", $token, $book, ['Idempotency-Key: e2e-'.$execution]);
    [$c2, $r2] = call('POST', "$api/booking/appointments", $token, $book, ['Idempotency-Key: e2e-'.$execution]); // a retry of the same call
    note($log, ['event' => 'booked', 'first' => $c1, 'retry' => $c2, 'reason' => $r1['reason'] ?? null, 'errors' => $r1['errors'] ?? null, 'message' => $r1['message'] ?? null, 'confirmed' => $r1['confirmed'] ?? null, 'same_appointment' => ($r1['appointment']['id'] ?? 1) === ($r2['appointment']['id'] ?? 2)]);
    if ($c1 === 201 && ($r1['confirmed'] ?? false) === true) {
        $reply("Tu cita quedo confirmada para $want.");
    } elseif ($c1 === 201) {
        $reply('Tu cita quedo por confirmar.');
    } elseif (($r1['reason'] ?? '') === 'slot_unavailable') {
        $reply('Ese horario ya no esta disponible.');
    } else {
        $reply('No pude registrar la cita; una persona lo revisara.');
    }
    exit;
}

$reply('Hola, soy el doble de n8n. Dijiste: '.($p['message']['text'] ?? ''));
