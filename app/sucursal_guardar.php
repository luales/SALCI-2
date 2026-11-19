<?php
declare(strict_types=1);

require_once __DIR__ . '/operaciones.php';

$user = auth_require();
if (!op_admin($user)) {
    http_response_code(403);
    exit('Solo el administrador puede modificar sucursales.');
}

op_csrf_or_fail('sucursales.php');

$pdo      = db();
$id       = (int) ($_POST['id'] ?? 0);
$nombre   = op_texto($_POST['nombre'] ?? null, 120);
$direccion = op_texto($_POST['direccion'] ?? null, 200);
$aud      = op_auditoria($user);

try {
    if ($nombre === '') {
        throw new RuntimeException('El nombre de la sucursal es obligatorio.');
    }

    op_tx($pdo, static function () use ($pdo, $id, $nombre, $direccion, $aud): void {
        if ($id > 0) {
            $st = $pdo->prepare(
                'UPDATE sucursales
                    SET nombre = :nombre, direccion = :direccion,
                        updated_at = :at, updated_by = :by
                  WHERE id = :id'
            );
            $st->execute([
                ':nombre'   => $nombre,
                ':direccion'=> $direccion !== '' ? $direccion : null,
                ':at'       => $aud['at'],
                ':by'       => $aud['by'],
                ':id'       => $id,
            ]);
            return;
        }

        $st = $pdo->prepare(
            'INSERT INTO sucursales (nombre, direccion, activa, created_by)
             VALUES (:nombre, :direccion, 1, :by)'
        );
        $st->execute([
            ':nombre'   => $nombre,
            ':direccion'=> $direccion !== '' ? $direccion : null,
            ':by'       => $aud['by'],
        ]);
    });

    op_flash('ok', 'Sucursal guardada.');
} catch (PDOException $e) {
    $dup = str_contains($e->getMessage(), '23000') || str_contains($e->getMessage(), 'Duplicate');
    op_flash('error', $dup
        ? 'Ya existe una sucursal con ese nombre.'
        : 'No se pudo guardar: ' . (APP_DEBUG ? $e->getMessage() : 'error de base de datos.'));
} catch (RuntimeException $e) {
    op_flash('error', $e->getMessage());
} catch (Throwable $e) {
    op_flash('error', 'No se pudo guardar: ' . (APP_DEBUG ? $e->getMessage() : 'error inesperado.'));
}

op_redir('sucursales.php');
