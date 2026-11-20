USE SALCI;
GO
SET QUOTED_IDENTIFIER ON;
GO

/* ---------------------------------------------------------------------
   Migracion 09: RF04/RF15 - las reversas AJUSTE pueden conservar el
   documento de origen (lote o detalle de venta), pero nunca ambos.
   --------------------------------------------------------------------- */
IF OBJECT_ID(N'ck_transacciones_origen', N'C') IS NOT NULL
   ALTER TABLE transacciones DROP CONSTRAINT ck_transacciones_origen;
GO

ALTER TABLE transacciones WITH CHECK ADD CONSTRAINT ck_transacciones_origen CHECK (
       (tipo = 'INGRESO' AND venta_detalle_id IS NULL)
    OR (tipo = 'SALIDA'  AND venta_detalle_id IS NOT NULL)
    OR (tipo IN ('TRANSFERENCIA_SALIDA','TRANSFERENCIA_INGRESO')
        AND lote_id IS NULL AND venta_detalle_id IS NULL)
    OR (tipo = 'AJUSTE'  AND (lote_id IS NULL OR venta_detalle_id IS NULL))
);
GO

ALTER TABLE transacciones WITH CHECK CHECK CONSTRAINT ck_transacciones_origen;
GO

PRINT 'Migracion 09 aplicada: AJUSTE puede ligar lote o venta, no ambos.';
GO
