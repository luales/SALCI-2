<?php
declare(strict_types=1);

require_once __DIR__ . '/operaciones.php';

$user = auth_require();
$pdo  = db();
op_csrf_or_fail('ingresos.php');

$id        = (int) ($_POST['id'] ?? 0);
$esAdmin   = op_admin($user);
$producto  = (int) ($_POST['producto_id'] ?? 0);
$sucursal  = (int) ($_POST['sucursal_id'] ?? 0);
$cantidad  = trim((string) ($_POST['cantidad'] ?? ''));
$proveedor = (int) ($_POST['proveedor_id'] ?? 0);
$numero    = op_texto($_POST['numero_lote'] ?? null, 50);
$fecha     = op_fecha($_POST['fecha_ingreso'] ?? null);

if (!$esAdmin) {
    $sucursal = (int) $user['sucursal_id'];
}

if ($id > 0 && !$esAdmin) {
    http_response_code(403);
    exit('Solo el administrador puede editar ingresos.');
}

try {
    if ($id > 0) {
        op_tx($pdo, static function () use ($pdo, $id, $proveedor, $numero, $fecha, $user): void {
            $aud = op_auditoria($user);
            $st = $pdo->prepare(
                'UPDATE lotes
                    SET proveedor_id = :prov, numero_lote = :numero, fecha_ingreso = :fecha,
                        updated_at = :at, updated_by = :by
                  WHERE id = :id AND anulado = 0'
            );
            $st->execute([
                ':prov'   => $proveedor > 0 ? $proveedor : null,
                ':numero' => $numero !== '' ? $numero : null,
                ':fecha'  => $fecha ?? date('Y-m-d 12:00:00'),
                ':at'     => $aud['at'],
                ':by'     => $aud['by'],
                ':id'     => $id,
            ]);
        });

        op_flash('ok', 'Datos del lote actualizados.');
        op_redir('ingresos.php');
    }

    /* RF04: la fecha de recepción es opcional; si se omite se usa hoy. */
    if ($producto <= 0 || !ctype_digit($cantidad) || (int) $cantidad <= 0) {
        throw new RuntimeException('Revisa los datos: producto y cantidad (entero > 0) son obligatorios.');
    }

    $st = $pdo->prepare('SELECT 1 FROM productos WHERE id = :id AND activo = 1');
    $st->execute([':id' => $producto]);
    if ($st->fetchColumn() === false) {
        throw new RuntimeException('El producto no existe o está de baja.');
    }

    $st = $pdo->prepare('SELECT 1 FROM sucursales WHERE id = :id AND activa = 1');
    $st->execute([':id' => $sucursal]);
    if ($st->fetchColumn() === false) {
        throw new RuntimeException('La sucursal no existe o está deshabilitada.');
    }

    if ($proveedor > 0) {
        $st = $pdo->prepare('SELECT 1 FROM proveedores WHERE id = :id AND activo = 1');
        $st->execute([':id' => $proveedor]);
        if ($st->fetchColumn() === false) {
            throw new RuntimeException('El proveedor no existe o está de baja.');
        }
    }

    op_tx($pdo, static function () use (
        $pdo, $producto, $sucursal, $cantidad, $proveedor, $numero, $fecha, $user
    ): int {
        $aud = op_auditoria($user);

        $ins = $pdo->prepare(
            'INSERT INTO lotes
                (producto_id, sucursal_id, cantidad, proveedor_id, numero_lote,
                 fecha_ingreso, anulado, created_by, updated_at, updated_by)
             OUTPUT INSERTED.id
             VALUES (:producto, :sucursal, :cantidad, :proveedor, :numero,
                     :fecha, 0, :by, :uat, :uby)'
        );
        $ins->execute([
            ':producto'  => $producto,
            ':sucursal'  => $sucursal,
            ':cantidad'  => (int) $cantidad,
            ':proveedor' => $proveedor > 0 ? $proveedor : null,
            ':numero'    => $numero !== '' ? $numero : null,
            ':fecha'     => $fecha ?? date('Y-m-d 12:00:00'),
            ':by'        => $aud['by'],
            ':uat'       => $aud['at'],
            ':uby'       => $aud['by'],
        ]);

        $loteId = (int) $ins->fetchColumn();
        $ins->closeCursor();

        if ($loteId <= 0) {
            throw new RuntimeException('No se pudo crear el lote.');
        }

        op_asegurar_inventario($pdo, $producto, $sucursal, $user);
        op_mover_stock(
            $pdo, $producto, $sucursal, (int) $cantidad, 'INGRESO', $user, $loteId
        );

        return $loteId;
    });

    op_flash('ok', 'Ingreso registrado: stock y kardex actualizados.');
} catch (RuntimeException $e) {
    op_flash('error', $e->getMessage());
} catch (Throwable $e) {
    op_flash('error', 'No se pudo registrar: ' . (APP_DEBUG ? $e->getMessage() : 'error inesperado.'));
}

op_redir('ingresos.php');
