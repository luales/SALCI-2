<?php
declare(strict_types=1);

require_once __DIR__ . '/operaciones.php';

$user = auth_require();
$pdo  = db();
op_csrf_or_fail('despachos.php');

$producto  = (int) ($_POST['producto_id'] ?? 0);
$sucursal  = (int) ($_POST['sucursal_id'] ?? 0);
$comprador = (int) ($_POST['comprador_id'] ?? 0);
$destino   = op_texto($_POST['destino'] ?? null, 200);
$cantidad  = trim((string) ($_POST['cantidad'] ?? ''));
$venta     = (int) ($_POST['venta_id'] ?? 0);

if (!op_admin($user)) {
    $sucursal = (int) $user['sucursal_id'];
}

try {
    if ($producto <= 0 || $comprador <= 0 || $destino === ''
        || !ctype_digit($cantidad) || (int) $cantidad <= 0) {
        throw new RuntimeException('Revisa los datos: producto, comprador, destino y cantidad son obligatorios.');
    }

    $st = $pdo->prepare('SELECT 1 FROM productos WHERE id = :id AND activo = 1');
    $st->execute([':id' => $producto]);
    if ($st->fetchColumn() === false) {
        throw new RuntimeException('El producto no existe o está de baja.');
    }

    $st = $pdo->prepare('SELECT 1 FROM sucursales WHERE id = :id AND activa = 1');
    $st->execute([':id' => $sucursal]);
    if ($st->fetchColumn() === false) {
        throw new RuntimeException('La sucursal no existe o está deshabilitada.');
    }

    $st = $pdo->prepare('SELECT 1 FROM compradores WHERE id = :id AND activo = 1');
    $st->execute([':id' => $comprador]);
    if ($st->fetchColumn() === false) {
        throw new RuntimeException('El comprador no existe o está de baja.');
    }

    if ($venta > 0) {
        $st = $pdo->prepare(
            'SELECT v.sucursal_id,
                    ISNULL((SELECT SUM(d.cantidad)
                              FROM venta_detalle d
                             WHERE d.venta_id = v.id
                               AND d.producto_id = :producto), 0) AS vendidos
               FROM ventas v
              WHERE v.id = :id AND v.estado = \'COMPLETADA\''
        );
        $st->execute([':id' => $venta, ':producto' => $producto]);
        $ventaFila = $st->fetch();

        if (!$ventaFila) {
            throw new RuntimeException('El folio de venta no existe o no está completada.');
        }

        if ((int) $ventaFila['sucursal_id'] !== $sucursal) {
            throw new RuntimeException('La venta pertenece a otra sucursal.');
        }

        if ((int) $ventaFila['vendidos'] < (int) $cantidad) {
            throw new RuntimeException(
                'La venta indicada no contiene ese producto en la cantidad solicitada.'
            );
        }
    }

    op_tx($pdo, static function () use (
        $pdo, $producto, $sucursal, $comprador, $destino, $cantidad, $venta, $user
    ): void {
        $aud = op_auditoria($user);
        $st = $pdo->prepare(
            'INSERT INTO despachos_pendientes
                (producto_id, sucursal_id, comprador_id, venta_id, destino,
                 cantidad, estado, created_by)
             VALUES
                (:producto, :sucursal, :comprador, :venta, :destino,
                 :cantidad, \'PENDIENTE\', :by)'
        );
        $st->execute([
            ':producto'  => $producto,
            ':sucursal'  => $sucursal,
            ':comprador' => $comprador,
            ':venta'     => $venta > 0 ? $venta : null,
            ':destino'   => $destino,
            ':cantidad'  => (int) $cantidad,
            ':by'        => $aud['by'],
        ]);
    });

    op_flash('ok', 'Despacho registrado en estado PENDIENTE.');
} catch (RuntimeException $e) {
    op_flash('error', $e->getMessage());
} catch (Throwable $e) {
    op_flash('error', 'No se pudo registrar: ' . (APP_DEBUG ? $e->getMessage() : 'error inesperado.'));
}

op_redir('despachos.php');
