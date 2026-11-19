<?php
declare(strict_types=1);

require_once __DIR__ . '/operaciones.php';

$user = auth_require();
if (!op_admin($user)) {
    http_response_code(403);
    exit('Solo el administrador puede modificar proveedores.');
}

op_csrf_or_fail('proveedores.php');

$id     = (int) ($_POST['id'] ?? 0);
$nombre = op_texto($_POST['nombre'] ?? null, 150);
$rut    = op_rut($_POST['rut'] ?? null);
$tel    = op_texto($_POST['telefono'] ?? null, 30);
$email  = op_texto($_POST['email'] ?? null, 120);
$pdo    = db();

if ($nombre === '') {
    op_flash('error', 'El nombre del proveedor es obligatorio.');
    op_redir('proveedores.php');
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    op_flash('error', 'El correo electrónico no es válido.');
    op_redir('proveedores.php');
}

$aud = op_auditoria($user);

try {
    op_tx($pdo, static function () use (
        $pdo, $id, $nombre, $rut, $tel, $email, $aud
    ): void {
        if ($id > 0) {
            $upd = $pdo->prepare(
                'UPDATE proveedores
                    SET nombre = :nombre, rut = :rut, telefono = :tel, email = :email,
                        updated_at = :at, updated_by = :by
                  WHERE id = :id'
            );
            $upd->execute([
                ':nombre' => $nombre, ':rut' => $rut, ':tel' => $tel, ':email' => $email,
                ':at' => $aud['at'], ':by' => $aud['by'], ':id' => $id,
            ]);
            return;
        }

        $ins = $pdo->prepare(
            'INSERT INTO proveedores (rut, nombre, telefono, email, activo, created_by)
             VALUES (:rut, :nombre, :tel, :email, 1, :by)'
        );
        $ins->execute([
            ':rut' => $rut, ':nombre' => $nombre, ':tel' => $tel,
            ':email' => $email, ':by' => $aud['by'],
        ]);
    });

    op_flash('ok', 'Proveedor guardado.');
} catch (PDOException $e) {
    $dup = str_contains($e->getMessage(), '23000') || str_contains($e->getMessage(), 'Duplicate');
    op_flash('error', $dup
        ? 'Ese RUT ya está registrado.'
        : 'No se pudo guardar: ' . (APP_DEBUG ? $e->getMessage() : 'error de base de datos.'));
} catch (Throwable $e) {
    op_flash('error', 'No se pudo guardar: ' . (APP_DEBUG ? $e->getMessage() : 'error inesperado.'));
}

op_redir('proveedores.php');
