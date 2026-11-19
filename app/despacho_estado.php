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
        $st = $pdo->prepare('SELECT estado FROM despachos_pendientes WHERE id = :id');
        $st->execute([':id' => $id]);
        $actual = (string) $st->fetchColumn();

        $permitido = match ($actual) {
            'PENDIENTE' => in_array($estado, ['EN_RUTA', 'CANCELADO'], true),
            'EN_RUTA'   => in_array($estado, ['COMPLETADO', 'CANCELADO'], true),
            default     => false,
        };

        if (!$permitido) {
            throw new RuntimeException('Transición de estado no permitida.');
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
