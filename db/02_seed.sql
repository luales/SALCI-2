/* =====================================================================
   PROYECTO SALCI - Script 02: Datos de demostracion
   ---------------------------------------------------------------------
   ADVERTENCIA: este script BORRA todos los datos de SALCI y carga el
   set de demo. No ejecutar sobre informacion real.

   Usuarios demo:   admin / supervisor / cajero.chillan /
                    cajero.conce / cajero.santiago
   Clave:           Salci2026!
   Hash:            SHA-256( salt_binario || password_utf8 )  en hex

   Ejecutar:  sqlcmd -S .\SQLEXPRESS -E -C -f 65001 -i db\02_seed.sql
   ===================================================================== */

USE SALCI;
GO

SET NOCOUNT ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_NULLS ON;
GO

/* ---------------------------------------------------------------------
   1. Limpieza (de hijos a padres)
   --------------------------------------------------------------------- */
DELETE FROM transacciones;
DELETE FROM registro_alertas;
DELETE FROM despachos_pendientes;
DELETE FROM venta_detalle;
DELETE FROM ventas;
DELETE FROM lotes;
DELETE FROM inventario;
DELETE FROM compradores;
DELETE FROM proveedores;
DELETE FROM productos;
DELETE FROM usuarios;
DELETE FROM sucursales;
GO

/* ---------------------------------------------------------------------
   2. Maestros
   Las sucursales usan IDs fijos (1,2,3) porque el resto del seed las
   referencia. El resto de las tablas usa subconsultas por clave natural.
   --------------------------------------------------------------------- */
SET IDENTITY_INSERT sucursales ON;
INSERT INTO sucursales (id, nombre, direccion, activa, created_by) VALUES
 (1, 'Chillán',    'Av. Argentina 100',     1, 'seed'),
 (2, 'Concepción', 'O''Higgins 200',        1, 'seed'),
 (3, 'Santiago',   'Av. Providencia 1500',  1, 'seed');
SET IDENTITY_INSERT sucursales OFF;

INSERT INTO usuarios (username, password_hash, salt, rol, sucursal_id, activo, created_by) VALUES
 ('admin',           'f3e3871cb0f085792afd9c8a9a1dc923b2291e979177468b854875d72964a01c', '0516ca0726e162878adccd1a37832e31', 'ADMIN',    NULL, 1, 'seed'),
 ('supervisor',      'd065dbcf99ed1cdb621e1093e90bec9c6a6e9034c10f385ecd8c8d189e361bf0', '615f24ca1dfa14932b70cb5cbc01e628', 'ADMIN',    1,    1, 'seed'),
 ('cajero.chillan',  '662297acef74683c0439376995886b97e5ba697a2301dc0f40cb2db93cff4b53', 'fe0159aa8edf8741ffe3fd4fdb541666', 'OPERADOR', 1,    1, 'seed'),
 ('cajero.conce',    '45f6020ce39df18dfb09e5ea88b56e4d9450c8fd3797d11771b4cfba093afab1', '1268512fb865356c39dab0ea9aea79cd', 'OPERADOR', 2,    1, 'seed'),
 ('cajero.santiago', 'b760297ff887ead1e770b012ab2f8c98430fea8e2adcfd97f706dd13082eadb0', 'ea1a4c4165bde106175e527abbe00036', 'OPERADOR', 3,    1, 'seed');

INSERT INTO productos (sku, nombre, precio, stock_minimo, created_by) VALUES
 ('HAR-001', 'Harina 1 kg',  1290, 10, 'seed'),
 ('ACE-001', 'Aceite 1 L',   2190,  8, 'seed'),
 ('ARR-001', 'Arroz 1 kg',   1490, 12, 'seed'),
 ('AZU-001', 'Azúcar 1 kg',  1190, 10, 'seed'),
 ('FID-001', 'Fideos 500 g',  890, 15, 'seed'),
 ('SAL-001', 'Sal 1 kg',      690,  6, 'seed'),
 ('CAF-001', 'Café 250 g',   3590, 12, 'seed'),
 ('LEC-001', 'Leche 1 L',    1090, 20, 'seed');

