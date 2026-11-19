<?php
declare(strict_types=1);

require_once __DIR__ . '/operaciones.php';
require_once __DIR__ . '/partials.php';

$user = auth_require();
$pdo  = db();
$ctx  = op_contexto_sucursal($user, $pdo);
$suc  = (int) $ctx['id'];
$esAdmin = (bool) $ctx['es_admin'];

$params = [];
$where  = '1 = 1';
if (!$ctx['todas']) {
    $where = 'd.sucursal_id = :suc';
    $params[':suc'] = $suc;
}

$st = $pdo->prepare(
    'SELECT d.id, d.destino, d.cantidad, d.estado, d.fecha_creacion, d.fecha_entrega,
            p.sku, p.nombre AS producto, s.nombre AS sucursal,
            c.nombre AS comprador, v.id AS venta
       FROM despachos_pendientes d
       JOIN productos p ON p.id = d.producto_id
       JOIN sucursales s ON s.id = d.sucursal_id
       JOIN compradores c ON c.id = d.comprador_id
       LEFT JOIN ventas v ON v.id = d.venta_id
      WHERE ' . $where . '
      ORDER BY CASE d.estado
                 WHEN \'PENDIENTE\' THEN 0
                 WHEN \'EN_RUTA\' THEN 1
                 WHEN \'COMPLETADO\' THEN 2
                 ELSE 3 END, d.fecha_creacion DESC'
);
$st->execute($params);
$despachos = $st->fetchAll();

$productos = $pdo->query(
    'SELECT id, sku, nombre FROM productos WHERE activo = 1 ORDER BY nombre'
)->fetchAll();

$compradores = $pdo->query(
    'SELECT id, nombre, rut FROM compradores WHERE activo = 1 ORDER BY nombre'
)->fetchAll();

$compradorSel = (int) ($_GET['comprador_id'] ?? 0);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#050a12">
    <title>Despachos · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <?php render_barra($user, 'despachos'); ?>

    <main class="panel panel-ancho">
        <section class="bienvenida">
            <p class="eyebrow">CRUD · RF09</p>
            <h1>Órdenes de despacho</h1>
            <p>Solicitudes de entrega por sucursal, con cambio de estado hasta completado.</p>
        </section>

        <?php render_flash_op(); ?>

        <section class="detalle seccion">
            <h2>Nueva solicitud</h2>
            <form method="post" action="despacho_guardar.php" class="formulario">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

                <label for="producto_id">Producto</label>
                <select id="producto_id" name="producto_id" required>
                    <?php foreach ($productos as $p): ?>
                        <option value="<?= (int) $p['id'] ?>">
                            <?= e((string) $p['sku']) ?> · <?= e((string) $p['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php if ($esAdmin): ?>
                    <label for="sucursal_id">Sucursal de origen</label>
                    <select id="sucursal_id" name="sucursal_id" required>
                        <?php foreach ($ctx['sucursales'] as $s): ?>
                            <option value="<?= (int) $s['id'] ?>"
                                <?= $suc === (int) $s['id'] ? 'selected' : '' ?>>
                                <?= e((string) $s['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="hidden" name="sucursal_id" value="<?= $suc ?>">
                <?php endif; ?>

                <label for="comprador_id">Comprador</label>
                <select id="comprador_id" name="comprador_id" required>
                    <?php foreach ($compradores as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"
                            <?= $compradorSel === (int) $c['id'] ? 'selected' : '' ?>>
                            <?= e((string) $c['nombre']) ?>
                            <?= $c['rut'] ? '(' . e((string) $c['rut']) . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$compradores): ?>
                    <p class="nota">No hay compradores. Registra uno
                       <a href="comprador_nuevo.php?volver=despachos.php&sucursal_id=<?= $suc ?>">aquí</a>.</p>
                <?php endif; ?>

                <label for="destino">Destino de entrega</label>
                <input id="destino" name="destino" type="text" maxlength="200" required
                       placeholder="Dirección o punto de entrega">

                <label for="cantidad">Cantidad</label>
                <input id="cantidad" name="cantidad" type="number" min="1" step="1" required>

                <label for="venta_id">Folio de venta (opcional)</label>
                <input id="venta_id" name="venta_id" type="number" min="1" step="1">

                <p class="acciones">
                    <button class="boton" type="submit">Registrar despacho</button>
                    <a class="btn-mini btn-secundario"
                       href="comprador_nuevo.php?volver=despachos.php&sucursal_id=<?= $suc ?>">
                        Nuevo comprador
                    </a>
                </p>
            </form>
        </section>

        <section class="detalle seccion">
            <h2>Listado (<?= e($ctx['nombre']) ?>)</h2>
            <div class="tabla-scroll">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>#</th><th>Sucursal</th><th>Producto</th><th>Comprador</th>
                            <th>Destino</th><th>Cant.</th><th>Estado</th>
                            <th>Creación</th><th>Entrega</th><th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$despachos): ?>
                        <tr><td colspan="10" class="vacio">Sin despachos para este filtro.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($despachos as $d): ?>
                        <tr>
                            <td><?= (int) $d['id'] ?></td>
                            <td><?= e((string) $d['sucursal']) ?></td>
                            <td><?= e((string) $d['sku']) ?> · <?= e((string) $d['producto']) ?></td>
                            <td><?= e((string) $d['comprador']) ?></td>
                            <td><?= e((string) $d['destino']) ?></td>
                            <td><?= (int) $d['cantidad'] ?></td>
                            <td><strong><?= e((string) $d['estado']) ?></strong></td>
                            <td><?= e(substr((string) $d['fecha_creacion'], 0, 16)) ?></td>
                            <td><?= $d['fecha_entrega'] ? e(substr((string) $d['fecha_entrega'], 0, 16)) : '—' ?></td>
                            <td>
                                <div class="acciones-tabla">
                                <?php if ($d['estado'] === 'PENDIENTE'): ?>
                                    <form method="post" action="despacho_estado.php">
                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                        <input type="hidden" name="estado" value="EN_RUTA">
                                        <button class="btn-mini" type="submit">En ruta</button>
                                    </form>
                                <?php elseif ($d['estado'] === 'EN_RUTA'): ?>
                                    <form method="post" action="despacho_estado.php">
                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                        <input type="hidden" name="estado" value="COMPLETADO">
                                        <button class="btn-mini" type="submit">Completado</button>
                                    </form>
                                <?php endif; ?>
                                <?php if (in_array($d['estado'], ['PENDIENTE', 'EN_RUTA'], true)): ?>
                                    <form method="post" action="despacho_estado.php">
                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                        <input type="hidden" name="estado" value="CANCELADO">
                                        <button class="btn-mini btn-secundario" type="submit">Cancelar</button>
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
    </main>
</body>
</html>
