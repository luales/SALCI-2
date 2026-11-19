<?php
declare(strict_types=1);

require_once __DIR__ . '/operaciones.php';

$user = auth_require();
$pdo  = db();
op_csrf_or_fail('transferencias.php');

$producto = (int) ($_POST['producto_id'] ?? 0);
$origen   = (int) ($_POST['sucursal_origen'] ?? 0);
$destino  = (int) ($_POST['sucursal_destino'] ?? 0);
$cantidad = trim((string) ($_POST['cantidad'] ?? ''));

if (!op_admin($user)) {
    $origen = (int) $user['sucursal_id'];
}

function op_guid(): string
{
    $hex = bin2hex(random_bytes(16));

    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4'
        . substr($hex, 13, 3) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

try {
    if ($producto <= 0 || $origen <= 0 || $destino <= 0
        || !ctype_digit($cantidad) || (int) $cantidad <= 0) {
        throw new RuntimeException('Revisa los datos: producto, sucursales y cantidad son obligatorios.');
    }

    if ($origen === $destino) {
        throw new RuntimeException('Origen y destino deben ser sucursales distintas.');
    }

    foreach ([[$producto, 'productos', 'activo', 'producto'],
              [$origen, 'sucursales', 'activa', 'sucursal de origen'],
              [$destino, 'sucursales', 'activa', 'sucursal de destino']] as [$id, $tabla, $col, $etiqueta]) {
        $st = $pdo->prepare("SELECT 1 FROM $tabla WHERE id = :id AND $col = 1");
        $st->execute([':id' => $id]);
        if ($st->fetchColumn() === false) {
            throw new RuntimeException('La ' . $etiqueta . ' no existe o no está activa.');
        }
    }

    $grupo = op_guid();

    op_tx($pdo, static function () use (
        $pdo, $producto, $origen, $destino, $cantidad, $grupo, $user
    ): void {
        op_mover_stock(
            $pdo, $producto, $origen, -((int) $cantidad),
            'TRANSFERENCIA_SALIDA', $user, null, null, $grupo
        );

        op_asegurar_inventario($pdo, $producto, $destino, $user);
        op_mover_stock(
            $pdo, $producto, $destino, (int) $cantidad,
            'TRANSFERENCIA_INGRESO', $user, null, null, $grupo
        );
    });

    op_flash('ok', 'Transferencia completada. Grupo ' . substr($grupo, 0, 8)
        . ': dos asientos en el kardex.');
} catch (RuntimeException $e) {
    op_flash('error', $e->getMessage());
} catch (Throwable $e) {
    op_flash('error', 'No se pudo transferir: ' . (APP_DEBUG ? $e->getMessage() : 'error inesperado.'));
}

op_redir('transferencias.php');
