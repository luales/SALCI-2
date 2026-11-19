<?php
declare(strict_types=1);

require_once __DIR__ . '/operaciones.php';

$user = auth_require();
if (!op_admin($user)) {
    http_response_code(403);
    exit('Solo el administrador puede cambiar el estado del catálogo.');
}

op_csrf_or_fail('productos.php');

$id     = (int) ($_POST['id'] ?? 0);
$accion = (string) ($_POST['accion'] ?? '');
$pdo    = db();

if ($id <= 0 || !in_array($accion, ['alta', 'baja'], true)) {
    op_flash('error', 'Solicitud inválida.');
    op_redir('productos.php');
}

$aud = op_auditoria($user);

try {
    op_tx($pdo, static function () use ($pdo, $id, $accion, $aud): void {
        $upd = $pdo->prepare(
            'UPDATE productos
                SET activo = :activo, updated_at = :at, updated_by = :by
              WHERE id = :id'
        );
        $upd->execute([
            ':activo' => $accion === 'alta' ? 1 : 0,
            ':at'     => $aud['at'],
            ':by'     => $aud['by'],
            ':id'     => $id,
        ]);

        if ($upd->rowCount() === 0) {
            throw new RuntimeException('El producto ya no existe.');
        }
    });

    op_flash('ok', $accion === 'alta' ? 'Producto reactivado.' : 'Producto dado de baja.');
} catch (Throwable $e) {
    op_flash('error', 'No se pudo cambiar el estado: ' . (APP_DEBUG ? $e->getMessage() : 'error.'));
}

op_redir('productos.php');
