<?php
declare(strict_types=1);

require_once __DIR__ . '/operaciones.php';
require_once __DIR__ . '/partials.php';

$user = auth_require();
$pdo  = db();
$esAdmin = op_admin($user);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0 && !$esAdmin) {
    http_response_code(403);
    exit('Solo el administrador puede crear productos.');
}

$producto = null;
if ($id > 0) {
    $st = $pdo->prepare('SELECT id, sku, nombre, precio, stock_minimo, activo
                           FROM productos WHERE id = :id');
    $st->execute([':id' => $id]);
    $producto = $st->fetch();

    if (!$producto) {
        op_flash('error', 'El producto no existe.');
        op_redir('productos.php');
    }
}

$nuevo = $producto === null;
$valores = [
    'sku'          => $producto['sku'] ?? '',
    'nombre'       => $producto['nombre'] ?? '',
    'precio'       => isset($producto['precio']) ? number_format((float) $producto['precio'], 2, '.', '') : '',
    'stock_minimo' => $producto['stock_minimo'] ?? '',
];
?>
<!doctype html>
<html lang="es">
<head>
    <script src="assets/tema.js"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#050a12">
    <title><?= $nuevo ? 'Nuevo producto' : 'Editar producto' ?> · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <?php render_barra($user, 'productos'); ?>

    <main class="panel">
        <section class="bienvenida">
            <p class="eyebrow">Producto</p>
            <h1><?= $nuevo ? 'Nuevo producto' : 'Editar producto' ?></h1>
            <p>
                <?php if (!$esAdmin): ?>
                    Modo consulta: tu rol es <strong><?= e((string) $user['rol']) ?></strong>.
                <?php elseif ($nuevo): ?>
                    Se creará con stock 0 en todas las sucursales activas; carga las
                    existencias desde <a href="ingresos.php">Ingresos</a>.
                <?php else: ?>
                    El SKU es único en todo el catálogo.
                <?php endif; ?>
            </p>
        </section>

        <?php render_flash_op(); ?>

        <form method="post" action="producto_guardar.php" class="formulario">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= $id ?>">

            <label for="sku">SKU</label>
            <input id="sku" name="sku" type="text" maxlength="30" required
                   value="<?= e((string) $valores['sku']) ?>" <?= $esAdmin ? '' : 'readonly' ?>>

            <label for="nombre">Nombre</label>
            <input id="nombre" name="nombre" type="text" maxlength="150" required
                   value="<?= e((string) $valores['nombre']) ?>" <?= $esAdmin ? '' : 'readonly' ?>>

            <label for="precio">Precio de lista</label>
            <input id="precio" name="precio" type="number" min="0" step="0.01" required
                   value="<?= e((string) $valores['precio']) ?>" <?= $esAdmin ? '' : 'readonly' ?>>

            <label for="stock_minimo">Stock mínimo (umbral de alerta)</label>
            <input id="stock_minimo" name="stock_minimo" type="number" min="0" step="1" required
                   value="<?= e((string) $valores['stock_minimo']) ?>" <?= $esAdmin ? '' : 'readonly' ?>>

            <?php if ($esAdmin): ?>
                <p class="acciones">
                    <button class="boton" type="submit">Guardar</button>
                    <a class="btn-mini btn-secundario" href="productos.php">Cancelar</a>
                </p>
            <?php else: ?>
                <p class="acciones">
                    <a class="btn-mini btn-secundario" href="productos.php">Volver</a>
                </p>
            <?php endif; ?>
        </form>
    </main>
</body>
</html>
