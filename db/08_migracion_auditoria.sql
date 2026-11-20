USE SALCI;
GO
SET QUOTED_IDENTIFIER ON;
GO
IF COL_LENGTH('lotes','updated_at') IS NULL ALTER TABLE lotes ADD updated_at DATETIME2(0) NULL;
GO
IF COL_LENGTH('lotes','updated_by') IS NULL ALTER TABLE lotes ADD updated_by NVARCHAR(50) NULL;
GO
IF COL_LENGTH('venta_detalle','created_at') IS NULL
   ALTER TABLE venta_detalle ADD created_at DATETIME2(0) NOT NULL CONSTRAINT df_vd_creat DEFAULT (SYSDATETIME()), created_by NVARCHAR(50) NULL, updated_at DATETIME2(0) NULL, updated_by NVARCHAR(50) NULL;
GO
IF COL_LENGTH('transacciones','created_at') IS NULL
   ALTER TABLE transacciones ADD created_at DATETIME2(0) NOT NULL CONSTRAINT df_tx_creat DEFAULT (SYSDATETIME()), created_by NVARCHAR(50) NULL, updated_at DATETIME2(0) NULL, updated_by NVARCHAR(50) NULL;
GO

UPDATE t
   SET t.created_at = t.fecha,
       t.created_by = ISNULL(u.username, 'SISTEMA'),
       t.updated_at = t.fecha,
       t.updated_by = ISNULL(u.username, 'SISTEMA')
  FROM transacciones t
  LEFT JOIN usuarios u ON u.id = t.usuario_id
 WHERE t.created_by IS NULL;
GO

UPDATE d
   SET d.created_at = v.fecha,
       d.created_by = ISNULL(u.username, 'SISTEMA'),
       d.updated_at = v.fecha,
       d.updated_by = ISNULL(u.username, 'SISTEMA')
  FROM venta_detalle d
  JOIN ventas v ON v.id = d.venta_id
  LEFT JOIN usuarios u ON u.id = v.usuario_id
 WHERE d.created_by IS NULL;
GO

UPDATE l
   SET l.updated_at = l.created_at,
       l.updated_by = ISNULL(l.created_by, 'SISTEMA')
  FROM lotes l
 WHERE l.updated_at IS NULL;
GO

PRINT 'Migracion 08 aplicada: auditoria completa en lotes, venta_detalle y transacciones.';
GO
