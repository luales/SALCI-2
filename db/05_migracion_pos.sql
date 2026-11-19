/* =====================================================================
   PROYECTO SALCI - Script 05: migracion para la venta POS (RF15)
   ---------------------------------------------------------------------
   Agrega el precio de venta al catalogo de productos.

   Por que en PRODUCTOS y no en VENTA_DETALLE:
     - El precio de lista es un atributo del producto (depende solo de su id).
     - VENTA_DETALLE.precio_unitario conserva el precio historico al momento
       de la venta, para que un cambio futuro de precio no altere boletas
       antiguas. Ambos datos son necesarios y no se duplican.

   Es idempotente: se puede ejecutar sobre una base v3 ya existente.
   Para instalaciones nuevas, 01_schema.sql ya trae la columna.

   Ejecutar:  sqlcmd -S .\SQLEXPRESS -E -C -i db\05_migracion_pos.sql
   ===================================================================== */

USE SALCI;
GO

IF COL_LENGTH('productos', 'precio') IS NULL
BEGIN
    ALTER TABLE productos
        ADD precio DECIMAL(12,2) NOT NULL
            CONSTRAINT df_productos_precio DEFAULT (0);
    PRINT 'Columna productos.precio agregada.';
END
ELSE
    PRINT 'La columna productos.precio ya existe.';
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE name = 'ck_productos_precio'
      AND parent_object_id = OBJECT_ID('productos')
)
BEGIN
    ALTER TABLE productos
        ADD CONSTRAINT ck_productos_precio CHECK (precio >= 0);
END;
GO

/* Precios de demostracion (solo completa los que estan en 0). */
UPDATE productos
SET precio = CASE sku
    WHEN 'HAR-001' THEN 1290
    WHEN 'ACE-001' THEN 2190
    WHEN 'ARR-001' THEN 1490
    WHEN 'AZU-001' THEN 1190
    WHEN 'FID-001' THEN  890
    WHEN 'SAL-001' THEN  690
    WHEN 'CAF-001' THEN 3590
    WHEN 'LEC-001' THEN 1090
    ELSE precio
END
WHERE precio = 0;
GO

SELECT sku, nombre, precio, stock_minimo
FROM productos
ORDER BY sku;
GO
