<?php
declare(strict_types=1);

require_once __DIR__ . '/operaciones.php';

$user = auth_require();
if (!op_admin($user)) {
    http_response_code(403);
    exit('Solo el administrador puede modificar el catálogo.');
}

op_csrf_or_fail('productos.php');

$id    = (int) ($_POST['id'] ?? 0);
$sku   = op_texto($_POST['sku'] ?? null, 30);
$nombre = op_texto($_POST['nombre'] ?? null, 150);
$precioRaw = str_replace(',', '.', trim((string) ($_POST['precio'] ?? '')));
$minRaw = trim((string) ($_POST['stock_minimo'] ?? ''));

$volver = 'producto_form.php' . ($id > 0 ? '?id=' . $id : '');
$pdo = db();

if ($sku === '' || $nombre === '' || !is_numeric($precioRaw) || !ctype_digit($minRaw)) {
    op_flash('error', 'Revisa los datos: SKU y nombre son obligatorios; precio y mínimo deben ser >= 0.');
    op_redir($volver);
}

$precio = (float) $precioRaw;
$minimo = (int) $minRaw;
if ($precio < 0 || $minimo < 0) {
    op_flash('error', 'El precio y el stock mínimo no pueden ser negativos.');
    op_redir($volver);
}

$precioDb = number_format($precio, 2, '.', '');
$aud      = op_auditoria($user);

try {
    op_tx($pdo, static function () use (
        $pdo, $id, $sku, $nombre, $precioDb, $minimo, $aud
    ): void {
        if ($id > 0) {
            $existe = $pdo->prepare('SELECT 1 FROM productos WHERE id = :id');
            $existe->execute([':id' => $id]);
            if ($existe->fetchColumn() === false) {
                throw new RuntimeException('El producto ya no existe.');
            }

            $upd = $pdo->prepare(
                'UPDATE productos
                    SET sku = :sku, nombre = :nombre, precio = :precio,
                        stock_minimo = :minimo, updated_at = :at, updated_by = :by
                  WHERE id = :id'
            );
            $upd->execute([
                ':sku'    => $sku,
                ':nombre' => $nombre,
                ':precio' => $precioDb,
                ':minimo' => $minimo,
                ':at'     => $aud['at'],
                ':by'     => $aud['by'],
                ':id'     => $id,
            ]);

            return;
        }

        $ins = $pdo->prepare(
            'INSERT INTO productos
                (sku, nombre, precio, stock_minimo, activo, created_by)
             OUTPUT INSERTED.id
             VALUES (:sku, :nombre, :precio, :minimo, 1, :by)'
        );
        $ins->execute([
            ':sku'    => $sku,
            ':nombre' => $nombre,
            ':precio' => $precioDb,
            ':minimo' => $minimo,
            ':by'     => $aud['by'],
        ]);

        $productoId = (int) $ins->fetchColumn();
        $ins->closeCursor();

        if ($productoId <= 0) {
            throw new RuntimeException('No se pudo crear el producto.');
        }

        /* El stock vive en inventario por sucursal (3FN). */
        $sucursales = $pdo->query(
            'SELECT id FROM sucursales WHERE activa = 1'
        )->fetchAll();

        $insInv = $pdo->prepare(
            'INSERT INTO inventario
                (producto_id, sucursal_id, stock, created_by, updated_by)
             VALUES (:producto, :sucursal, 0, :by1, :by2)'
        );

        foreach ($sucursales as $s) {
            $insInv->execute([
                ':producto' => $productoId,
                ':sucursal' => (int) $s['id'],
                ':by1'      => $aud['by'],
                ':by2'      => $aud['by'],
            ]);
        }
    });

    op_flash('ok', $id > 0 ? 'Producto actualizado.' : 'Producto creado con stock inicial 0.');
} catch (PDOException $e) {
    $esDuplicado = str_contains($e->getMessage(), '23000')
        || str_contains($e->getMessage(), 'uq_productos_sku')
        || str_contains($e->getMessage(), 'Duplicate');

    op_flash(
        'error',
        $esDuplicado
            ? 'Ese SKU ya está registrado en el catálogo.'
            : 'No se pudo guardar: ' . (APP_DEBUG ? $e->getMessage() : 'error de base de datos.')
    );
} catch (Throwable $e) {
    op_flash('error', 'No se pudo guardar: ' . (APP_DEBUG ? $e->getMessage() : 'error inesperado.'));
}

op_redir('productos.php');
