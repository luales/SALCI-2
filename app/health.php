<?php
declare(strict_types=1);

/* ---------------------------------------------------------------------
   RNF04 — Fiabilidad: disponibilidad.
   Endpoint público de monitoreo (GET /health). No requiere sesión para que
   un balanceador o script externo pueda consultarlo sin credenciales.
   Devuelve 200 si SQL Server responde y 503 si la base está caída.

   Por defecto entrega JSON (curl, monitores, verificar_rnf.ps1). Si se abre
   en un navegador (Accept: text/html) muestra una pantalla legible, porque
   el JSON crudo parece "página en blanco" para quien no espera texto.
   --------------------------------------------------------------------- */

require_once __DIR__ . '/db.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');

$estado = 'ok';
$base   = null;
$error  = null;
$info   = ['causa' => null, 'accion' => null];

try {
    $pdo  = db();
    $base = $pdo->query('SELECT DB_NAME()')->fetchColumn();
    $pdo->query('SELECT 1')->fetchColumn();
} catch (Throwable $e) {
    $estado = 'error';
    $info   = salci_error_clasificar($e);
    $error  = APP_DEBUG ? $e->getMessage() : $info['causa'];
    http_response_code(503);
}

$respuesta = [
    'status'    => $estado,
    'app'       => APP_NAME,
    'timestamp' => gmdate('c'),
    'target'    => '99.9%',
    /* RNF06: también el JSON de monitoreo explica causa y acción. */
    'causa'     => $estado === 'ok' ? null : $info['causa'],
    'accion'    => $estado === 'ok' ? null : $info['accion'],
    'checks'    => [
        'php' => [
            'ok'      => true,
            'version' => PHP_VERSION,
        ],
        'database' => [
            'ok'      => $estado === 'ok',
            'driver'  => strtok(DB_DSN, ':'),
            'name'    => $base,
            'detail'  => $error,
        ],
    ],
    'response_ms' => round(salci_response_ms(), 2),
];

$aceptado = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
$enNavegador = str_contains($aceptado, 'text/html');

if (!$enNavegador) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return;
}

/* ---------- Vista legible para el navegador ---------- */
header('Content-Type: text/html; charset=utf-8');

$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$color = $estado === 'ok' ? 'ok' : 'error';
$texto = $estado === 'ok'
    ? 'El sistema y SQL Server están respondiendo.'
    : 'SQL Server no responde. Revisa el servicio y el DB_DSN.';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="assets/tema.js"></script>
    <title>Estado del servicio · <?= $h(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="centrado">
    <button type="button" class="tema-toggle tema-flotante" data-tema-toggle
            aria-pressed="false" aria-label="Cambiar tema">◐</button>
    <div class="acceso" style="max-width:620px">
        <main class="tarjeta" style="max-width:none">
            <p class="eyebrow">RNF04 · Monitoreo</p>
            <h2>Estado del servicio</h2>

            <div class="flash <?= $color ?>" role="status">
                <strong><?= $estado === 'ok' ? 'OK' : 'SIN CONEXIÓN' ?></strong> — <?= $h($texto) ?>
            </div>

            <dl style="margin-top:20px">
                <dt>Aplicación</dt>
                <dd><?= $h($respuesta['app']) ?> · PHP <?= $h(PHP_VERSION) ?></dd>
                <dt>Base de datos</dt>
                <dd><?= $h($respuesta['checks']['database']['name'] ?? '—') ?>
                    (<?= $h($respuesta['checks']['database']['driver']) ?>)</dd>
                <dt>Disponibilidad objetivo</dt>
                <dd><?= $h($respuesta['target']) ?></dd>
                <dt>Tiempo de respuesta</dt>
                <dd><?= $h($respuesta['response_ms']) ?> ms
                    · cabecera <code>X-Response-Time-Ms</code></dd>
                <dt>Última verificación</dt>
                <dd class="mono"><?= $h($respuesta['timestamp']) ?></dd>
                <?php if ($error !== null): ?>
                    <dt>Detalle</dt>
                    <dd><?= $h($error) ?></dd>
                <?php endif; ?>
            </dl>

            <p class="nota">
                Esta pantalla es sólo para personas. Los monitores obtienen el
                mismo estado en JSON con <code>curl http://localhost/salci/health.php</code>
                o desde el panel: <a href="index.php">Volver al panel</a>.
            </p>
        </main>
    </div>
</body>
</html>
