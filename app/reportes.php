<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/reportes_lib.php';

$user = auth_require();
$pdo  = db();
$ctx  = informes_contexto($user, $pdo);
$suc  = (int) $ctx['sucursal_id'];

$indicadores = indicadores_informe($pdo, $suc);
$top5        = top5_informe($pdo, $suc);

[$hStock,       $stock]       = csv_informe($pdo, 'stock', $suc);
[$hAlertas,     $alertas]     = csv_informe($pdo, 'alertas', $suc);
[$hProveedores, $proveedores] = csv_informe($pdo, 'proveedores', $suc);
[$hVentas,      $ventas]      = csv_informe($pdo, 'ventas', $suc);
[$hKardex,      $kardex]      = csv_informe($pdo, 'kardex', $suc);

/** Tabla reutilizable para todos los informes. */
function render_tabla(array $cabeceras, array $filas, int $limite = 0): void
{
    if ($limite > 0) {
        $filas = array_slice($filas, 0, $limite);
    }

    echo '<div class="tabla-scroll"><table class="tabla"><thead><tr>';
    foreach ($cabeceras as $c) {
        echo '<th>' . e(ucwords(str_replace('_', ' ', (string) $c))) . '</th>';
    }
    echo '</tr></thead><tbody>';

    if (!$filas) {
        echo '<tr><td class="vacio" colspan="' . count($cabeceras) . '">Sin datos para este filtro.</td></tr>';
    }

    foreach ($filas as $fila) {
        $critico = in_array('CRITICO', $fila, true) ? ' class="critico"' : '';
        echo '<tr' . $critico . '>';
        foreach ($fila as $valor) {
            $clase = (string) $valor === 'CRITICO' ? ' class="stock-critico"' : '';
            echo '<td' . $clase . '>' . e((string) $valor) . '</td>';
        }
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

/** Enlace de descarga UTF-8 (RF14). */
function descarga_csv(string $tipo, int $sucursalId, string $texto): void
{
    $url = 'export_csv.php?tipo=' . $tipo
        . ($sucursalId > 0 ? '&sucursal_id=' . $sucursalId : '');

    echo '<a class="btn-mini btn-secundario" href="' . e($url) . '">'
        . e($texto) . '</a>';
}

$topFilas = [];
$posicion = 1;
foreach ($top5 as $t) {
    $topFilas[] = [
        $posicion++,
        (string) $t['sku'],
        (string) $t['producto'],
        (string) $t['unidades'],
        (string) $t['monto'],
    ];
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#050a12">
    <title>Reportes · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <?php render_barra($user, 'reportes'); ?>

    <main class="panel panel-ancho reportes">
        <section class="bienvenida">
            <p class="eyebrow">RF03 · RF10 · RF11 · RF14</p>
            <h1>Reportes y stock en vivo</h1>
            <p>
                Indicadores del dashboard, catálogo con semáforo de stock crítico
                y exportación CSV en UTF-8. Contexto:
                <strong><?= e($ctx['sucursal_nombre']) ?></strong>.
            </p>
        </section>

        <?php if ($ctx['es_admin']): ?>
            <form method="get" action="reportes.php" class="fila filtro-reportes">
                <label for="sucursal">Filtrar por sucursal</label>
                <select id="sucursal" name="sucursal_id" onchange="this.form.submit()">
                    <option value="0" <?= $suc === 0 ? 'selected' : '' ?>>Todas las sucursales</option>
                    <?php foreach ($ctx['sucursales'] as $s): ?>
                        <option value="<?= (int) $s['id'] ?>"
                            <?= $suc === (int) $s['id'] ? 'selected' : '' ?>>
                            <?= e((string) $s['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="btn-mini">Aplicar</button></noscript>
            </form>
        <?php endif; ?>

        <section class="indicadores">
            <article class="indicador">
                <span class="valor"><?= (int) $indicadores['productos'] ?></span>
                <span class="etiqueta">Productos en catálogo</span>
            </article>
            <article class="indicador indicador-critico">
                <span class="valor"><?= (int) $indicadores['criticos'] ?></span>
                <span class="etiqueta">Ítems en stock crítico</span>
            </article>
            <article class="indicador">
                <span class="valor"><?= (int) $indicadores['unidades'] ?></span>
                <span class="etiqueta">Unidades en inventario</span>
            </article>
            <article class="indicador">
                <span class="valor"><?= (int) $indicadores['despachos'] ?></span>
                <span class="etiqueta">Despachos pendientes</span>
            </article>
            <article class="indicador">
                <span class="valor"><?= pesos((int) round($indicadores['monto'] * 100)) ?></span>
                <span class="etiqueta">Ventas completadas</span>
            </article>
        </section>

        <div class="reportes-grid">
            <section class="detalle">
                <h2>Top 5 de rotación</h2>
                <?php render_tabla(['#', 'sku', 'producto', 'unidades', 'monto'], $topFilas); ?>
                <p class="nota">Unidades vendidas en ventas completadas.</p>
            </section>

            <section class="detalle">
                <h2>Alertas sin leer</h2>
                <?php render_tabla($hAlertas, $alertas, 10); ?>
                <p class="descargas">
                    <?php descarga_csv('alertas', $suc, 'Descargar alertas CSV'); ?>
                </p>
            </section>
        </div>

        <section class="detalle seccion">
            <h2>Stock en vivo</h2>
            <p class="nota">
                Las filas en rojo tienen saldo igual o inferior al mínimo (RF10).
                Esta es la consulta [PAUTA 2] del script <code>03_queries.sql</code>.
            </p>
            <?php render_tabla($hStock, $stock); ?>
            <p class="descargas">
                <?php descarga_csv('stock', $suc, 'Descargar stock CSV'); ?>
                <?php descarga_csv('ventas', $suc, 'Descargar ventas CSV'); ?>
            </p>
        </section>

        <details class="detalle seccion">
            <summary>Proveedores y lotes (relación 1:N) — [PAUTA 3]</summary>
            <?php render_tabla($hProveedores, $proveedores); ?>
            <p class="descargas">
                <?php descarga_csv('proveedores', $suc, 'Descargar proveedores CSV'); ?>
            </p>
        </details>

        <details class="detalle seccion">
            <summary>Ventas y productos (relación N:N) — [PAUTA 4]</summary>
            <?php render_tabla($hVentas, $ventas, 20); ?>
        </details>

        <details class="detalle seccion">
            <summary>Kardex de movimientos (RF13)</summary>
            <?php render_tabla($hKardex, $kardex, 20); ?>
            <p class="descargas">
                <?php descarga_csv('kardex', $suc, 'Descargar kardex CSV'); ?>
            </p>
        </details>

        <p class="nota">
            Los CSV se generan con separador <code>;</code>, BOM UTF-8 y respetan
            el filtro de sucursal del usuario (RF11, RF14).
        </p>
    </main>
</body>
</html>