INSERT INTO proveedores (rut, nombre, telefono, email, created_by) VALUES
 ('76.111.222-3', 'Molinos del Sur',       '+56 41 234 5678', 'ventas@molinosdelsur.cl',   'seed'),
 ('77.222.333-4', 'Distribuidora Central', '+56 41 345 6789', 'contacto@distcentral.cl',   'seed'),
 ('78.333.444-5', 'AgroAndes',             '+56 22 456 7890', 'pedidos@agroandes.cl',      'seed');

INSERT INTO compradores (rut, nombre, telefono, email, direccion, created_by) VALUES
 ('12.345.678-9', 'María Soto',    '+56 9 8765 4321', 'maria.soto@correo.cl',  'Av. Argentina 150, Chillán',   'seed'),
 ('13.456.789-0', 'Pedro Rojas',   '+56 9 7654 3210', 'pedro.rojas@correo.cl', 'Los Carrera 45, Concepción',   'seed'),
 ('14.567.890-1', 'Carla Muñoz',   '+56 9 6543 2109', 'carla.munoz@correo.cl', 'Providencia 900, Santiago',    'seed'),
 ('15.678.901-2', 'Diego Fuentes', '+56 9 5432 1098', 'diego.fuentes@correo.cl','Av. Brasil 210, Chillán',       'seed');
GO

/* ---------------------------------------------------------------------
   3. Ingresos (lotes)  ->  RF04
   --------------------------------------------------------------------- */
DECLARE @har INT = (SELECT id FROM productos WHERE sku = 'HAR-001');
DECLARE @ace INT = (SELECT id FROM productos WHERE sku = 'ACE-001');
DECLARE @arr INT = (SELECT id FROM productos WHERE sku = 'ARR-001');
DECLARE @azu INT = (SELECT id FROM productos WHERE sku = 'AZU-001');
DECLARE @fid INT = (SELECT id FROM productos WHERE sku = 'FID-001');
DECLARE @sal INT = (SELECT id FROM productos WHERE sku = 'SAL-001');
DECLARE @caf INT = (SELECT id FROM productos WHERE sku = 'CAF-001');
DECLARE @lec INT = (SELECT id FROM productos WHERE sku = 'LEC-001');

DECLARE @p1 INT = (SELECT id FROM proveedores WHERE rut = '76.111.222-3');
DECLARE @p2 INT = (SELECT id FROM proveedores WHERE rut = '77.222.333-4');
DECLARE @p3 INT = (SELECT id FROM proveedores WHERE rut = '78.333.444-5');

INSERT INTO lotes (producto_id, proveedor_id, numero_lote, cantidad, sucursal_id, fecha_ingreso, created_by) VALUES
 (@har, @p1, 'L-HAR-1102',  60, 1, '2026-11-02T08:00:00', 'supervisor'),
 (@ace, @p2, 'L-ACE-1102',  40, 1, '2026-11-02T08:30:00', 'supervisor'),
 (@arr, @p1, 'L-ARR-1102',  50, 1, '2026-11-02T09:00:00', 'supervisor'),
 (@fid, @p2, 'L-FID-1103',  30, 1, '2026-11-03T09:00:00', 'supervisor'),
 (@har, @p1, 'L-HAR-1103',  40, 2, '2026-11-03T10:00:00', 'supervisor'),
 (@sal, @p3, 'L-SAL-1104',  20, 1, '2026-11-04T08:00:00', 'supervisor'),
 (@lec, @p3, 'L-LEC-1104',  36, 3, '2026-11-04T11:00:00', 'supervisor'),
 (@caf, @p3, 'L-CAF-1104',  12, 2, '2026-11-04T12:00:00', 'supervisor'),
 (@lec, @p3, 'L-LEC-1104B', 48, 1, '2026-11-04T13:00:00', 'supervisor'),
 (@azu, @p2, 'L-AZU-1104',  30, 1, '2026-11-04T14:00:00', 'supervisor'),
 (@ace, @p2, 'L-ACE-1104',  14, 2, '2026-11-04T15:00:00', 'supervisor'),
 (@azu, @p2, 'L-AZU-1104B', 20, 3, '2026-11-04T16:00:00', 'supervisor'),
 (@arr, @p1, 'L-ARR-1104',  30, 2, '2026-11-04T17:00:00', 'supervisor');
