<?php
declare(strict_types=1);

require_once __DIR__ . '/operaciones.php';
require_once __DIR__ . '/partials.php';

$user = auth_require();
if (!op_admin($user)) {
    http_response_code(403);
    exit('Solo el administrador puede gestionar sucursales.');
}

$pdo = db();
$editar = (int) ($_GET['editar'] ?? 0);
$sucursal = null;
if ($editar > 0) {
    $st = $pdo->prepare('SELECT * FROM sucursales WHERE id = :id');
    $st->execute([':id' => $editar]);
    $sucursal = $st->fetch();
}

$sucursales = $pdo->query(
    'SELECT s.*,
            (SELECT COUNT(*) FROM usuarios u
              WHERE u.sucursal_id = s.id AND u.activo = 1) AS usuarios,
            ISNULL((SELECT SUM(i.stock) FROM inventario i
                     WHERE i.sucursal_id = s.id), 0) AS unidades,
            (SELECT COUNT(*) FROM transacciones t
              WHERE t.sucursal_id = s.id) AS movimientos
       FROM sucursales s
      ORDER BY s.activa DESC, s.nombre'
)->fetchAll();

$v = static fn(string $k): string => (string) ($sucursal[$k] ?? '');
?>
<!doctype html>
<html lang="es">
<head>
    <script src="assets/tema.js"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#050a12">
    <title>Sucursales · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <?php render_barra($user, 'sucursales'); ?>

    <main class="panel panel-ancho">
        <section class="bienvenida">
            <p class="eyebrow"></p>
            <h1>Sucursales físicas</h1>
            <p>No se puede eliminar una
               sucursal con usuarios asignados, existencias o movimientos.</p>
        </section>

        <?php render_flash_op(); ?>

        <section class="detalle seccion">
            <h2><?= $sucursal ? 'Editar sucursal' : 'Nueva sucursal' ?></h2>
            <form method="post" action="sucursal_guardar.php" class="formulario">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int) ($sucursal['id'] ?? 0) ?>">

                <label for="nombre">Nombre</label>
                <input id="nombre" name="nombre" type="text" maxlength="120" required
                       value="<?= e($v('nombre')) ?>">

                <label for="direccion">Dirección</label>
                <input id="direccion" name="direccion" type="text" maxlength="200"
                       value="<?= e($v('direccion')) ?>">

                <p class="acciones">
                    <button class="boton" type="submit">Guardar sucursal</button>
                    <?php if ($sucursal): ?>
                        <a class="btn-mini btn-secundario" href="sucursales.php">Cancelar</a>
                    <?php endif; ?>
                </p>
            </form>
        </section>

        <section class="detalle seccion">
            <div class="tabla-scroll">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>#</th><th>Nombre</th><th>Dirección</th><th>Usuarios</th>
                            <th>Unidades</th><th>Movimientos</th><th>Estado</th><th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sucursales as $s): ?>
                        <?php $puedeBorrar = (int) $s['usuarios'] === 0
                            && (int) $s['unidades'] === 0
                            && (int) $s['movimientos'] === 0; ?>
                        <tr>
                            <td><?= (int) $s['id'] ?></td>
                            <td><strong><?= e((string) $s['nombre']) ?></strong></td>
                            <td><?= e((string) ($s['direccion'] ?: '—')) ?></td>
                            <td><?= (int) $s['usuarios'] ?></td>
                            <td><?= (int) $s['unidades'] ?></td>
                            <td><?= (int) $s['movimientos'] ?></td>
                            <td><?= (int) $s['activa'] === 1 ? 'Activa' : 'Deshabilitada' ?></td>
                            <td>
                                <div class="acciones-tabla">
                                    <a class="btn-mini" href="sucursales.php?editar=<?= (int) $s['id'] ?>">Editar</a>
                                    <form method="post" action="sucursal_estado.php">
                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                        <input type="hidden" name="accion"
                                               value="<?= (int) $s['activa'] === 1 ? 'deshabilitar' : 'activar' ?>">
                                        <button class="btn-mini btn-secundario" type="submit">
                                            <?= (int) $s['activa'] === 1 ? 'Deshabilitar' : 'Activar' ?>
                                        </button>
                                    </form>
                                    <?php if ($puedeBorrar): ?>
                                        <form method="post" action="sucursal_estado.php">
                                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                            <input type="hidden" name="accion" value="eliminar">
                                            <button class="btn-mini btn-secundario" type="submit">Eliminar</button>
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
