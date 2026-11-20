<?php
declare(strict_types=1);

/* ---------------------------------------------------------------------
   RF02 — Receptor de webhook para la demostración local.
   Guarda el POST JSON que envía op_notificar() en logs/rf02_webhook.log.
   Solo responde a peticiones desde la propia máquina (127.0.0.1 / ::1).
   --------------------------------------------------------------------- */

require_once __DIR__ . '/operaciones.php';

header('Content-Type: application/json; charset=utf-8');

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Solo localhost'], JSON_UNESCAPED_UNICODE);
    return;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Se espera POST'], JSON_UNESCAPED_UNICODE);
    return;
}

$cuerpo = file_get_contents('php://input');
$datos  = json_decode($cuerpo, true);

if (!is_array($datos)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido'], JSON_UNESCAPED_UNICODE);
    return;
}

op_log_rf02('webhook', $cuerpo);

echo json_encode([
    'ok'        => true,
    'recibido'  => gmdate('c'),
    'producto'  => $datos['producto'] ?? null,
    'sucursal'  => $datos['sucursal'] ?? null,
    'mensaje'   => $datos['mensaje'] ?? null,
], JSON_UNESCAPED_UNICODE);
