<?php

// LOCAL TEST DOUBLE of Meta's Graph API (NOT Meta). Accepts outbound messages and records what Ava sent (never the token).
$raw = file_get_contents('php://input');
$p = json_decode($raw, true);
$h = array_change_key_case(getallheaders(), CASE_LOWER);
$n = (int) @file_get_contents('/tmp/e2e/graph_seq') + 1;
file_put_contents('/tmp/e2e/graph_seq', (string) $n);
file_put_contents('/tmp/e2e/graph_double.log', json_encode([
    'path' => $_SERVER['REQUEST_URI'], 'has_auth' => isset($h['authorization']), 'type' => $p['type'] ?? null,
    'to_suffix' => substr((string) ($p['to'] ?? ''), -4), 'body' => $p['text']['body'] ?? null, 'callback' => $p['biz_opaque_callback_data'] ?? null,
], JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
echo json_encode(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.OUT'.$n]]]);
