/* =====================================================================
   PROYECTO SALCI - Migracion 06: baja logica de productos
   ---------------------------------------------------------------------
   Agrega productos.activo para dar de baja un producto sin borrar su
   historial de ventas, lotes ni movimientos. Idempotente.

   Ejecutar:
     sqlcmd -S .\SQLEXPRESS -E -C -f 65001 -i db\06_migracion_productos_activo.sql
   ===================================================================== */

USE SALCI;
GO

IF COL_LENGTH('dbo.productos', 'activo') IS NULL
BEGIN
    ALTER TABLE dbo.productos
        ADD activo BIT NOT NULL
            CONSTRAINT df_productos_activo DEFAULT (1);

    PRINT 'Columna productos.activo agregada.';
END;
ELSE
BEGIN
    PRINT 'Columna productos.activo ya existe.';
END;
GO

UPDATE dbo.productos SET activo = 1 WHERE activo IS NULL;
GO
