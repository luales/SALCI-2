/* =====================================================================
   PROYECTO SALCI - Script 03: Consultas SQL
   ---------------------------------------------------------------------
   Contiene las 4 consultas exigidas por la pauta de la Evaluacion 4
   y consultas de apoyo para el dashboard y el prototipo.

     [PAUTA 1]  Valida login
     [PAUTA 2]  Informe tabla 1
     [PAUTA 3]  Informe tabla 2 (relacion 1:N)
     [PAUTA 4]  Informe de transacciones (relacion N:N)

   Ejecutar:  sqlcmd -S .\SQLEXPRESS -E -C -f 65001 -i db\03_queries.sql
   Si tu base no se llama SALCI, cambia el USE SALCI; de abajo por tu base.
   ===================================================================== */

USE SALCI;
GO

SET NOCOUNT ON;

/* Parametros de prueba (en el prototipo vienen del formulario) */
DECLARE @username    NVARCHAR(50)  = N'cajero.chillan';
DECLARE @password    NVARCHAR(100) = N'Salci2026!';
DECLARE @sucursal_id INT           = NULL;   -- NULL = todas las sucursales


/* =====================================================================
   [PAUTA 1] VALIDA LOGIN
   ---------------------------------------------------------------------
   Devuelve el usuario solo si esta activo y la credencial coincide.
   El hash se calcula como SHA-256( salt_binario || password ) en hex,
   igual que lo hara PHP con hash('sha256', hex2bin($salt).$password).
   ===================================================================== */
SELECT  u.id,
        u.username,
        u.rol,
        u.sucursal_id,
        s.nombre AS sucursal,
        u.activo,
        CASE WHEN u.password_hash = x.hash_calculado THEN 1 ELSE 0 END AS credencial_valida
FROM usuarios u
LEFT JOIN sucursales s ON s.id = u.sucursal_id
CROSS APPLY (
    SELECT CONVERT(CHAR(64), HASHBYTES('SHA2_256',
               CONVERT(VARBINARY(32), u.salt, 2)
             + CONVERT(VARBINARY(MAX), CONVERT(VARCHAR(100), @password))), 2) AS hash_calculado
) x
WHERE u.username = @username
  AND u.activo = 1
  AND u.password_hash = x.hash_calculado;


/* =====================================================================
   [PAUTA 2] INFORME TABLA 1 - STOCK EN VIVO  (RF10, RF11)
   ---------------------------------------------------------------------
   Muestra el inventario por sucursal y marca en critico los productos
   cuyo saldo es menor o igual al umbral de seguridad.
   ===================================================================== */
SELECT  s.nombre                                   AS sucursal,
        p.sku,
        p.nombre                                   AS producto,
        i.stock,
        p.stock_minimo,
        i.stock - p.stock_minimo                   AS holgura,
        CASE WHEN i.stock <= p.stock_minimo
             THEN 'CRITICO' ELSE 'OK' END          AS estado
FROM inventario i
INNER JOIN productos  p ON p.id = i.producto_id
INNER JOIN sucursales s ON s.id = i.sucursal_id
WHERE p.activo = 1
  AND (@sucursal_id IS NULL OR i.sucursal_id = @sucursal_id)
ORDER BY CASE WHEN i.stock <= p.stock_minimo THEN 0 ELSE 1 END,
         s.nombre, p.sku;

-- Misma informacion usando la vista de apoyo
SELECT * FROM vw_stock_en_vivo
WHERE @sucursal_id IS NULL OR sucursal_id = @sucursal_id
ORDER BY es_critico DESC, sucursal, sku;


/* =====================================================================
   [PAUTA 3] INFORME TABLA 2 - RELACION 1:N  (proveedores -> lotes)
   ---------------------------------------------------------------------
   Un proveedor tiene muchos lotes de ingreso; cada lote pertenece a un
   solo proveedor.
   ===================================================================== */
