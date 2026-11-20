<?php
declare(strict_types=1);

/* ---------------------------------------------------------------------
   Consultas compartidas por reportes.php y export_csv.php.
   Las mismas SQL alimentan la pantalla y el CSV (RF03/RF10/RF14).
   --------------------------------------------------------------------- */

/**
 * Sucursal efectiva según el rol.
 * ADMIN puede pedir todas (0) o una; OPERADOR queda fijo a la suya (RF11).
 */
function informes_contexto(array $user, PDO $pdo): array
{
    $esAdmin = $user['rol'] === 'ADMIN';
    $sucursales = $pdo->query(
        'SELECT id, nombre FROM sucursales WHERE activa = 1 ORDER BY id'
    )->fetchAll();

    $ids = array_map(static fn(array $s): int => (int) $s['id'], $sucursales);
    $nombres = [];
    foreach ($sucursales as $s) {
        $nombres[(int) $s['id']] = (string) $s['nombre'];
    }

    if ($esAdmin) {
        $pedida = (int) ($_GET['sucursal_id'] ?? 0);
        $sucursalId = $pedida === 0 || in_array($pedida, $ids, true) ? $pedida : 0;
    } else {
        $sucursalId = (int) $user['sucursal_id'];
    }

    return [
        'es_admin'       => $esAdmin,
        'sucursales'     => $sucursales,
        'sucursal_id'    => $sucursalId,
        'sucursal_nombre' => $sucursalId === 0
            ? 'Todas las sucursales'
            : ($nombres[$sucursalId] ?? 'Sucursal'),
    ];
}

/** Fragmento WHERE y parámetros para segmentar por sucursal. */
function filtro_informe(int $sucursalId, string $columna): array
{
    if ($sucursalId <= 0) {
        return ['', []];
    }

    return [' AND ' . $columna . ' = :suc', [':suc' => $sucursalId]];
}

function ejecutar_informe(PDO $pdo, string $sql, array $params): array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll();
}

/** Indicadores del dashboard ejecutivo (RF03). */
function indicadores_informe(PDO $pdo, int $sucursalId): array
{
    [$fInventario, $pInventario] = filtro_informe($sucursalId, 'i.sucursal_id');
    [$fDespachos,  $pDespachos]  = filtro_informe($sucursalId, 'd.sucursal_id');
    [$fVentas,     $pVentas]     = filtro_informe($sucursalId, 'v.sucursal_id');

    $criticos = ejecutar_informe(
        $pdo,
        'SELECT COUNT(*) AS n
           FROM inventario i
           JOIN productos p ON p.id = i.producto_id
          WHERE p.activo = 1
            AND i.stock <= p.stock_minimo' . $fInventario,
        $pInventario
    )[0]['n'];

    $despachos = ejecutar_informe(
        $pdo,
        "SELECT COUNT(*) AS n
           FROM despachos_pendientes d
          WHERE d.estado IN ('PENDIENTE','EN_RUTA')" . $fDespachos,
        $pDespachos
    )[0]['n'];

    $monto = ejecutar_informe(
        $pdo,
        "SELECT ISNULL(SUM(v.total), 0) AS n
           FROM ventas v
          WHERE v.estado = 'COMPLETADA'" . $fVentas,
        $pVentas
    )[0]['n'];

    $unidades = ejecutar_informe(
        $pdo,
        'SELECT ISNULL(SUM(i.stock), 0) AS n FROM inventario i WHERE 1 = 1' . $fInventario,
        $pInventario
    )[0]['n'];

    if ($sucursalId > 0) {
        [$fProductos, $pProductos] = filtro_informe($sucursalId, 'i.sucursal_id');
        $productos = ejecutar_informe(
            $pdo,
            'SELECT COUNT(DISTINCT i.producto_id) AS n
               FROM inventario i
               JOIN productos p ON p.id = i.producto_id
              WHERE p.activo = 1' . $fProductos,
            $pProductos
        )[0]['n'];
    } else {
        $productos = (int) $pdo->query(
            'SELECT COUNT(*) FROM productos WHERE activo = 1'
        )->fetchColumn();
    }

    return [
        'productos'  => (int) $productos,
        'criticos'   => (int) $criticos,
        'despachos'  => (int) $despachos,
        'monto'      => (float) $monto,
        'unidades'   => (int) $unidades,
    ];
}

/** Top 5 de rotación (RF03). */
function top5_informe(PDO $pdo, int $sucursalId): array
{
    [$filtro, $params] = filtro_informe($sucursalId, 'v.sucursal_id');

    return ejecutar_informe(
        $pdo,
        'SELECT TOP (5)
                p.sku,
                p.nombre AS producto,
                SUM(d.cantidad) AS unidades,
                SUM(d.subtotal) AS monto
           FROM venta_detalle d
           JOIN ventas v ON v.id = d.venta_id
           JOIN productos p ON p.id = d.producto_id
          WHERE v.estado = \'COMPLETADA\'' . $filtro . '
          GROUP BY p.id, p.sku, p.nombre
          ORDER BY unidades DESC',
        $params
    );
}

/**
 * Devuelve [cabeceras, filas] de un informe.
 * $tipo: stock | ventas | proveedores | alertas | kardex
 */
