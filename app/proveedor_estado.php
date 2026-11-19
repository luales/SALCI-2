<?php
declare(strict_types=1);

require_once __DIR__ . '/operaciones.php';

$user = auth_require();
if (!op_admin($user)) {
    http_response_code(403);
    exit('Solo el administrador puede cambiar proveedores.');
}

op_csrf_or_fail('proveedores.php');

$id = (int) ($_POST['id'] ?? 0);
$accion = (string) ($_POST['accion'] ?? '');
if ($id <= 0 || !in_array($accion, ['alta', 'baja'], true)) {
    op_flash('error', 'Solicitud inválida.');
    op_redir('proveedores.php');
}

$aud = op_auditoria($user);

try {
    op_tx(db(), static function () use ($id, $accion, $aud): void {
        $st = db()->prepare(
            'UPDATE proveedores SET activo = :activo, updated_at = :at, updated_by = :by
              WHERE id = :id'
        );
        $st->execute([
            ':activo' => $accion === 'alta' ? 1 : 0,
            ':at' => $aud['at'], ':by' => $aud['by'], ':id' => $id,
        ]);
    });
    op_flash('ok', $accion === 'alta' ? 'Proveedor reactivado.' : 'Proveedor dado de baja.');
} catch (Throwable $e) {
    op_flash('error', 'No se pudo cambiar el estado: ' . (APP_DEBUG ? $e->getMessage() : 'error.'));
}

op_redir('proveedores.php');
