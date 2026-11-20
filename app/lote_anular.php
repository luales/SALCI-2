<?php
declare(strict_types=1);

require_once __DIR__ . '/operaciones.php';

$user = auth_require();
if (!op_admin($user)) {
    http_response_code(403);
    exit('Solo el administrador puede anular ingresos.');
}

op_csrf_or_fail('ingresos.php');

$id  = (int) ($_POST['id'] ?? 0);
$pdo = db();

if ($id <= 0) {
    op_flash('error', 'Solicitud inválida.');
    op_redir('ingresos.php');
}

try {
    op_tx($pdo, static function () use ($pdo, $id, $user): void {
        $st = $pdo->prepare(
            'SELECT producto_id, sucursal_id, cantidad
               FROM lotes WHERE id = :id AND anulado = 0'
        );
        $st->execute([':id' => $id]);
        $lote = $st->fetch();

        if (!$lote) {
            throw new RuntimeException('El lote no existe o ya está anulado.');
        }

        /* Reversa por un AJUSTE ligado al lote: el kardex queda completo y auditable. */
        op_mover_stock(
            $pdo,
            (int) $lote['producto_id'],
            (int) $lote['sucursal_id'],
            -((int) $lote['cantidad']),
            'AJUSTE',
            $user,
            $id
        );

        $aud = op_auditoria($user);
        $upd = $pdo->prepare(
            'UPDATE lotes SET anulado = 1, updated_at = :at, updated_by = :by WHERE id = :id'
        );
        $upd->execute([':at' => $aud['at'], ':by' => $aud['by'], ':id' => $id]);
    });

    op_flash('ok', 'Ingreso anulado: stock revertido y alta en el kardex.');
} catch (RuntimeException $e) {
    op_flash('error', $e->getMessage());
} catch (Throwable $e) {
    op_flash('error', 'No se pudo anular: ' . (APP_DEBUG ? $e->getMessage() : 'error inesperado.'));
}

op_redir('ingresos.php');