GO

/* ---------------------------------------------------------------------
   4. Ventas POS  ->  RF15
   (los totales se calculan al final desde el detalle)
   --------------------------------------------------------------------- */
DECLARE @c1 INT = (SELECT id FROM compradores WHERE rut = '12.345.678-9');
DECLARE @c2 INT = (SELECT id FROM compradores WHERE rut = '13.456.789-0');
DECLARE @c3 INT = (SELECT id FROM compradores WHERE rut = '14.567.890-1');
DECLARE @c4 INT = (SELECT id FROM compradores WHERE rut = '15.678.901-2');

DECLARE @u_ch INT = (SELECT id FROM usuarios WHERE username = 'cajero.chillan');
DECLARE @u_co INT = (SELECT id FROM usuarios WHERE username = 'cajero.conce');
DECLARE @u_st INT = (SELECT id FROM usuarios WHERE username = 'cajero.santiago');

SET IDENTITY_INSERT ventas ON;
INSERT INTO ventas (id, sucursal_id, comprador_id, usuario_id, fecha, estado, created_by) VALUES
 (1, 1, @c1, @u_ch, '2026-11-05T10:15:00', 'COMPLETADA', 'cajero.chillan'),
 (2, 1, NULL, @u_ch, '2026-11-05T11:40:00', 'COMPLETADA', 'cajero.chillan'),
 (3, 2, @c2, @u_co, '2026-11-06T09:30:00', 'COMPLETADA', 'cajero.conce'),
 (4, 3, @c3, @u_st, '2026-11-06T16:00:00', 'COMPLETADA', 'cajero.santiago'),
 (5, 1, @c1, @u_ch, '2026-11-07T12:00:00', 'COMPLETADA', 'cajero.chillan'),
 (6, 2, NULL, @u_co, '2026-11-08T10:00:00', 'COMPLETADA', 'cajero.conce'),
 (7, 1, @c4, @u_ch, '2026-11-09T17:30:00', 'COMPLETADA', 'cajero.chillan');
SET IDENTITY_INSERT ventas OFF;

DECLARE @har INT = (SELECT id FROM productos WHERE sku = 'HAR-001');
DECLARE @ace INT = (SELECT id FROM productos WHERE sku = 'ACE-001');
DECLARE @arr INT = (SELECT id FROM productos WHERE sku = 'ARR-001');
DECLARE @azu INT = (SELECT id FROM productos WHERE sku = 'AZU-001');
DECLARE @fid INT = (SELECT id FROM productos WHERE sku = 'FID-001');
DECLARE @sal INT = (SELECT id FROM productos WHERE sku = 'SAL-001');
DECLARE @caf INT = (SELECT id FROM productos WHERE sku = 'CAF-001');
DECLARE @lec INT = (SELECT id FROM productos WHERE sku = 'LEC-001');

INSERT INTO venta_detalle (venta_id, producto_id, cantidad, precio_unitario) VALUES
 (1, @har, 6, 1290), (1, @ace, 2, 2190), (1, @fid, 3, 890),
 (2, @azu, 4, 1190), (2, @sal, 2, 690),
 (3, @har, 8, 1290), (3, @arr, 5, 1490),
 (4, @lec, 6, 1090), (4, @azu, 3, 1190),
 (5, @har, 10, 1290), (5, @lec, 5, 1090), (5, @ace, 4, 2190),
 (6, @ace, 6, 2190), (6, @caf, 2, 3590),
 (7, @arr, 7, 1490), (7, @fid, 5, 890), (7, @sal, 3, 690);
GO

/* ---------------------------------------------------------------------
   5. Kardex de movimientos  ->  RF01, RF02, RF12, RF13
   Se insertan con stock_resultante = 0 y luego se recalcula el saldo
   acumulado real, para que el kardex y el inventario nunca difieran.
   --------------------------------------------------------------------- */
DECLARE @u_sup INT = (SELECT id FROM usuarios WHERE username = 'supervisor');
DECLARE @har   INT = (SELECT id FROM productos WHERE sku = 'HAR-001');

