<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/reportes_lib.php';

$user = auth_require();
$pdo  = db();

/* RF08 / RF11: tabla independiente de compradores. El operador ve sólo las
   transacciones de su sucursal; ADMIN puede elegir sede en Reportes. */
$perfil = informes_contexto($user, $pdo);
$sucId  = (int) $perfil['sucursal_id'];
$fV = $fD = '';
$pSuc = [];
if ($sucId > 0) {
    $fV = ' AND v.sucursal_id = :s1';
    $fD = ' AND d.sucursal_id = :s2';
    $pSuc = [':s1' => $sucId, ':s2' => $sucId];
}

$st = $pdo->prepare(
    'SELECT c.*,
            (SELECT COUNT(*) FROM ventas v
              WHERE v.comprador_id = c.id AND v.estado = \'COMPLETADA\'' . $fV . ') AS ventas,
            (SELECT COUNT(*) FROM despachos_pendientes d
              WHERE d.comprador_id = c.id' . $fD . ') AS despachos
       FROM compradores c
      ORDER BY c.nombre'
);
$st->execute($pSuc);
$compradores = $st->fetchAll();
$sucursalId = $sucId;
?>
<!doctype html>
<html lang="es">
<head>
    <script src="assets/tema.js"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>Compradores · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <?php render_barra($user, 'compradores'); ?>

    <main class="panel panel-ancho">
        <section class="bienvenida">
            <p class="eyebrow">Compradores</p>
            <h1>Compradores</h1>
            <p>Registro de compradores para ventas y despachos.</p>
        </section>

        <?php render_flash_op(); ?>

        <p class="acciones">
            <a class="boton" href="comprador_nuevo.php?volver=compradores.php&sucursal_id=<?= (int) $sucursalId ?>">
                Nuevo comprador
            </a>
        </p>

        <section class="detalle seccion">
            <div class="tabla-scroll">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>RUT</th><th>Nombre</th><th>Teléfono</th>
                            <th>Correo</th><th>Dirección</th>
                            <th>Ventas</th><th>Despachos</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$compradores): ?>
                        <tr><td colspan="7" class="vacio">Sin compradores registrados.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($compradores as $c): ?>
                        <tr>
                            <td><?= e((string) ($c['rut'] ?? '—')) ?></td>
                            <td><strong><?= e((string) $c['nombre']) ?></strong></td>
                            <td><?= e((string) ($c['telefono'] ?? '—')) ?></td>
                            <td><?= e((string) ($c['email'] ?? '—')) ?></td>
                            <td><?= e((string) ($c['direccion'] ?? '—')) ?></td>
                            <td><?= (int) $c['ventas'] ?></td>
                            <td><?= (int) $c['despachos'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
