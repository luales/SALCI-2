<?php
declare(strict_types=1);

require_once __DIR__ . '/operaciones.php';

$user = auth_require();
$pdo  = db();
op_csrf_or_fail('despachos.php');

$id     = (int) ($_POST['id'] ?? 0);
$estado = (string) ($_POST['estado'] ?? '');

if ($id <= 0 || !in_array($estado, ['EN_RUTA', 'COMPLETADO', 'CANCELADO'], true)) {
    op_flash('error', 'Solicitud inválida.');
    op_redir('despachos.php');
}

try {
    op_tx($pdo, static function () use ($pdo, $id, $estado, $user): void {
        $st = $pdo->prepare(
            'SELECT estado, sucursal_id
               FROM despachos_pendientes WHERE id = :id'
        );
        $st->execute([':id' => $id]);
        $fila = $st->fetch();

        if (!$fila) {
            throw new RuntimeException('El despacho no existe.');
        }

        if (!op_admin($user)
            && (int) $fila['sucursal_id'] !== (int) $user['sucursal_id']) {
            throw new RuntimeException('No tienes acceso a despachos de otra sucursal.');
        }

        $actual = (string) $fila['estado'];

        $permitido = match ($actual) {
            'PENDIENTE' => in_array($estado, ['EN_RUTA', 'CANCELADO'], true),
            'EN_RUTA'   => in_array($estado, ['COMPLETADO', 'CANCELADO'], true),
            default     => false,
        };

        if (!$permitido) {
            throw new RuntimeException('Transición de estado no permitida.');
        }

        /* RF01: la entrega sin venta asociada es una salida de almacen. */
        $mov = $pdo->prepare(
            'SELECT producto_id, sucursal_id, cantidad, venta_id
               FROM despachos_pendientes WHERE id = :id'
        );
        $mov->execute([':id' => $id]);
        $despacho = $mov->fetch();

        if (!$despacho) {
            throw new RuntimeException('El despacho no existe.');
        }

        if ($estado === 'COMPLETADO' && $despacho['venta_id'] === null) {
            op_mover_stock(
                $pdo,
                (int) $despacho['producto_id'],
                (int) $despacho['sucursal_id'],
                -((int) $despacho['cantidad']),
                'AJUSTE',
                $user
            );
        }

        $aud = op_auditoria($user);
        $upd = $pdo->prepare(
            'UPDATE despachos_pendientes
                SET estado = :estado, fecha_entrega = :entrega,
                    updated_at = :at, updated_by = :by
              WHERE id = :id'
        );
        $upd->execute([
            ':estado'  => $estado,
            ':entrega' => $estado === 'COMPLETADO' ? $aud['at'] : null,
            ':at'      => $aud['at'],
            ':by'      => $aud['by'],
            ':id'      => $id,
        ]);
    });

    op_flash('ok', 'Estado del despacho actualizado a ' . $estado . '.');
} catch (RuntimeException $e) {
    op_flash('error', $e->getMessage());
} catch (Throwable $e) {
    op_flash('error', 'No se pudo actualizar: ' . (APP_DEBUG ? $e->getMessage() : 'error inesperado.'));
}

op_redir('despachos.php');
