<?php

// LOCAL TEST DRIVER: plays Meta against Ava over real HTTP (signed events) and reads the disposable e2e database. Contacts are fake numbers.
if (! str_ends_with((string) getenv('DB_DATABASE'), '_e2e')) {
    exit("refusing\n");
}
$pdo = new PDO('pgsql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
$q = fn (string $sql, array $args = []) => (function () use ($pdo, $sql, $args) {
    $s = $pdo->prepare($sql);
    $s->execute($args);

    return $s->fetchAll(PDO::FETCH_ASSOC);
})();
$seq = 0;

function deliver(string $phoneId, string $from, string $text, ?string $id = null, string $secret = 'local-meta-secret', string $url = 'http://127.0.0.1:8099/api/webhooks/whatsapp', bool $wait = true): array
{
    static $n = 0;
    $id ??= 'wamid.IN'.(++$n).bin2hex(random_bytes(3));
    $payload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
        'messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '15550001111', 'phone_number_id' => $phoneId],
        'contacts' => [['profile' => ['name' => 'Contacto Prueba'], 'wa_id' => $from]],
        'messages' => [['from' => $from, 'id' => $id, 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => $text]]],
    ]]]]]];
    $raw = json_encode($payload);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $raw, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Hub-Signature-256: sha256='.hash_hmac('sha256', $raw, $secret)]]);
    $t = microtime(true);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    global $pdo;
    for ($i = 0; $wait && $i < 40; $i++) { // the queue worker answers asynchronously: wait until nothing is open
        usleep(500000);
        if ((int) $pdo->query("select count(*) from chatbot_executions where status not in ('succeeded','failed','cancelled','discarded','skipped')")->fetchColumn() === 0 && (int) $pdo->query('select count(*) from jobs')->fetchColumn() === 0) {
            break;
        }
    }

    return ['http' => $code, 'ms' => (int) ((microtime(true) - $t) * 1000), 'id' => $id, 'body' => substr((string) $body, 0, 120)];
}

function show(string $title, mixed $data): void
{
    echo "\n### $title\n".(is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))."\n";
}

function conv($q, string $contact, string $phoneId): array
{
    return $q('select c.id, c.handling, w.code as ws from conversations c join workspaces w on w.id=c.workspace_id join chatbots b on b.id=c.chatbot_id where c.contact_id=? and w.code=?', [$contact, $phoneId === '1000001' ? 'E2E_A' : 'E2E_B'])[0] ?? [];
}

function msgs($q, int $conversationId): array
{
    return $q('select direction, sender, status, left(body,70) as body from messages where conversation_id=? order by id', [$conversationId]);
}

function execs($q, int $conversationId): array
{
    return $q('select status, outcome_code, control_result, delivery, attempts from chatbot_executions where conversation_id=? order by id', [$conversationId]);
}

function tools($q, int $conversationId): array
{
    return $q('select tool, kind, status, http_status, result_state, replayed, reason from agent_tool_calls where conversation_id=? order by id', [$conversationId]);
}

$scenario = $argv[1] ?? 'all';
$A = '1000001';
$B = '1000002';

