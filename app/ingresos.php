<?php
declare(strict_types=1);

require_once __DIR__ . '/operaciones.php';
require_once __DIR__ . '/partials.php';

$user = auth_require();
$pdo  = db();
$ctx  = op_contexto_sucursal($user, $pdo);
$suc  = (int) $ctx['id'];
$esAdmin = (bool) $ctx['es_admin'];

$editar = (int) ($_GET['editar'] ?? 0);
$lote = null;
if ($editar > 0 && $esAdmin) {
    $st = $pdo->prepare('SELECT * FROM lotes WHERE id = :id AND anulado = 0');
    $st->execute([':id' => $editar]);
    $lote = $st->fetch();
}

$where = ['1 = 1'];
$params = [];
if (!$ctx['todas']) {
    $where[] = 'l.sucursal_id = :suc';
    $params[':suc'] = $suc;
}
if (isset($_GET['solo_activos'])) {
    $where[] = 'l.anulado = 0';
}

$sql = 'SELECT l.id, l.numero_lote, l.cantidad, l.fecha_ingreso, l.anulado,
               p.sku, p.nombre AS producto, pr.nombre AS proveedor,
               s.nombre AS sucursal, t.stock_resultante
          FROM lotes l
          JOIN productos p ON p.id = l.producto_id
          JOIN sucursales s ON s.id = l.sucursal_id
          LEFT JOIN proveedores pr ON pr.id = l.proveedor_id
          LEFT JOIN transacciones t
                 ON t.lote_id = l.id AND t.tipo = ' . "'INGRESO'" . '
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY l.fecha_ingreso DESC, l.id DESC';

$st = $pdo->prepare($sql);
$st->execute($params);
$lotes = $st->fetchAll();

$productos = $pdo->query(
    'SELECT id, sku, nombre FROM productos WHERE activo = 1 ORDER BY nombre'
)->fetchAll();

$proveedores = $pdo->query(
    'SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY nombre'
)->fetchAll();

$hoy = date('Y-m-d');
$v = static fn(string $k): string => (string) ($lote[$k] ?? '');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#050a12">
    <title>Ingresos y lotes · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <?php render_barra($user, 'ingresos'); ?>

    <main class="panel panel-ancho">
        <section class="bienvenida">
            <p class="eyebrow">CRUD · tabla 2 (1:N) · RF04/RF08</p>
            <h1>Ingresos de mercadería</h1>
            <p>
                Contexto: <strong><?= e($ctx['nombre']) ?></strong>. Cada ingreso
                crea un lote opcional, incrementa el inventario y deja su asiento
                en el kardex.
            </p>
        </section>

        <?php render_flash_op(); ?>

        <section class="detalle seccion">
            <h2><?= $lote ? 'Editar datos del lote #' . (int) $lote['id'] : 'Nuevo ingreso' ?></h2>
            <form method="post" action="ingreso_guardar.php" class="formulario">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int) ($lote['id'] ?? 0) ?>">

                <?php if ($lote): ?>
                    <p class="nota">
                        La cantidad, el producto y la sucursal ya fueron contabilizados.
                        Para reversar el ingreso usa <strong>Anular</strong> en el listado.
                    </p>
                <?php else: ?>
                    <label for="producto_id">Producto</label>
                    <select id="producto_id" name="producto_id" required>
                        <?php foreach ($productos as $p): ?>
                            <option value="<?= (int) $p['id'] ?>">
                                <?= e((string) $p['sku']) ?> · <?= e((string) $p['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <?php if ($esAdmin): ?>
                        <label for="sucursal_id">Sucursal</label>
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

                    <label for="cantidad">Cantidad (entero > 0)</label>
                    <input id="cantidad" name="cantidad" type="number" min="1" step="1" required>
                <?php endif; ?>

                <label for="proveedor_id">Proveedor (opcional)</label>
                <select id="proveedor_id" name="proveedor_id">
                    <option value="0">Sin proveedor</option>
                    <?php foreach ($proveedores as $pr): ?>
                        <option value="<?= (int) $pr['id'] ?>"
                            <?= (int) ($lote['proveedor_id'] ?? 0) === (int) $pr['id'] ? 'selected' : '' ?>>
                            <?= e((string) $pr['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="numero_lote">Número de lote (opcional)</label>
                <input id="numero_lote" name="numero_lote" type="text" maxlength="50"
                       value="<?= e($v('numero_lote')) ?>">

                <label for="fecha_ingreso">Fecha de recepción</label>
                <input id="fecha_ingreso" name="fecha_ingreso" type="date"
                       value="<?= e(substr($v('fecha_ingreso') ?: $hoy, 0, 10)) ?>" required>

                <p class="acciones">
                    <button class="boton" type="submit">Guardar ingreso</button>
                    <?php if ($lote): ?>
                        <a class="btn-mini btn-secundario" href="ingresos.php">Cancelar</a>
                    <?php endif; ?>
                </p>
            </form>
        </section>

        <?php if ($esAdmin): ?>
            <p class="acciones">
                <a class="btn-mini btn-secundario" href="proveedores.php">Administrar proveedores</a>
            </p>
        <?php endif; ?>

        <section class="detalle seccion">
            <div class="tabla-scroll">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>#</th><th>Lote</th><th>Producto</th><th>Sucursal</th>
                            <th>Proveedor</th><th>Cantidad</th><th>Saldo</th>
                            <th>Recepción</th><th>Estado</th><th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$lotes): ?>
                        <tr><td colspan="10" class="vacio">Sin ingresos registrados.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($lotes as $l): ?>
                        <tr>
                            <td><?= (int) $l['id'] ?></td>
                            <td><?= e((string) ($l['numero_lote'] ?: '—')) ?></td>
                            <td><?= e((string) $l['sku']) ?> · <?= e((string) $l['producto']) ?></td>
                            <td><?= e((string) $l['sucursal']) ?></td>
                            <td><?= e((string) ($l['proveedor'] ?: '—')) ?></td>
                            <td><?= (int) $l['cantidad'] ?></td>
                            <td><?= $l['stock_resultante'] !== null ? (int) $l['stock_resultante'] : '—' ?></td>
                            <td><?= e(substr((string) $l['fecha_ingreso'], 0, 10)) ?></td>
                            <td><?= (int) $l['anulado'] === 1 ? 'Anulado' : 'Vigente' ?></td>
                            <td>
                                <?php if ($esAdmin && (int) $l['anulado'] === 0): ?>
                                    <div class="acciones-tabla">
                                        <a class="btn-mini" href="ingresos.php?editar=<?= (int) $l['id'] ?>">Editar</a>
                                        <form method="post" action="lote_anular.php">
                                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                                            <button class="btn-mini btn-secundario" type="submit">Anular</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
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