-- 6.1 Ingresos (uno por lote)
INSERT INTO transacciones (tipo, producto_id, sucursal_id, cantidad, stock_resultante, lote_id, usuario_id, fecha)
SELECT 'INGRESO', l.producto_id, l.sucursal_id, l.cantidad, 0, l.id, @u_sup, l.fecha_ingreso
FROM lotes l;

-- 6.2 Salidas por venta (una por linea de detalle)
INSERT INTO transacciones (tipo, producto_id, sucursal_id, cantidad, stock_resultante, venta_detalle_id, usuario_id, fecha)
SELECT 'SALIDA', d.producto_id, v.sucursal_id, d.cantidad, 0, d.id, v.usuario_id,
       DATEADD(MINUTE, d.id, v.fecha)
FROM venta_detalle d
INNER JOIN ventas v ON v.id = d.venta_id;

-- 6.3 Transferencia inter-sucursal (doble asiento, mismo grupo)
DECLARE @grupo UNIQUEIDENTIFIER = NEWID();
INSERT INTO transacciones (tipo, grupo_id, producto_id, sucursal_id, cantidad, stock_resultante, usuario_id, fecha) VALUES
 ('TRANSFERENCIA_SALIDA',  @grupo, @har, 1, 5, 0, @u_sup, '2026-11-10T09:00:00'),
 ('TRANSFERENCIA_INGRESO', @grupo, @har, 2, 5, 0, @u_sup, '2026-11-10T09:00:00');

-- 6.4 Ajuste por merma
INSERT INTO transacciones (tipo, producto_id, sucursal_id, cantidad, stock_resultante, usuario_id, fecha) VALUES
 ('AJUSTE', @har, 1, 2, 0, @u_sup, '2026-11-11T18:00:00');