if ($scenario === 'basic' || $scenario === 'all') {
    $r = deliver($A, '573000000001', 'Hola, buenas');
    show('1. mensaje simple -> respuesta', ['delivery' => $r]);
    $c = conv($q, '573000000001', $A);
    show('   conversacion', ['conv' => $c, 'messages' => msgs($q, $c['id']), 'executions' => execs($q, $c['id'])]);
}
if ($scenario === 'avail' || $scenario === 'all') {
    $r = deliver($A, '573000000002', 'Quiero ver la disponibilidad');
    $c = conv($q, '573000000002', $A);
    show('2. disponibilidad', ['delivery' => $r, 'messages' => msgs($q, $c['id']), 'tools' => tools($q, $c['id'])]);
    $r = deliver($A, '573000000002', 'disponibilidad sinhorario');
    show('   sin disponibilidad', ['messages' => array_slice(msgs($q, $c['id']), -1)]);
}
if ($scenario === 'book' || $scenario === 'all') {
    $r = deliver($A, '573000000003', 'Quiero reservar');
    $c = conv($q, '573000000003', $A);
    show('3. reserva', ['delivery' => $r, 'messages' => msgs($q, $c['id']), 'tools' => tools($q, $c['id']), 'executions' => execs($q, $c['id']),
        'appointments' => $q('select a.status, a.conversation_id, w.code as ws, a.starts_at from appointments a join workspaces w on w.id=a.workspace_id where a.conversation_id=?', [$c['id']]),
        'appointments_total_for_ws_A' => $q("select count(*) n from appointments a join workspaces w on w.id=a.workspace_id where w.code='E2E_A'")[0]['n'],
        'd_tel_customer_phone_is_the_whatsapp_number' => $q('select count(*) n from booking_customers bc join appointments a on a.customer_id=bc.id where a.conversation_id=? and bc.phone=?', [$c['id'], '573000000003'])[0]['n']]);
    $slot = $q('select starts_at from appointments where conversation_id=?', [$c['id']])[0]['starts_at'] ?? null;
    if ($slot) {
        $iso = (new DateTimeImmutable($slot, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $r = deliver($A, '573000000004', "Quiero reservar slot=$iso");
        $c2 = conv($q, '573000000004', $A);
        show('4. mismo horario por otro contacto (rechazo)', ['messages' => msgs($q, $c2['id']), 'tools' => tools($q, $c2['id']),
            'appointments_total_for_ws_A' => $q("select count(*) n from appointments a join workspaces w on w.id=a.workspace_id where w.code='E2E_A'")[0]['n']]);
        $r = deliver($B, '573000000004', "Quiero reservar slot=$iso");
        $c3 = conv($q, '573000000004', $B);
        show('   mismo contacto y horario en OTRO workspace (aislamiento)', ['messages' => msgs($q, $c3['id']),
            'appointments_by_ws' => $q('select w.code, count(*) n from appointments a join workspaces w on w.id=a.workspace_id group by 1 order by 1')]);
    }
}
if ($scenario === 'dup' || $scenario === 'all') {
    $r1 = deliver($A, '573000000005', 'Hola duplicado', 'wamid.DUPLICATED1');
    $r2 = deliver($A, '573000000005', 'Hola duplicado', 'wamid.DUPLICATED1');
    $c = conv($q, '573000000005', $A);
    show('5. evento duplicado de Meta', ['first' => $r1['http'], 'second' => $r2['http'], 'messages' => msgs($q, $c['id']), 'executions' => execs($q, $c['id'])]);
}
if ($scenario === 'handoff' || $scenario === 'all') {
    deliver($A, '573000000006', 'Necesito un humano');
    $c = conv($q, '573000000006', $A);
    $before = file_exists('/tmp/e2e/n8n_double.log') ? substr_count((string) file_get_contents('/tmp/e2e/n8n_double.log'), '"event":"received"') : 0;
    deliver($A, '573000000006', 'Sigo escribiendo');
    $after = substr_count((string) file_get_contents('/tmp/e2e/n8n_double.log'), '"event":"received"');
    show('6. traspaso a persona', ['conv' => conv($q, '573000000006', $A), 'messages' => msgs($q, $c['id']), 'n8n_calls_after_handoff' => $after - $before]);
}
if ($scenario === 'errors' || $scenario === 'all') {
    foreach (['caido' => 'n8n responde 503', 'invalido' => 'respuesta con otro execution_id', 'lista' => 'respuesta como lista JSON', 'lento' => 'n8n tarda mas que el timeout'] as $word => $label) {
        $contact = '57300000'.(100 + array_search($word, ['caido', 'invalido', 'lista', 'lento']));
        $r = deliver($A, $contact, "mensaje $word");
        $c = conv($q, $contact, $A);
        show("7. $label", ['http' => $r['http'], 'ms' => $r['ms'], 'conversation_handling' => $c['handling'], 'messages' => msgs($q, $c['id']), 'executions' => execs($q, $c['id'])]);
    }
}
if ($scenario === 'security' || $scenario === 'all') {
    $bad = deliver($A, '573000000009', 'Hola', null, 'wrong-secret');
    show('8. evento con firma incorrecta', ['http' => $bad['http'], 'conversations_for_contact' => $q("select count(*) n from conversations where contact_id='573000000009'")[0]['n']]);
    $r = deliver($A, '573000000010', 'prueba tokenmuerto');
    $c = conv($q, '573000000010', $A);
    show('   token alterado en una herramienta', ['messages' => msgs($q, $c['id'])]);
    $u = deliver('9999999', '573000000011', 'Hola numero ajeno');
    show('   numero que no es de nadie', ['http' => $u['http'], 'conversations' => $q("select count(*) n from conversations where contact_id='573000000011'")[0]['n']]);
}
function get(string $query): array
{
    $ch = curl_init('http://127.0.0.1:8099/api/webhooks/whatsapp?'.$query);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['http' => $code, 'body' => substr((string) $body, 0, 60)];
}

if ($scenario === 'verify' || $scenario === 'all') {
    // Meta's one-time GET: the right token returns the challenge as plain text; a wrong token, a wrong mode or no challenge are refused.
    show('0. verificacion GET del webhook', [
        'token correcto' => get('hub_mode=subscribe&hub_verify_token=local-verify&hub_challenge=abc123'),
        'token incorrecto' => get('hub_mode=subscribe&hub_verify_token=otro&hub_challenge=abc123'),
        'modo incorrecto' => get('hub_mode=unsubscribe&hub_verify_token=local-verify&hub_challenge=abc123'),
        'sin challenge' => get('hub_mode=subscribe&hub_verify_token=local-verify'),
    ]);
}
// The worker-crash scenario needs the shell around it (run.sh kills and restarts the worker between the steps): crash1, crash_early, crash_final.
if ($scenario === 'crash1') {
    deliver($A, '573000000020', 'colgado por favor', null, 'local-meta-secret', 'http://127.0.0.1:8099/api/webhooks/whatsapp', false);
    for ($i = 0; $i < 80; $i++) { // wait until the worker has claimed it: the workflow call is in flight
        if ((int) $pdo->query("select count(*) from chatbot_executions where status='running'")->fetchColumn() > 0) {
            break;
        }
        usleep(100000);
    }
    show('15a. ejecucion en curso (el trabajador va a morir)', $q('select status, attempts from chatbot_executions order by id desc limit 1'));
}
if ($scenario === 'crash_early') {
    $out = shell_exec('cd /var/www/html && php artisan executions:recover 2>&1');
    show('15b. recuperacion ANTES del umbral: no toca la ejecucion', ['salida' => trim((string) $out), 'ejecucion' => $q('select status, attempts from chatbot_executions order by id desc limit 1')]);
    $pdo->exec("update chatbot_executions set updated_at = now() - interval '3 minutes' where status='running'"); // time passes (disposable database only)
}
if ($scenario === 'crash_final') {
    $out = shell_exec('cd /var/www/html && php artisan executions:recover 2>&1');
    for ($i = 0; $i < 60; $i++) {
        usleep(500000);
        if ((int) $pdo->query("select count(*) from chatbot_executions where status in ('pending','running')")->fetchColumn() === 0) {
            break;
        }
    }
    $c = conv($q, '573000000020', $A);
    show('15c. recuperacion tras el umbral con un trabajador nuevo', ['salida' => trim((string) $out), 'ejecuciones' => execs($q, $c['id']), 'mensajes' => msgs($q, $c['id']), 'respuestas_salientes' => $q("select count(*) n from messages where conversation_id=? and direction='out'", [$c['id']])[0]['n']]);
}

if ($scenario === 'summary') {
    show('executions', $q('select status, outcome_code, count(*) n from chatbot_executions group by 1,2 order by 1,2'));
    show('tool calls', $q('select tool, status, http_status, replayed, count(*) n from agent_tool_calls group by 1,2,3,4 order by 1,2'));
    show('messages out', $q("select sender, status, count(*) n from messages where direction='out' group by 1,2"));
    show('D-FALLO: conversations handed to a person by an AI failure', $q("select control_result, outcome_code, count(*) n from chatbot_executions where status in ('failed','discarded') group by 1,2 order by 1,2"));
    $alerts = file_exists('/tmp/e2e/alerts.log') ? substr_count((string) file_get_contents('/tmp/e2e/alerts.log'), 'ai_failure_handed_to_human') : 0;
    show('D-FALLO: operational alerts written (local file only, nothing external)', ['ai_failure_handed_to_human' => $alerts]);
}
