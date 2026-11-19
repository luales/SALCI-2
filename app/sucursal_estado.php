<?php
declare(strict_types=1);

require_once __DIR__ . '/operaciones.php';

$user = auth_require();
if (!op_admin($user)) {
    http_response_code(403);
    exit('Solo el administrador puede cambiar sucursales.');
}

op_csrf_or_fail('sucursales.php');

$pdo    = db();
$id     = (int) ($_POST['id'] ?? 0);
$accion = (string) ($_POST['accion'] ?? '');

if ($id <= 0 || !in_array($accion, ['activar', 'deshabilitar', 'eliminar'], true)) {
    op_flash('error', 'Solicitud inválida.');
    op_redir('sucursales.php');
}

try {
    op_tx($pdo, static function () use ($pdo, $id, $accion, $user): void {
        $st = $pdo->prepare('SELECT nombre, activa FROM sucursales WHERE id = :id');
        $st->execute([':id' => $id]);
        $sucursal = $st->fetch();

        if (!$sucursal) {
            throw new RuntimeException('La sucursal no existe.');
        }

        if ($accion === 'eliminar') {
            $usuarios = (int) $pdo->query(
                'SELECT COUNT(*) FROM usuarios WHERE sucursal_id = ' . $id
            )->fetchColumn();
            $stock = (int) $pdo->query(
                'SELECT ISNULL(SUM(stock), 0) FROM inventario WHERE sucursal_id = ' . $id
            )->fetchColumn();
            $movimientos = (int) $pdo->query(
                'SELECT COUNT(*) FROM transacciones WHERE sucursal_id = ' . $id
            )->fetchColumn();

            if ($usuarios > 0 || $stock > 0 || $movimientos > 0) {
                throw new RuntimeException(
                    'No se puede eliminar: la sucursal tiene usuarios, existencias o movimientos (RF07).'
                );
            }

            $del = $pdo->prepare('DELETE FROM sucursales WHERE id = :id');
            $del->execute([':id' => $id]);
            return;
        }

        $aud = op_auditoria($user);
        $upd = $pdo->prepare(
            'UPDATE sucursales
                SET activa = :activa, updated_at = :at, updated_by = :by
              WHERE id = :id'
        );
        $upd->execute([
            ':activa' => $accion === 'activar' ? 1 : 0,
            ':at'     => $aud['at'],
            ':by'     => $aud['by'],
            ':id'     => $id,
        ]);
    });

    $msg = match ($accion) {
        'eliminar' => 'Sucursal eliminada.',
        'activar'  => 'Sucursal activada.',
        default    => 'Sucursal deshabilitada.',
    };
    op_flash('ok', $msg);
} catch (RuntimeException $e) {
    op_flash('error', $e->getMessage());
} catch (PDOException $e) {
    op_flash('error', 'No se pudo completar: ' . (APP_DEBUG ? $e->getMessage() : 'error de base de datos.'));
} catch (Throwable $e) {
    op_flash('error', 'No se pudo completar: ' . (APP_DEBUG ? $e->getMessage() : 'error inesperado.'));
}

op_redir('sucursales.php');
