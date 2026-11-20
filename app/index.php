<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/reportes_lib.php';

$user    = auth_require();
$pdo     = db();
$perfil  = informes_contexto($user, $pdo);
$sucId   = (int) $perfil['sucursal_id'];
$saludo  = rol_saludo($user['rol']);
$claims  = auth_jwt_validar($_SESSION['access_token'] ?? null);
$tokenExp = $claims !== null ? date('d/m/Y H:i', (int) $claims['exp']) : '—';

/* Datos reales de la conexión, para demostrar que se llegó a SQL Server */
$info    = $pdo->query("SELECT DB_NAME() AS base, @@VERSION AS version")->fetch();
$version = trim(strtok((string) $info['version'], "\n"));
$servidor = preg_replace('/^sqlsrv:Server=([^;]+).*$/i', '$1', DB_DSN) ?: DB_DSN;

/* RF03: "productos totales" se segmenta por la sucursal del operador.
   ADMIN con "todas" (0) ve el catálogo global; el operador ve sólo los
   productos con existencia en su sede. */
if ($sucId > 0) {
    $st = $pdo->prepare(
        'SELECT COUNT(DISTINCT i.producto_id)
           FROM inventario i
           JOIN productos p ON p.id = i.producto_id
          WHERE p.activo = 1 AND i.sucursal_id = :s0'
    );
    $st->execute([':s0' => $sucId]);
    $totales = (int) $st->fetchColumn();
} else {
    $totales = (int) $pdo->query(
        'SELECT COUNT(*) FROM productos WHERE activo = 1'
    )->fetchColumn();
}

$sqlCriticos = "SELECT COUNT(*)
       FROM inventario i
       JOIN productos p ON p.id = i.producto_id
      WHERE p.activo = 1
        AND i.stock <= p.stock_minimo";
$parCriticos = [];
if ($sucId > 0) {
    $sqlCriticos .= ' AND i.sucursal_id = :s1';
    $parCriticos[':s1'] = $sucId;
}
$cr = $pdo->prepare($sqlCriticos);
$cr->execute($parCriticos);
$criticos = (int) $cr->fetchColumn();

$sqlPendientes = "SELECT COUNT(*)
       FROM despachos_pendientes
      WHERE estado IN ('PENDIENTE','EN_RUTA')";
$parPendientes = [];
if ($sucId > 0) {
    $sqlPendientes .= ' AND sucursal_id = :s2';
    $parPendientes[':s2'] = $sucId;
}
$pe = $pdo->prepare($sqlPendientes);
$pe->execute($parPendientes);
$pendientes = (int) $pe->fetchColumn();

$top5 = top5_informe($pdo, $sucId);

?>
<!doctype html>
<html lang="es">
<head>
    <script src="assets/tema.js"></script>
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
                <a class="btn-mini btn-secundario" href="reportes.php">Ver reportes</a>
                <?php if ($user['rol'] === 'ADMIN'): ?>
                    <a class="btn-mini btn-secundario" href="ventas.php">Ver ventas</a>
                <?php endif; ?>
            </p>
        </section>

        <section class="indicadores">
            <article class="indicador">
                <span class="valor"><?= $totales ?></span>
                <span class="etiqueta">Productos totales</span>
            </article>
            <article class="indicador indicador-critico">
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
                <dd><?= e((string) $servidor) ?></dd>
                <dt>Base de datos</dt>
                <dd><?= e((string) $info['base']) ?></dd>
                <dt>Driver</dt>
                <dd><?= e((string) db()->getAttribute(PDO::ATTR_DRIVER_NAME)) ?></dd>
                <dt>Versión</dt>
                <dd class="mono"><?= e($version) ?></dd>
                <dt>Token RF05 (JWT HS256)</dt>
                <dd class="mono">firmado · expira <?= e($tokenExp) ?></dd>
                <dt>RNF01 · Tiempo de respuesta</dt>
                <dd><?= number_format(salci_response_ms(), 2, ',', '.') ?> ms ·
                    cabecera <code>X-Response-Time-Ms</code> (objetivo &lt; 500 ms)</dd>
                <dt>RNF04 · Monitoreo</dt>
                <dd><a href="health.php">health.php</a> ·
                    <code>GET /health</code> · disponibilidad objetivo 99,9%</dd>
            </dl>
            <p class="nota">
                Si ves esta pantalla, PHP está hablando con SQL Server y la
                sesión quedó autenticada contra la tabla <code>usuarios</code>.
                RNF02 usa las claves foráneas nativas de SQL Server (equivalente
                a <code>PRAGMA foreign_keys=ON</code>), RNF05 apoya las búsquedas
                en índices B-Tree de <code>sku</code>, <code>sucursal_id</code> y
                <code>username</code>, y RNF06 informa causa + acción en cada error.
            </p>
        </section>

        <section class="detalle">
            <h2>Top 5 de rotaci&oacute;n</h2>
            <div class="tabla-scroll">
                <table class="tabla">
                    <thead><tr><th>#</th><th>sku</th><th>producto</th><th>unidades</th><th>monto</th></tr></thead>
                    <tbody>
                    <?php foreach ($top5 as $i => $f): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= e((string) $f['sku']) ?></td>
                            <td><?= e((string) $f['producto']) ?></td>
                            <td><?= (int) $f['unidades'] ?></td>
                            <td><?= pesos((int) round((float) $f['monto'] * 100)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="nota">Unidades vendidas en ventas completadas.</p>
        </section>
    </main>
</body>
</html>
