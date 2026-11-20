<?php
declare(strict_types=1);

require_once __DIR__ . '/operaciones.php';
require_once __DIR__ . '/partials.php';

$user = auth_require();
$pdo  = db();
$ctx  = op_contexto_sucursal($user, $pdo, 'sucursal_id', true);
$suc  = (int) $ctx['id'];
$esAdmin = (bool) $ctx['es_admin'];
$origenForm = $suc > 0 ? $suc : (int) ($ctx['sucursales'][0]['id'] ?? 0);

$where  = "t.tipo LIKE 'TRANSFERENCIA%'";
$params = [];
if (!$ctx['todas']) {
    $where = "t.tipo LIKE 'TRANSFERENCIA%'
              AND t.grupo_id IN (
                    SELECT t2.grupo_id FROM transacciones t2
                     WHERE t2.sucursal_id = :suc
                       AND t2.tipo LIKE 'TRANSFERENCIA%'
              )";
    $params[':suc'] = $suc;
}

$st = $pdo->prepare(
    'SELECT t.id, t.grupo_id, t.tipo, t.cantidad, t.stock_resultante, t.fecha,
            p.sku, p.nombre AS producto, s.nombre AS sucursal, u.username
       FROM transacciones t
       JOIN productos p ON p.id = t.producto_id
       JOIN sucursales s ON s.id = t.sucursal_id
       JOIN usuarios u ON u.id = t.usuario_id
      WHERE ' . $where . '
      ORDER BY t.fecha DESC, t.id DESC'
);
$st->execute($params);
$movimientos = $st->fetchAll();

$productos = $pdo->query(
    'SELECT id, sku, nombre FROM productos WHERE activo = 1 ORDER BY nombre'
)->fetchAll();
?>
<!doctype html>
<html lang="es">
<head>
    <script src="assets/tema.js"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#050a12">
    <title>Transferencias · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <?php render_barra($user, 'transferencias'); ?>

    <main class="panel panel-ancho">
        <section class="bienvenida">
            <p class="eyebrow">RF12 · doble asiento</p>
            <h1>Transferencias entre sucursales</h1>
            <p>
                Descuento en origen, incremento en destino y dos filas de kardex
                con el mismo <code>grupo_id</code>. Origen y destino deben ser distintos.
            </p>
        </section>

        <?php render_flash_op(); ?>

        <?php if ($esAdmin): ?>
            <form method="get" action="transferencias.php" class="fila filtro-reportes">
                <label for="sucursal">Ver movimientos de</label>
                <select id="sucursal" name="sucursal_id" onchange="this.form.submit()">
                    <option value="0" <?= $ctx['todas'] ? 'selected' : '' ?>>Todas</option>
                    <?php foreach ($ctx['sucursales'] as $s): ?>
                        <option value="<?= (int) $s['id'] ?>"
                            <?= $suc === (int) $s['id'] ? 'selected' : '' ?>>
                            <?= e((string) $s['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button class="btn-mini" type="submit">Aplicar</button></noscript>
            </form>
        <?php endif; ?>

        <section class="detalle seccion">
            <h2>Nueva transferencia</h2>
            <form method="post" action="transferencia_guardar.php" class="formulario">
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
                    <label for="sucursal_origen">Sucursal de origen</label>
                    <select id="sucursal_origen" name="sucursal_origen" required>
                        <?php foreach ($ctx['sucursales'] as $s): ?>
                            <option value="<?= (int) $s['id'] ?>"
                                <?= $origenForm === (int) $s['id'] ? 'selected' : '' ?>>
                                <?= e((string) $s['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="hidden" name="sucursal_origen" value="<?= $suc ?>">
                    <p class="nota">Origen fijo: <?= e($ctx['nombre']) ?></p>
                <?php endif; ?>

                <label for="sucursal_destino">Sucursal de destino</label>
                <select id="sucursal_destino" name="sucursal_destino" required>
                    <option value="">Selecciona destino</option>
                    <?php foreach ($ctx['sucursales'] as $s): ?>
                        <option value="<?= (int) $s['id'] ?>">
                            <?= e((string) $s['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="cantidad">Cantidad a trasladar</label>
                <input id="cantidad" name="cantidad" type="number" min="1" step="1" required>

                <p class="acciones">
                    <button class="boton" type="submit">Transferir stock</button>
                </p>
            </form>
        </section>

        <section class="detalle seccion">
            <h2>Asientos generados</h2>
            <div class="tabla-scroll">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>#</th><th>Grupo</th><th>Lado</th><th>Producto</th>
                            <th>Sucursal</th><th>Cantidad</th><th>Saldo</th>
                            <th>Fecha</th><th>Responsable</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$movimientos): ?>
                        <tr><td colspan="9" class="vacio">Sin transferencias registradas.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($movimientos as $m): ?>
                        <tr>
                            <td><?= (int) $m['id'] ?></td>
                            <td><code><?= e(substr((string) $m['grupo_id'], 0, 8)) ?></code></td>
                            <td><?= $m['tipo'] === 'TRANSFERENCIA_SALIDA' ? 'Salida' : 'Ingreso' ?></td>
                            <td><?= e((string) $m['sku']) ?> · <?= e((string) $m['producto']) ?></td>
                            <td><?= e((string) $m['sucursal']) ?></td>
                            <td><?= (int) $m['cantidad'] ?></td>
                            <td><?= (int) $m['stock_resultante'] ?></td>
                            <td><?= e(substr((string) $m['fecha'], 0, 16)) ?></td>
                            <td><?= e((string) $m['username']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
