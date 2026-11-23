<?php
declare(strict_types=1);

require_once __DIR__ . '/operaciones.php';
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/reportes_lib.php';

$user = auth_require();
$pdo  = db();
$esAdmin = op_admin($user);

/* RF11: el operador sólo ve movimientos de su sucursal; ADMIN elige sede. */
$perfil = informes_contexto($user, $pdo);
$sucId  = (int) $perfil['sucursal_id'];
$fSuc1 = $fSuc2 = '';
$pSuc  = [];
if ($sucId > 0) {
    /* Dos marcadores distintos: PDO_SQLSRV no permite repetir :suc. */
    $fSuc1 = ' AND l.sucursal_id = :s1';
    $fSuc2 = ' AND l.sucursal_id = :s2';
    $pSuc  = [':s1' => $sucId, ':s2' => $sucId];
}

$editar = (int) ($_GET['editar'] ?? 0);
$proveedor = null;
if ($editar > 0) {
    $st = $pdo->prepare('SELECT * FROM proveedores WHERE id = :id');
    $st->execute([':id' => $editar]);
    $proveedor = $st->fetch();
}

$stProv = $pdo->prepare(
    'SELECT pr.*,
            (SELECT COUNT(*) FROM lotes l
              WHERE l.proveedor_id = pr.id AND l.anulado = 0' . $fSuc1 . ') AS lotes,
            ISNULL((SELECT SUM(l.cantidad) FROM lotes l
                     WHERE l.proveedor_id = pr.id AND l.anulado = 0' . $fSuc2 . '), 0) AS unidades
       FROM proveedores pr
      ORDER BY pr.activo DESC, pr.nombre'
);
$stProv->execute($pSuc);
$proveedores = $stProv->fetchAll();

$v = static fn(string $k): string => (string) ($proveedor[$k] ?? '');
?>
<!doctype html>
<html lang="es">
<head>
    <script src="assets/tema.js"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#050a12">
    <title>Proveedores · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <?php render_barra($user, 'proveedores'); ?>

    <main class="panel panel-ancho">
        <section class="bienvenida">
            <p class="eyebrow">Proveedores</p>
            <h1>Proveedores</h1>
            <p>Cada proveedor puede tener muchos lotes. Los datos de contraparte
               viven aquí y no se repiten en el kardex.</p>
        </section>

        <?php render_flash_op(); ?>

        <?php if ($esAdmin): ?>
            <section class="detalle seccion">
                <h2><?= $proveedor ? 'Editar proveedor' : 'Nuevo proveedor' ?></h2>
                <form method="post" action="proveedor_guardar.php" class="formulario">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int) ($proveedor['id'] ?? 0) ?>">

                    <label for="rut">RUT (opcional, único)</label>
                    <input id="rut" name="rut" type="text" maxlength="12"
                           value="<?= e($v('rut')) ?>" placeholder="12.345.678-9">

                    <label for="nombre">Nombre</label>
                    <input id="nombre" name="nombre" type="text" maxlength="150" required
                           value="<?= e($v('nombre')) ?>">

                    <label for="telefono">Teléfono</label>
                    <input id="telefono" name="telefono" type="text" maxlength="30"
                           value="<?= e($v('telefono')) ?>">

                    <label for="email">Correo electrónico</label>
                    <input id="email" name="email" type="email" maxlength="120"
                           value="<?= e($v('email')) ?>">

                    <p class="acciones">
                        <button class="boton" type="submit">Guardar</button>
                        <?php if ($proveedor): ?>
                            <a class="btn-mini btn-secundario" href="proveedores.php">Cancelar</a>
                        <?php endif; ?>
                    </p>
                </form>
            </section>
        <?php endif; ?>

        <section class="detalle seccion">
            <div class="tabla-scroll">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>RUT</th><th>Nombre</th><th>Teléfono</th><th>Correo</th>
                            <th>Lotes</th><th>Unidades</th><th>Estado</th><th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$proveedores): ?>
                        <tr><td colspan="8" class="vacio">Sin proveedores registrados.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($proveedores as $pr): ?>
                        <tr>
                            <td><?= e((string) ($pr['rut'] ?? '—')) ?></td>
                            <td><strong><?= e((string) $pr['nombre']) ?></strong></td>
                            <td><?= e((string) ($pr['telefono'] ?? '—')) ?></td>
                            <td><?= e((string) ($pr['email'] ?? '—')) ?></td>
                            <td><?= (int) $pr['lotes'] ?></td>
                            <td><?= (int) $pr['unidades'] ?></td>
                            <td><?= (int) $pr['activo'] === 1 ? 'Activo' : 'De baja' ?></td>
                            <td>
                                <div class="acciones-tabla">
                                    <a class="btn-mini" href="proveedores.php?editar=<?= (int) $pr['id'] ?>">
                                        <?= $esAdmin ? 'Editar' : 'Ver' ?>
                                    </a>
                                    <?php if ($esAdmin): ?>
                                        <form method="post" action="proveedor_estado.php">
                                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="id" value="<?= (int) $pr['id'] ?>">
                                            <input type="hidden" name="accion"
                                                   value="<?= (int) $pr['activo'] === 1 ? 'baja' : 'alta' ?>">
                                            <button class="btn-mini btn-secundario" type="submit">
                                                <?= (int) $pr['activo'] === 1 ? 'Dar de baja' : 'Reactivar' ?>
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
    </main>
</body>
</html>
