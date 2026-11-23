<?php
declare(strict_types=1);

require_once __DIR__ . '/operaciones.php';
require_once __DIR__ . '/partials.php';

$user = auth_require();
$pdo  = db();
$ctx  = op_contexto_sucursal($user, $pdo);
$suc  = (int) $ctx['id'];

$q      = trim((string) ($_GET['q'] ?? ''));
$where  = ['1 = 1'];
$params = [':suc' => $suc];

if ($q !== '') {
    $where[]         = '(p.sku LIKE :q1 OR p.nombre LIKE :q2)';
    $params[':q1']   = '%' . $q . '%';
    $params[':q2']   = '%' . $q . '%';
}

$sql = 'SELECT p.id, p.sku, p.nombre, p.precio, p.stock_minimo, p.activo,
               COALESCE(i.stock, 0) AS stock,
               (SELECT ISNULL(SUM(i2.stock), 0)
                  FROM inventario i2 WHERE i2.producto_id = p.id) AS stock_total
          FROM productos p
          LEFT JOIN inventario i
                 ON i.producto_id = p.id AND i.sucursal_id = :suc
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY p.activo DESC, p.sku';

$st = $pdo->prepare($sql);
$st->execute($params);
$productos = $st->fetchAll();
?>
<!doctype html>
<html lang="es">
<head>
    <script src="assets/tema.js"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#050a12">
    <title>Productos · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <?php render_barra($user, 'productos'); ?>

    <main class="panel panel-ancho">
        <section class="bienvenida">
            <p class="eyebrow">Productos</p>
            <h1>Catálogo de productos</h1>
            <p>
                Contexto: <strong><?= e($ctx['nombre']) ?></strong>.
                El catálogo es global; el stock se consulta por sucursal.
            </p>
        </section>

        <?php render_flash_op(); ?>

        <form method="get" action="productos.php" class="fila filtro-reportes">
            <label for="q">Buscar por SKU o nombre</label>
            <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Ej. HAR">
            <button type="submit" class="btn-mini">Buscar</button>
            <?php if ($ctx['es_admin']): ?>
                <label for="sucursal">Sucursal</label>
                <select id="sucursal" name="sucursal_id" onchange="this.form.submit()">
                    <?php foreach ($ctx['sucursales'] as $s): ?>
                        <option value="<?= (int) $s['id'] ?>"
                            <?= $suc === (int) $s['id'] ? 'selected' : '' ?>>
                            <?= e((string) $s['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="btn-mini">Aplicar</button></noscript>
            <?php endif; ?>
        </form>

        <?php if ($ctx['es_admin']): ?>
            <p class="acciones">
                <a class="boton" href="producto_form.php">Nuevo producto</a>
                <a class="btn-mini btn-secundario" href="ingresos.php">Registrar ingreso</a>
            </p>
        <?php endif; ?>

        <section class="detalle seccion">
            <div class="tabla-scroll">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Producto</th>
                            <th>Precio</th>
                            <th>Mínimo</th>
                            <th>Stock sucursal</th>
                            <th>Stock total</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$productos): ?>
                        <tr><td colspan="8" class="vacio">No hay productos para ese filtro.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($productos as $p): ?>
                        <?php $critico = (int) $p['stock'] <= (int) $p['stock_minimo']; ?>
                        <tr<?= $critico ? ' class="critico"' : '' ?>>
                            <td><strong><?= e((string) $p['sku']) ?></strong></td>
                            <td><?= e((string) $p['nombre']) ?></td>
                            <td><?= pesos((int) round((float) $p['precio'] * 100)) ?></td>
                            <td><?= (int) $p['stock_minimo'] ?></td>
                            <td<?= $critico ? ' class="stock-critico"' : '' ?>>
                                <?= (int) $p['stock'] ?>
                            </td>
                            <td><?= (int) $p['stock_total'] ?></td>
                            <td><?= (int) $p['activo'] === 1 ? 'Activo' : 'De baja' ?></td>
                            <td>
                                <div class="acciones-tabla">
                                    <a class="btn-mini" href="producto_form.php?id=<?= (int) $p['id'] ?>">
                                        <?= $ctx['es_admin'] ? 'Editar' : 'Ver' ?>
                                    </a>
                                    <?php if ($ctx['es_admin']): ?>
                                        <form method="post" action="producto_estado.php">
                                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                            <input type="hidden" name="accion"
                                                   value="<?= (int) $p['activo'] === 1 ? 'baja' : 'alta' ?>">
                                            <button class="btn-mini btn-secundario" type="submit">
                                                <?= (int) $p['activo'] === 1 ? 'Dar de baja' : 'Reactivar' ?>
                                            </button>
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
            La baja es lógica: conserva el historial de ventas, lotes y movimientos.
        </p>
    </main>
</body>
</html>
