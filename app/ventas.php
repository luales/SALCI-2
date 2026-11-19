<?php
declare(strict_types=1);

require_once __DIR__ . '/operaciones.php';
require_once __DIR__ . '/partials.php';

$user = auth_require();
$pdo  = db();
$ctx  = op_contexto_sucursal($user, $pdo, 'sucursal_id', true);

$where  = '1 = 1';
$params = [];
if (!$ctx['todas']) {
    $where = 'v.sucursal_id = :suc';
    $params[':suc'] = $ctx['id'];
}

$st = $pdo->prepare(
    'SELECT v.id, v.fecha, v.subtotal, v.iva, v.total, v.estado,
            s.nombre AS sucursal, c.nombre AS comprador,
            u.username AS cajero,
            (SELECT COUNT(*) FROM venta_detalle d WHERE d.venta_id = v.id) AS lineas,
            ISNULL((SELECT SUM(d.cantidad) FROM venta_detalle d
                     WHERE d.venta_id = v.id), 0) AS unidades
       FROM ventas v
       JOIN sucursales s ON s.id = v.sucursal_id
       JOIN usuarios u ON u.id = v.usuario_id
       LEFT JOIN compradores c ON c.id = v.comprador_id
      WHERE ' . $where . '
      ORDER BY v.fecha DESC, v.id DESC'
);
$st->execute($params);
$ventas = $st->fetchAll();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#050a12">
    <title>Ventas (N:N) · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <?php render_barra($user, 'ventas'); ?>

    <main class="panel panel-ancho">
        <section class="bienvenida">
            <p class="eyebrow">CRUD · tabla de transacciones N:N · RF15</p>
            <h1>Ventas registradas</h1>
            <p>
                <code>ventas</code> se relaciona con <code>productos</code> a través de
                <code>venta_detalle</code>. Alta: POS · lectura: este listado y el ticket ·
                baja: anulación con reversa de stock.
            </p>
        </section>

        <?php render_flash_op(); ?>

        <?php if ($ctx['es_admin']): ?>
            <form method="get" action="ventas.php" class="fila filtro-reportes">
                <label for="sucursal">Sucursal</label>
                <select id="sucursal" name="sucursal_id" onchange="this.form.submit()">
                    <option value="0" <?= $ctx['todas'] ? 'selected' : '' ?>>Todas</option>
                    <?php foreach ($ctx['sucursales'] as $s): ?>
                        <option value="<?= (int) $s['id'] ?>"
                            <?= $ctx['id'] === (int) $s['id'] ? 'selected' : '' ?>>
                            <?= e((string) $s['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button class="btn-mini" type="submit">Aplicar</button></noscript>
            </form>
        <?php endif; ?>

        <section class="detalle seccion">
            <div class="tabla-scroll">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>#</th><th>Fecha</th><th>Sucursal</th><th>Comprador</th>
                            <th>Cajero</th><th>Líneas</th><th>Unidades</th>
                            <th>Total</th><th>Estado</th><th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$ventas): ?>
                        <tr><td colspan="10" class="vacio">Sin ventas para este filtro.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($ventas as $v): ?>
                        <tr>
                            <td><?= (int) $v['id'] ?></td>
                            <td><?= e(substr((string) $v['fecha'], 0, 16)) ?></td>
                            <td><?= e((string) $v['sucursal']) ?></td>
                            <td><?= e((string) ($v['comprador'] ?: 'Mostrador')) ?></td>
                            <td><?= e((string) $v['cajero']) ?></td>
                            <td><?= (int) $v['lineas'] ?></td>
                            <td><?= (int) $v['unidades'] ?></td>
                            <td><?= pesos((int) round((float) $v['total'] * 100)) ?></td>
                            <td><strong><?= e((string) $v['estado']) ?></strong></td>
                            <td>
                                <div class="acciones-tabla">
                                    <a class="btn-mini" href="pos_ticket.php?id=<?= (int) $v['id'] ?>">Ver</a>
                                    <?php if ($ctx['es_admin'] && $v['estado'] === 'COMPLETADA'): ?>
                                        <form method="post" action="venta_anular.php">
                                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                                            <button class="btn-mini btn-secundario" type="submit">Anular</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <p class="nota">
            Anular devuelve cada línea al inventario con un movimiento
            <code>AJUSTE</code> y conserva la venta como <code>ANULADA</code> para auditoría.
        </p>
    </main>
</body>
</html>
