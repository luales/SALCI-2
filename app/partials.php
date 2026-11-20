<?php
declare(strict_types=1);

/* ---------------------------------------------------------------------
   Utilidades de presentación compartidas por las pantallas internas.
   --------------------------------------------------------------------- */

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function pesos(int $centavos): string
{
    return '$ ' . number_format($centavos / 100, 2, ',', '.');
}

/**
 * Barra superior con navegación. $activo resalta la sección actual.
 */
function render_barra(array $user, string $activo = ''): void
{
    $enlace = static function (string $href, string $texto, string $clave) use ($activo): string {
        $clase = 'menu-link' . ($activo === $clave ? ' activo' : '');

        return '<a class="' . $clase . '" href="' . e($href) . '">' . e($texto) . '</a>';
    };
    ?>
    <header class="barra">
        <div class="barra-izq">
            <span class="logo">S</span>
            <strong><?= e(APP_NAME) ?></strong>
            <nav class="menu" aria-label="Navegación principal">
                <?= $enlace('index.php', 'Panel', 'panel') ?>
                <?= $enlace('pos.php', 'Punto de venta', 'pos') ?>
                <?= $enlace('reportes.php', 'Reportes', 'reportes') ?>
                <?= $enlace('productos.php', 'Productos', 'productos') ?>
                <?= $enlace('ingresos.php', 'Ingresos', 'ingresos') ?>
                <?= $enlace('proveedores.php', 'Proveedores', 'proveedores') ?>
                <?= $enlace('compradores.php', 'Compradores', 'compradores') ?>
                <?= $enlace('despachos.php', 'Despachos', 'despachos') ?>
                <?= $enlace('transferencias.php', 'Transferencias', 'transferencias') ?>
                <?php if (($user['rol'] ?? '') === 'ADMIN'): ?>
                    <?= $enlace('ventas.php', 'Ventas', 'ventas') ?>
                    <?= $enlace('usuarios.php', 'Usuarios', 'usuarios') ?>
                    <?= $enlace('sucursales.php', 'Sucursales', 'sucursales') ?>
                <?php endif; ?>
            </nav>
        </div>
        <div class="barra-der">
            <button type="button" class="tema-toggle" data-tema-toggle
                    aria-pressed="false" aria-label="Cambiar tema">◐</button>
            <span class="chip"><?= e((string) $user['rol']) ?></span>
            <span><?= e((string) $user['username']) ?></span>
            <a class="salir" href="logout.php">Cerrar sesión</a>
        </div>
    </header>
    <?php
}

/** Avisos de operaciones CRUD (requiere operaciones.php en la página). */
function render_flash_op(): void
{
    if (!function_exists('op_flash_take')) {
        return;
    }

    $flash = op_flash_take();
    if ($flash === null) {
        return;
    }

    $msg = (string) $flash['msg'];

    /* RNF06: incluso los errores ya controlados indican causa y acción. */
    if ($flash['tipo'] === 'error' && stripos($msg, 'acción') === false) {
        $msg .= ' — Acción correctiva: revisa los datos e inténtalo de nuevo; '
            . 'si persiste, informa al administrador.';
    }

    echo '<div class="flash ' . e($flash['tipo']) . '">' . e($msg) . '</div>';
}