SELECT  pr.id,
        pr.rut,
        pr.nombre                          AS proveedor,
        pr.telefono,
        pr.email,
        COUNT(l.id)                        AS lotes_recibidos,
        ISNULL(SUM(l.cantidad), 0)         AS unidades_ingresadas,
        MIN(l.fecha_ingreso)               AS primera_entrega,
        MAX(l.fecha_ingreso)               AS ultima_entrega
FROM proveedores pr
LEFT JOIN lotes l ON l.proveedor_id = pr.id
GROUP BY pr.id, pr.rut, pr.nombre, pr.telefono, pr.email
ORDER BY unidades_ingresadas DESC;

-- Segunda relacion 1:N: sucursal -> inventario
SELECT  s.id                              AS sucursal_id,
        s.nombre                          AS sucursal,
        COUNT(i.id)                       AS productos_distintos,
        ISNULL(SUM(i.stock), 0)           AS unidades_en_stock
FROM sucursales s
LEFT JOIN inventario i ON i.sucursal_id = s.id
GROUP BY s.id, s.nombre
ORDER BY s.id;


/* =====================================================================
   [PAUTA 4] INFORME DE TRANSACCIONES - RELACION N:N
   ---------------------------------------------------------------------
   Una venta tiene muchos productos y un producto aparece en muchas
   ventas. La tabla VENTA_DETALLE resuelve la relacion N:N.
   ===================================================================== */
SELECT  v.id                                         AS venta,
        CONVERT(DATE, v.fecha)                       AS fecha,
        s.nombre                                     AS sucursal,
        ISNULL(c.nombre, 'Consumidor final')         AS comprador,
        u.username                                   AS cajero,
        p.sku,
        p.nombre                                     AS producto,
        d.cantidad,
        d.precio_unitario,
        d.subtotal
FROM ventas v
INNER JOIN sucursales   s ON s.id = v.sucursal_id
LEFT  JOIN compradores  c ON c.id = v.comprador_id
INNER JOIN usuarios     u ON u.id = v.usuario_id
INNER JOIN venta_detalle d ON d.venta_id = v.id
INNER JOIN productos    p ON p.id = d.producto_id
WHERE v.estado = 'COMPLETADA'
  AND (@sucursal_id IS NULL OR v.sucursal_id = @sucursal_id)
ORDER BY v.fecha, v.id, p.sku;

-- Resumen N:N: ventas por producto
SELECT  p.sku,
        p.nombre                        AS producto,
        COUNT(DISTINCT d.venta_id)      AS ventas_distintas,
        SUM(d.cantidad)                 AS unidades_vendidas,
        SUM(d.subtotal)                 AS monto_vendido
FROM venta_detalle d
INNER JOIN ventas    v ON v.id = d.venta_id
INNER JOIN productos p ON p.id = d.producto_id
WHERE v.estado = 'COMPLETADA'
  AND (@sucursal_id IS NULL OR v.sucursal_id = @sucursal_id)
GROUP BY p.id, p.sku, p.nombre
ORDER BY unidades_vendidas DESC;


/* =====================================================================
   APOYO 1 - DASHBOARD EJECUTIVO  (RF03)
   ===================================================================== */
SELECT
    (SELECT COUNT(*) FROM productos)                                   AS productos_catalogo,
    (SELECT COUNT(*) FROM inventario i
     INNER JOIN productos p ON p.id = i.producto_id
     WHERE i.stock <= p.stock_minimo
       AND (@sucursal_id IS NULL OR i.sucursal_id = @sucursal_id))     AS items_criticos,
    (SELECT COUNT(*) FROM despachos_pendientes d
     WHERE d.estado IN ('PENDIENTE','EN_RUTA')
       AND (@sucursal_id IS NULL OR d.sucursal_id = @sucursal_id))     AS despachos_pendientes,
    (SELECT COUNT(*) FROM registro_alertas a
     WHERE a.leida = 0
       AND (@sucursal_id IS NULL OR a.sucursal_id = @sucursal_id))     AS alertas_sin_leer,
    (SELECT ISNULL(SUM(v.total), 0) FROM ventas v
     WHERE v.estado = 'COMPLETADA'
       AND (@sucursal_id IS NULL OR v.sucursal_id = @sucursal_id))     AS venta_total;