-- 6.5 Recalcular el saldo acumulado de cada movimiento
;WITH mov AS (
    SELECT id, producto_id, sucursal_id, fecha,
           CASE WHEN tipo IN ('SALIDA','TRANSFERENCIA_SALIDA','AJUSTE')
                THEN -cantidad ELSE cantidad END AS delta
    FROM transacciones
), calc AS (
    SELECT id,
           SUM(delta) OVER (PARTITION BY producto_id, sucursal_id
                            ORDER BY fecha, id
                            ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS saldo
    FROM mov
)
UPDATE t
SET stock_resultante = c.saldo
FROM transacciones t
INNER JOIN calc c ON c.id = t.id;
GO

/* ---------------------------------------------------------------------
   6. Inventario resultante (derivado del kardex)
   --------------------------------------------------------------------- */
INSERT INTO inventario (producto_id, sucursal_id, stock, created_by, updated_by)
SELECT producto_id, sucursal_id, SUM(delta), 'seed', 'seed'
FROM (
    SELECT producto_id, sucursal_id,
           CASE WHEN tipo IN ('SALIDA','TRANSFERENCIA_SALIDA','AJUSTE')
                THEN -cantidad ELSE cantidad END AS delta
    FROM transacciones
) x
GROUP BY producto_id, sucursal_id
HAVING SUM(delta) > 0;
GO

/* ---------------------------------------------------------------------
   7. Totales de venta (derivados del detalle)
   --------------------------------------------------------------------- */
;WITH tot AS (
    SELECT venta_id, SUM(subtotal) AS subtotal
    FROM venta_detalle
    GROUP BY venta_id
)
UPDATE v
SET subtotal = t.subtotal,
    iva      = ROUND(t.subtotal * 0.19, 2),
    total    = t.subtotal + ROUND(t.subtotal * 0.19, 2)
FROM ventas v
INNER JOIN tot t ON t.venta_id = v.id;
GO

/* ---------------------------------------------------------------------
   8. Alertas de stock critico (generadas desde el estado real)
   --------------------------------------------------------------------- */
INSERT INTO registro_alertas (producto_id, sucursal_id, fecha, mensaje, stock_al_momento, leida, created_by)
SELECT i.producto_id, i.sucursal_id, '2026-11-09T18:00:00',
       CONCAT('Stock critico: ', i.stock, ' unidades (minimo ', p.stock_minimo, ')'),
       i.stock, 0, 'SISTEMA'
FROM inventario i
INNER JOIN productos p ON p.id = i.producto_id
WHERE i.stock <= p.stock_minimo;

-- Una alerta ya fue revisada, para mostrar el contador de no leidas
UPDATE registro_alertas SET leida = 1
WHERE id = (SELECT MIN(id) FROM registro_alertas);
GO

/* ---------------------------------------------------------------------
   9. Ordenes de despacho  ->  RF09
   --------------------------------------------------------------------- */
DECLARE @har INT = (SELECT id FROM productos WHERE sku = 'HAR-001');
DECLARE @lec INT = (SELECT id FROM productos WHERE sku = 'LEC-001');
DECLARE @c1  INT = (SELECT id FROM compradores WHERE rut = '12.345.678-9');
DECLARE @c2  INT = (SELECT id FROM compradores WHERE rut = '13.456.789-0');
DECLARE @c3  INT = (SELECT id FROM compradores WHERE rut = '14.567.890-1');

INSERT INTO despachos_pendientes
 (producto_id, sucursal_id, comprador_id, venta_id, destino, cantidad, estado, fecha_creacion, fecha_entrega, created_by) VALUES
 (@har, 1, @c1, 1, 'Av. Argentina 150, Chillán',   6, 'PENDIENTE',  '2026-11-05T10:20:00', NULL,                  'cajero.chillan'),
 (@lec, 3, @c3, 4, 'Providencia 900, Santiago',    6, 'EN_RUTA',    '2026-11-06T16:05:00', NULL,                  'cajero.santiago'),
 (@har, 2, @c2, 3, 'Los Carrera 45, Concepción',   8, 'COMPLETADO', '2026-11-06T09:35:00', '2026-11-06T15:00:00', 'cajero.conce');
GO

/* ---------------------------------------------------------------------
   10. Verificacion
   --------------------------------------------------------------------- */
SELECT 'sucursales' AS tabla, COUNT(*) AS filas FROM sucursales
UNION ALL SELECT 'usuarios',            COUNT(*) FROM usuarios
UNION ALL SELECT 'productos',           COUNT(*) FROM productos
UNION ALL SELECT 'inventario',          COUNT(*) FROM inventario
UNION ALL SELECT 'proveedores',         COUNT(*) FROM proveedores
UNION ALL SELECT 'compradores',         COUNT(*) FROM compradores
UNION ALL SELECT 'lotes',               COUNT(*) FROM lotes
UNION ALL SELECT 'ventas',              COUNT(*) FROM ventas
UNION ALL SELECT 'venta_detalle',       COUNT(*) FROM venta_detalle
UNION ALL SELECT 'transacciones',       COUNT(*) FROM transacciones
UNION ALL SELECT 'despachos_pendientes',COUNT(*) FROM despachos_pendientes
UNION ALL SELECT 'registro_alertas',    COUNT(*) FROM registro_alertas;

-- Control de cuadratura: inventario vs. kardex (debe dar 0 diferencias)
SELECT COUNT(*) AS diferencias_inventario_kardex
FROM (
    SELECT i.producto_id, i.sucursal_id, i.stock,
           (SELECT SUM(CASE WHEN t.tipo IN ('SALIDA','TRANSFERENCIA_SALIDA','AJUSTE')
                            THEN -t.cantidad ELSE t.cantidad END)
            FROM transacciones t
            WHERE t.producto_id = i.producto_id
              AND t.sucursal_id = i.sucursal_id) AS kardex
    FROM inventario i
) c
WHERE c.stock <> c.kardex;

-- Control: total de venta = suma del detalle + IVA
SELECT COUNT(*) AS diferencias_totales_venta
FROM ventas v
WHERE v.subtotal <> (SELECT SUM(d.subtotal) FROM venta_detalle d WHERE d.venta_id = v.id);

-- Stock critico resultante
SELECT p.sku, s.nombre AS sucursal, i.stock, p.stock_minimo
FROM inventario i
JOIN productos p ON p.id = i.producto_id
JOIN sucursales s ON s.id = i.sucursal_id
WHERE i.stock <= p.stock_minimo
ORDER BY s.nombre, p.sku;
GO
