<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/partials.php';

$user    = auth_require();
$saludo  = rol_saludo($user['rol']);

/* Datos reales de la conexión, para demostrar que se llegó a SQL Server */
$info    = db()->query("SELECT DB_NAME() AS base, @@VERSION AS version")->fetch();
$version = trim(strtok((string) $info['version'], "\n"));

$criticos = (int) db()->query(
    "SELECT COUNT(*)
       FROM inventario i
       JOIN productos p ON p.id = i.producto_id
      WHERE i.stock <= p.stock_minimo"
)->fetchColumn();

$pendientes = (int) db()->query(
    "SELECT COUNT(*)
       FROM despachos_pendientes
      WHERE estado IN ('PENDIENTE','EN_RUTA')"
)->fetchColumn();

?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#050a12">
    <title>Panel · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <?php render_barra($user, 'panel'); ?>

    <main class="panel">
        <section class="bienvenida">
            <p class="eyebrow">Panel principal</p>
            <h1><?= e($saludo) ?></h1>
            <?php
            $contexto = $user['sucursal'] !== null
                ? 'en la sucursal <strong>' . e((string) $user['sucursal']) . '</strong>'
                : 'con acceso global';
            ?>
            <p>
                Sesión iniciada como <strong><?= e($user['username']) ?></strong> <?= $contexto ?>.
            </p>
            <p class="acciones">
                <a class="boton" href="pos.php">Abrir punto de venta</a>
            </p>
        </section>

        <section class="indicadores">
            <article class="indicador">
                <span class="valor"><?= $criticos ?></span>
                <span class="etiqueta">Productos en stock crítico</span>
            </article>
            <article class="indicador">
                <span class="valor"><?= $pendientes ?></span>
                <span class="etiqueta">Despachos pendientes</span>
            </article>
            <article class="indicador">
                <span class="valor"><?= e((string) $info['base']) ?></span>
                <span class="etiqueta">Base de datos conectada</span>
            </article>
        </section>

        <section class="detalle">
            <h2>Estado de la conexión</h2>
            <dl>
                <dt>Servidor</dt>
                <dd>.\SQLEXPRESS</dd>
                <dt>Base de datos</dt>
                <dd><?= e((string) $info['base']) ?></dd>
                <dt>Driver</dt>
                <dd><?= e((string) db()->getAttribute(PDO::ATTR_DRIVER_NAME)) ?></dd>
                <dt>Versión</dt>
                <dd class="mono"><?= e($version) ?></dd>
            </dl>
            <p class="nota">
                Si ves esta pantalla, PHP está hablando con SQL Server y la
                sesión quedó autenticada contra la tabla <code>usuarios</code>.
            </p>
        </section>
    </main>
</body>
</html>
