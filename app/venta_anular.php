<?php
declare(strict_types=1);

require_once __DIR__ . '/operaciones.php';

$user = auth_require();
if (!op_admin($user)) {
    http_response_code(403);
    exit('Solo el administrador puede anular ventas.');
}

op_csrf_or_fail('ventas.php');

$pdo = db();
$id  = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    op_flash('error', 'Solicitud inválida.');
    op_redir('ventas.php');
}

try {
    op_tx($pdo, static function () use ($pdo, $id, $user): void {
        $st = $pdo->prepare('SELECT sucursal_id, estado FROM ventas WHERE id = :id');
        $st->execute([':id' => $id]);
        $venta = $st->fetch();

        if (!$venta) {
            throw new RuntimeException('La venta no existe.');
        }
        if ($venta['estado'] !== 'COMPLETADA') {
            throw new RuntimeException('La venta ya está anulada.');
        }

        $sucursal = (int) $venta['sucursal_id'];
        $lineas = $pdo->prepare(
            'SELECT producto_id, cantidad
               FROM venta_detalle WHERE venta_id = :id
              ORDER BY producto_id'
        );
        $lineas->execute([':id' => $id]);
        $items = $lineas->fetchAll();

        if (!$items) {
            throw new RuntimeException('La venta no tiene detalle para reversar.');
        }

        foreach ($items as $item) {
            $producto = (int) $item['producto_id'];
            op_asegurar_inventario($pdo, $producto, $sucursal, $user);
            op_mover_stock(
                $pdo,
                $producto,
                $sucursal,
                (int) $item['cantidad'],
                'AJUSTE',
                $user
            );
        }

        $aud = op_auditoria($user);
        $upd = $pdo->prepare(
            'UPDATE ventas
                SET estado = \'ANULADA\', updated_at = :at, updated_by = :by
              WHERE id = :id'
        );
        $upd->execute([':at' => $aud['at'], ':by' => $aud['by'], ':id' => $id]);

        $des = $pdo->prepare(
            'UPDATE despachos_pendientes
                SET estado = \'CANCELADO\', updated_at = :at, updated_by = :by
              WHERE venta_id = :id AND estado IN (\'PENDIENTE\', \'EN_RUTA\')'
        );
        $des->execute([':at' => $aud['at'], ':by' => $aud['by'], ':id' => $id]);
    });

    op_flash('ok', 'Venta anulada: stock devuelto y kardex actualizado.');
} catch (RuntimeException $e) {
    op_flash('error', $e->getMessage());
} catch (Throwable $e) {
    op_flash('error', 'No se pudo anular: ' . (APP_DEBUG ? $e->getMessage() : 'error inesperado.'));
}

op_redir('ventas.php');
