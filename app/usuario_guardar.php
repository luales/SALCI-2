<?php
declare(strict_types=1);

require_once __DIR__ . '/operaciones.php';

$user = auth_require();
if (!op_admin($user)) {
    http_response_code(403);
    exit('Solo el administrador puede gestionar cuentas.');
}

op_csrf_or_fail('usuarios.php');

$pdo      = db();
$id       = (int) ($_POST['id'] ?? 0);
$username = op_texto($_POST['username'] ?? null, 50);
$password = (string) ($_POST['password'] ?? '');
$rol      = (string) ($_POST['rol'] ?? '');
$sucursal = (int) ($_POST['sucursal_id'] ?? 0);
$aud      = op_auditoria($user);

try {
    if ($username === '' || !in_array($rol, ['ADMIN', 'OPERADOR'], true)) {
        throw new RuntimeException('Usuario y rol son obligatorios.');
    }

    if ($rol === 'OPERADOR' && $sucursal <= 0) {
        throw new RuntimeException('Un OPERADOR debe tener una sucursal asignada.');
    }
    if ($rol === 'ADMIN') {
        $sucursal = 0;
    }

    if ($id === 0 && mb_strlen($password, 'UTF-8') < 8) {
        throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');
    }

    $sucursalDb = $sucursal > 0 ? $sucursal : null;

    if ($sucursalDb !== null) {
        $st = $pdo->prepare('SELECT 1 FROM sucursales WHERE id = :id AND activa = 1');
        $st->execute([':id' => $sucursalDb]);
        if ($st->fetchColumn() === false) {
            throw new RuntimeException('La sucursal no existe o está deshabilitada.');
        }
    }

    if ($id > 0) {
        $st = $pdo->prepare('SELECT rol, activo FROM usuarios WHERE id = :id');
        $st->execute([':id' => $id]);
        $target = $st->fetch();

        if (!$target) {
            throw new RuntimeException('La cuenta no existe.');
        }

        if ($rol !== 'ADMIN' && $target['rol'] === 'ADMIN' && (int) $target['activo'] === 1) {
            $admins = (int) $pdo->query(
                "SELECT COUNT(*) FROM usuarios WHERE rol = 'ADMIN' AND activo = 1"
            )->fetchColumn();

            if ($admins <= 1) {
                throw new RuntimeException(
                    'No se puede quitar el rol al último administrador activo.'
                );
            }
        }
    }

    op_tx($pdo, static function () use (
        $pdo, $id, $username, $password, $rol, $sucursalDb, $aud
    ): void {
        $cambiaPass = $password !== '';
        $credencial = $cambiaPass ? op_hash_password($password) : null;

        if ($id > 0) {
            $sql = 'UPDATE usuarios
                       SET username = :username, rol = :rol, sucursal_id = :suc,
                           updated_at = :at, updated_by = :by';
            $params = [
                ':username' => $username,
                ':rol'      => $rol,
                ':suc'      => $sucursalDb,
                ':at'       => $aud['at'],
                ':by'       => $aud['by'],
                ':id'       => $id,
            ];

            if ($credencial !== null) {
                $sql .= ', password_hash = :hash, salt = :salt';
                $params[':hash'] = $credencial['hash'];
                $params[':salt'] = $credencial['salt'];
            }

            $sql .= ' WHERE id = :id';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return;
        }

        $credencial = $credencial ?? op_hash_password($password);
        $st = $pdo->prepare(
            'INSERT INTO usuarios
                (username, password_hash, salt, rol, sucursal_id, activo, created_by)
             VALUES
                (:username, :hash, :salt, :rol, :suc, 1, :by)'
        );
        $st->execute([
            ':username' => $username,
            ':hash'     => $credencial['hash'],
            ':salt'     => $credencial['salt'],
            ':rol'      => $rol,
            ':suc'      => $sucursalDb,
            ':by'       => $aud['by'],
        ]);
    });

    op_flash('ok', 'Cuenta guardada.');
} catch (PDOException $e) {
    $dup = str_contains($e->getMessage(), '23000') || str_contains($e->getMessage(), 'Duplicate');
    op_flash('error', $dup
        ? 'Ese nombre de usuario ya está registrado.'
        : 'No se pudo guardar: ' . (APP_DEBUG ? $e->getMessage() : 'error de base de datos.'));
} catch (RuntimeException $e) {
    op_flash('error', $e->getMessage());
} catch (Throwable $e) {
    op_flash('error', 'No se pudo guardar: ' . (APP_DEBUG ? $e->getMessage() : 'error inesperado.'));
}

op_redir('usuarios.php');