/* =====================================================================
   APOYO 2 - TOP 5 DE ROTACION  (RF03)
   ===================================================================== */
SELECT TOP (5)
        p.sku,
        p.nombre                        AS producto,
        SUM(d.cantidad)                 AS unidades_vendidas
FROM venta_detalle d
INNER JOIN ventas    v ON v.id = d.venta_id
INNER JOIN productos p ON p.id = d.producto_id
WHERE v.estado = 'COMPLETADA'
  AND (@sucursal_id IS NULL OR v.sucursal_id = @sucursal_id)
GROUP BY p.id, p.sku, p.nombre
ORDER BY unidades_vendidas DESC;


/* =====================================================================
   APOYO 3 - ALERTAS SIN LEER  (RF02)
   ===================================================================== */
SELECT a.id,
       CONVERT(DATETIME2(0), a.fecha) AS fecha,
       s.nombre                       AS sucursal,
       p.sku,
       p.nombre                       AS producto,
       a.stock_al_momento,
       p.stock_minimo,
       a.mensaje
FROM registro_alertas a
INNER JOIN productos  p ON p.id = a.producto_id
INNER JOIN sucursales s ON s.id = a.sucursal_id
WHERE a.leida = 0
  AND (@sucursal_id IS NULL OR a.sucursal_id = @sucursal_id)
ORDER BY a.fecha DESC;


/* =====================================================================
   APOYO 4 - KARDEX / TRAZABILIDAD  (RF13)
   ===================================================================== */
SELECT  t.id,
        CONVERT(DATETIME2(0), t.fecha) AS fecha,
        t.tipo,
        p.sku,
        s.nombre                       AS sucursal,
        t.cantidad,
        t.stock_resultante,
        u.username                     AS responsable,
        l.numero_lote,
        v.id                           AS venta
FROM transacciones t
INNER JOIN productos  p ON p.id = t.producto_id
INNER JOIN sucursales s ON s.id = t.sucursal_id
INNER JOIN usuarios   u ON u.id = t.usuario_id
LEFT  JOIN lotes      l ON l.id = t.lote_id
LEFT  JOIN venta_detalle d ON d.id = t.venta_detalle_id
LEFT  JOIN ventas     v ON v.id = d.venta_id
ORDER BY t.fecha DESC, t.id DESC;


/* =====================================================================
   APOYO 5 - TRANSFERENCIAS COMO DOBLE ASIENTO  (RF12)
   ===================================================================== */
SELECT  grupo_id,
        COUNT(*)                                                        AS movimientos,
        MIN(fecha)                                                      AS fecha,
        MIN(CASE WHEN tipo = 'TRANSFERENCIA_SALIDA'  THEN sucursal_id END) AS origen_id,
        MIN(CASE WHEN tipo = 'TRANSFERENCIA_INGRESO' THEN sucursal_id END) AS destino_id,
        MAX(cantidad)                                                   AS cantidad
FROM transacciones
WHERE grupo_id IS NOT NULL
GROUP BY grupo_id;

/* =====================================================================
   APOYO 6 - EXPORTACION CSV  (RF14)
   ---------------------------------------------------------------------
   SQL Server no escribe archivos desde un SELECT. Se exporta con
   sqlcmd indicando separador y UTF-8:

    sqlcmd -S .\SQLEXPRESS -E -C -d SALCI -W -s ";" -f 65001 ^
          -Q "SELECT * FROM vw_stock_en_vivo" -o stock.csv

    En PHP el CSV se genera con fopen('php://output') y BOM UTF-8, usando
    el MISMO separador ';' que esta consulta de apoyo (RF14).
    ===================================================================== */
