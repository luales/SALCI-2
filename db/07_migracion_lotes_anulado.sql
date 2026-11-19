/* =====================================================================
   PROYECTO SALCI - Migracion 07: anulacion de ingresos (RF04)
   ---------------------------------------------------------------------
   Agrega lotes.anulado para dar de baja un ingreso sin borrar el lote
   (las transacciones del kardex apuntan a él). Idempotente.

   Ejecutar:
     sqlcmd -S .\SQLEXPRESS -E -C -f 65001 -i db\07_migracion_lotes_anulado.sql
   ===================================================================== */

USE SALCI;
GO

IF COL_LENGTH('dbo.lotes', 'anulado') IS NULL
BEGIN
    ALTER TABLE dbo.lotes
        ADD anulado BIT NOT NULL
            CONSTRAINT df_lotes_anulado DEFAULT (0);

    PRINT 'Columna lotes.anulado agregada.';
END;
ELSE
BEGIN
    PRINT 'Columna lotes.anulado ya existe.';
END;
GO

UPDATE dbo.lotes SET anulado = 0 WHERE anulado IS NULL;
GO