function csv_informe(PDO $pdo, string $tipo, int $sucursalId): array
{
    $cabeceras = [
        'stock'        => ['sucursal', 'sku', 'producto', 'stock', 'stock_minimo', 'holgura', 'estado'],
        'ventas'       => ['venta', 'fecha', 'sucursal', 'comprador', 'cajero', 'sku', 'producto', 'cantidad', 'precio_unitario', 'subtotal'],
        'proveedores'  => ['id', 'rut', 'proveedor', 'telefono', 'email', 'lotes_recibidos', 'unidades_ingresadas', 'primera_entrega', 'ultima_entrega'],
        'alertas'      => ['id', 'fecha', 'sucursal', 'sku', 'producto', 'stock_al_momento', 'stock_minimo', 'mensaje'],
        'kardex'       => ['id', 'fecha', 'tipo', 'sku', 'sucursal', 'cantidad', 'stock_resultante', 'responsable', 'numero_lote', 'venta'],
    ];

    if (!isset($cabeceras[$tipo])) {
        throw new InvalidArgumentException('Informe desconocido.');
    }

    switch ($tipo) {
        case 'stock':
            [$filtro, $params] = filtro_informe($sucursalId, 'i.sucursal_id');
            $sql = 'SELECT s.nombre AS sucursal,
                           p.sku,
                           p.nombre AS producto,
                           i.stock,
                           p.stock_minimo,
                           i.stock - p.stock_minimo AS holgura,
                           CASE WHEN i.stock <= p.stock_minimo THEN \'CRITICO\' ELSE \'OK\' END AS estado
                      FROM inventario i
                      JOIN productos p ON p.id = i.producto_id
                      JOIN sucursales s ON s.id = i.sucursal_id
                     WHERE p.activo = 1' . $filtro . '
                     ORDER BY CASE WHEN i.stock <= p.stock_minimo THEN 0 ELSE 1 END,
                              s.nombre, p.sku';
            break;

        case 'ventas':
            [$filtro, $params] = filtro_informe($sucursalId, 'v.sucursal_id');
            $sql = 'SELECT v.id AS venta,
                           CONVERT(VARCHAR(19), v.fecha, 120) AS fecha,
                           s.nombre AS sucursal,
                           ISNULL(c.nombre, \'Consumidor final\') AS comprador,
                           u.username AS cajero,
                           p.sku,
                           p.nombre AS producto,
                           d.cantidad,
                           d.precio_unitario,
                           d.subtotal
                      FROM ventas v
                      JOIN sucursales s ON s.id = v.sucursal_id
                      LEFT JOIN compradores c ON c.id = v.comprador_id
                      JOIN usuarios u ON u.id = v.usuario_id
                      JOIN venta_detalle d ON d.venta_id = v.id
                      JOIN productos p ON p.id = d.producto_id
                     WHERE 1 = 1' . $filtro . '
                     ORDER BY v.fecha DESC, v.id DESC';
            break;

        case 'proveedores':
            /* Relación 1:N: proveedor -> lotes (informe tabla 2 de la pauta).
               RF11: el filtro de sucursal también aplica a este informe y su CSV. */
            [$filtro, $params] = filtro_informe($sucursalId, 'l.sucursal_id');
            $sql = 'SELECT pr.id,
                           pr.rut,
                           pr.nombre AS proveedor,
                           pr.telefono,
                           pr.email,
                           COUNT(l.id) AS lotes_recibidos,
                           ISNULL(SUM(l.cantidad), 0) AS unidades_ingresadas,
                           MIN(l.fecha_ingreso) AS primera_entrega,
                           MAX(l.fecha_ingreso) AS ultima_entrega
                      FROM proveedores pr
                      LEFT JOIN lotes l ON l.proveedor_id = pr.id AND l.anulado = 0'
                      . $filtro . '
                     GROUP BY pr.id, pr.rut, pr.nombre, pr.telefono, pr.email
                     ORDER BY unidades_ingresadas DESC';
            break;

        case 'alertas':
            [$filtro, $params] = filtro_informe($sucursalId, 'a.sucursal_id');
            $sql = 'SELECT a.id,
                           CONVERT(VARCHAR(19), a.fecha, 120) AS fecha,
                           s.nombre AS sucursal,
                           p.sku,
                           p.nombre AS producto,
                           a.stock_al_momento,
                           p.stock_minimo,
                           a.mensaje
                      FROM registro_alertas a
                      JOIN productos p ON p.id = a.producto_id
                      JOIN sucursales s ON s.id = a.sucursal_id
                     WHERE a.leida = 0' . $filtro . '
                     ORDER BY a.fecha DESC';
            break;

        case 'kardex':
            [$filtro, $params] = filtro_informe($sucursalId, 't.sucursal_id');
            $sql = 'SELECT t.id,
                           CONVERT(VARCHAR(19), t.fecha, 120) AS fecha,
                           t.tipo,
                           p.sku,
                           s.nombre AS sucursal,
                           t.cantidad,
                           t.stock_resultante,
                           u.username AS responsable,
                           l.numero_lote,
                           v.id AS venta
                      FROM transacciones t
                      JOIN productos p ON p.id = t.producto_id
                      JOIN sucursales s ON s.id = t.sucursal_id
                      JOIN usuarios u ON u.id = t.usuario_id
                      LEFT JOIN lotes l ON l.id = t.lote_id
                      LEFT JOIN venta_detalle d ON d.id = t.venta_detalle_id
                      LEFT JOIN ventas v ON v.id = d.venta_id
                     WHERE 1 = 1' . $filtro . '
                     ORDER BY t.fecha DESC, t.id DESC';
            break;

        default:
            throw new InvalidArgumentException('Informe desconocido.');
    }

    $filas = ejecutar_informe($pdo, $sql, $params);

    return [$cabeceras[$tipo], array_map('array_values', $filas)];
}
