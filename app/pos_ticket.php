<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/partials.php';

$user = auth_require();
$pdo  = db();

$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: pos.php');
    exit;
}

$st = $pdo->prepare(
    'SELECT v.id, v.sucursal_id, v.fecha, v.subtotal, v.iva, v.total, v.estado,
            s.nombre AS sucursal,
            c.nombre AS comprador, c.rut AS comprador_rut,
            u.username AS cajero
       FROM ventas v
       INNER JOIN sucursales s ON s.id = v.sucursal_id
       LEFT JOIN compradores c ON c.id = v.comprador_id
       INNER JOIN usuarios u ON u.id = v.usuario_id
      WHERE v.id = :id'
);
$st->execute([':id' => $id]);
$venta = $st->fetch();

if (!$venta) {
    http_response_code(404);
    exit('Venta no encontrada.');
}

if ($user['rol'] !== 'ADMIN' && (int) $venta['sucursal_id'] !== (int) $user['sucursal_id']) {
    http_response_code(403);
    exit('No tienes acceso a ventas de otra sucursal.');
}

$st = $pdo->prepare(
    'SELECT d.cantidad, d.precio_unitario, d.subtotal, p.sku, p.nombre
       FROM venta_detalle d
       INNER JOIN productos p ON p.id = d.producto_id
      WHERE d.venta_id = :id
      ORDER BY d.id'
);
$st->execute([':id' => $id]);
$lineas = $st->fetchAll();

$subtotalCents = (int) round(((float) $venta['subtotal']) * 100);
$ivaCents      = (int) round(((float) $venta['iva']) * 100);
$totalCents    = (int) round(((float) $venta['total']) * 100);

$fecha = date('d-m-Y H:i', strtotime((string) $venta['fecha']));
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#050a12">
    <title>Venta #<?= (int) $venta['id'] ?> · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <?php render_barra($user, 'pos'); ?>

    <main class="panel panel-estrecho">
        <section class="bienvenida no-print">
            <p class="eyebrow">RF15 · Comprobante</p>
            <h1>Venta registrada</h1>
            <p>El stock fue descontado y el movimiento quedó en el kardex.</p>
        </section>

        <section class="detalle ticket">
            <header class="ticket-cab">
                <div>
                    <p class="eyebrow">Comprobante interno</p>
                    <h2>Venta #<?= (int) $venta['id'] ?></h2>
                </div>
                <span class="chip"><?= e((string) $venta['estado']) ?></span>
            </header>

            <dl class="ticket-meta">
                <dt>Fecha</dt>
                <dd><?= e($fecha) ?></dd>
                <dt>Sucursal</dt>
                <dd><?= e((string) $venta['sucursal']) ?></dd>
                <dt>Cajero</dt>
                <dd><?= e((string) $venta['cajero']) ?></dd>
                <dt>Comprador</dt>
                <dd>
                    <?= e((string) ($venta['comprador'] ?? 'Consumidor final')) ?>
                    <?php if (!empty($venta['comprador_rut'])): ?>
                        <span class="mono"> · <?= e((string) $venta['comprador_rut']) ?></span>
                    <?php endif; ?>
                </dd>
            </dl>

            <div class="tabla-scroll">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Producto</th>
                            <th class="num">Cant.</th>
                            <th class="num">P. unitario</th>
                            <th class="num">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lineas as $l): ?>
                            <tr>
                                <td class="mono"><?= e((string) $l['sku']) ?></td>
                                <td><?= e((string) $l['nombre']) ?></td>
                                <td class="num"><?= (int) $l['cantidad'] ?></td>
                                <td class="num"><?= pesos((int) round(((float) $l['precio_unitario']) * 100)) ?></td>
                                <td class="num"><?= pesos((int) round(((float) $l['subtotal']) * 100)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <dl class="totales">
                <dt>Subtotal</dt>
                <dd><?= pesos($subtotalCents) ?></dd>
                <dt>IVA 19%</dt>
                <dd><?= pesos($ivaCents) ?></dd>
                <dt class="total-final">Total</dt>
                <dd class="total-final"><?= pesos($totalCents) ?></dd>
            </dl>

            <p class="nota">
                Se registró un movimiento <code>SALIDA</code> por cada línea en
                <code>transacciones</code>, con el stock resultante. Si el saldo quedó
                bajo el mínimo, también se generó una alerta.
            </p>

            <div class="acciones-form no-print">
                <a class="boton" href="pos.php">Nueva venta</a>
                <button type="button" class="btn-mini btn-secundario"
                        onclick="window.print()">Imprimir</button>
            </div>
        </section>
    </main>
</body>
</html>
