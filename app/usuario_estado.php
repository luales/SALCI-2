<?php
declare(strict_types=1);

require_once __DIR__ . '/operaciones.php';

$user = auth_require();
if (!op_admin($user)) {
    http_response_code(403);
    exit('Solo el administrador puede cambiar cuentas.');
}

op_csrf_or_fail('usuarios.php');

$pdo    = db();
$id     = (int) ($_POST['id'] ?? 0);
$accion = (string) ($_POST['accion'] ?? '');

if ($id <= 0 || !in_array($accion, ['alta', 'baja'], true)) {
    op_flash('error', 'Solicitud inválida.');
    op_redir('usuarios.php');
}

try {
    op_tx($pdo, static function () use ($pdo, $id, $accion, $user): void {
        $st = $pdo->prepare('SELECT username, rol, activo FROM usuarios WHERE id = :id');
        $st->execute([':id' => $id]);
        $target = $st->fetch();

        if (!$target) {
            throw new RuntimeException('La cuenta no existe.');
        }

        if ($accion === 'baja') {
            if ($id === (int) $user['id']) {
                throw new RuntimeException('No puedes dar de baja tu propia cuenta.');
            }

            if ((int) $target['activo'] === 1 && $target['rol'] === 'ADMIN') {
                $admins = (int) $pdo->query(
                    "SELECT COUNT(*) FROM usuarios WHERE rol = 'ADMIN' AND activo = 1"
                )->fetchColumn();

                if ($admins <= 1) {
                    throw new RuntimeException(
                        'No se puede eliminar al último administrador activo (RF06).'
                    );
                }
            }
        }

        $aud = op_auditoria($user);
        $upd = $pdo->prepare(
            'UPDATE usuarios
                SET activo = :activo, updated_at = :at, updated_by = :by
              WHERE id = :id'
        );
        $upd->execute([
            ':activo' => $accion === 'alta' ? 1 : 0,
            ':at'     => $aud['at'],
            ':by'     => $aud['by'],
            ':id'     => $id,
        ]);
    });

    op_flash('ok', $accion === 'alta' ? 'Cuenta reactivada.' : 'Cuenta eliminada (baja lógica).');
} catch (RuntimeException $e) {
    op_flash('error', $e->getMessage());
} catch (Throwable $e) {
    op_flash('error', 'No se pudo cambiar el estado: ' . (APP_DEBUG ? $e->getMessage() : 'error.'));
}

op_redir('usuarios.php');
