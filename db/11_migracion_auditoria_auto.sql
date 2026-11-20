USE SALCI;
GO
SET QUOTED_IDENTIFIER ON;
GO

/* ---------------------------------------------------------------------
   RF13 — Auditoría automática de mutaciones.

   1) registro_alertas completa las cuatro columnas de auditoría.
   2) DEFAULT en updated_at/updated_by para que el alta quede auditada.
   3) Un trigger AFTER UPDATE por tabla para que updated_at/updated_by también
      se registren cuando la escritura venga directamente de SQL Server.

   IMPORTANTE: no se usan triggers AFTER INSERT porque SQL Server prohíbe
   `INSERT ... OUTPUT INSERTED.id` sobre tablas con triggers activos, y el
   prototipo usa esa cláusula para recuperar el id generado.

   El script es idempotente: se puede volver a ejecutar.
   --------------------------------------------------------------------- */

IF COL_LENGTH('registro_alertas', 'created_at') IS NULL
   ALTER TABLE registro_alertas
   ADD created_at DATETIME2(0) NOT NULL
       CONSTRAINT df_alertas_creat DEFAULT (SYSDATETIME());
GO
IF COL_LENGTH('registro_alertas', 'updated_at') IS NULL
   ALTER TABLE registro_alertas ADD updated_at DATETIME2(0) NULL;
GO
IF COL_LENGTH('registro_alertas', 'updated_by') IS NULL
   ALTER TABLE registro_alertas ADD updated_by NVARCHAR(50) NULL;
GO

UPDATE registro_alertas
   SET updated_at = ISNULL(updated_at, fecha),
       updated_by = ISNULL(updated_by, ISNULL(created_by, 'SISTEMA'));
GO

/* Defaults de auditoría en todas las tablas que ya tienen updated_*. */
DECLARE @tabla SYSNAME, @nombre SYSNAME, @sql NVARCHAR(MAX);

DECLARE cur_cols CURSOR LOCAL FAST_FORWARD FOR
    SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS
     WHERE COLUMN_NAME = 'updated_at'
     GROUP BY TABLE_NAME;
OPEN cur_cols;
FETCH NEXT FROM cur_cols INTO @tabla;
WHILE @@FETCH_STATUS = 0
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM sys.default_constraints dc
         WHERE dc.parent_object_id = OBJECT_ID(@tabla)
           AND dc.parent_column_id = COLUMNPROPERTY(OBJECT_ID(@tabla), 'updated_at', 'ColumnId')
    )
    BEGIN
        SET @nombre = N'df_' + @tabla + N'_uat';
        SET @sql = N'ALTER TABLE ' + QUOTENAME(@tabla) + N' ADD CONSTRAINT '
                 + QUOTENAME(@nombre) + N' DEFAULT (SYSDATETIME()) FOR updated_at';
        EXEC sp_executesql @sql;
    END

    IF NOT EXISTS (
        SELECT 1 FROM sys.default_constraints dc
         WHERE dc.parent_object_id = OBJECT_ID(@tabla)
           AND dc.parent_column_id = COLUMNPROPERTY(OBJECT_ID(@tabla), 'updated_by', 'ColumnId')
    )
    BEGIN
        SET @nombre = N'df_' + @tabla + N'_uby';
        SET @sql = N'ALTER TABLE ' + QUOTENAME(@tabla) + N' ADD CONSTRAINT '
                 + QUOTENAME(@nombre) + N' DEFAULT (SUSER_SNAME()) FOR updated_by';
        EXEC sp_executesql @sql;
    END

    FETCH NEXT FROM cur_cols INTO @tabla;
END
CLOSE cur_cols;
DEALLOCATE cur_cols;
GO

/* Si una versión anterior del script creó triggers de INSERT, se retiran. */
DECLARE @trg SYSNAME, @drop NVARCHAR(MAX);
DECLARE cur_drop CURSOR LOCAL FAST_FORWARD FOR
    SELECT name FROM sys.triggers WHERE name LIKE 'trg_aud_ins_%';
OPEN cur_drop;
FETCH NEXT FROM cur_drop INTO @trg;
WHILE @@FETCH_STATUS = 0
BEGIN
    SET @drop = N'DROP TRIGGER ' + QUOTENAME(@trg);
    EXEC sp_executesql @drop;
    FETCH NEXT FROM cur_drop INTO @trg;
END
CLOSE cur_drop;
DEALLOCATE cur_drop;
GO

/* Triggers AFTER UPDATE (no interfieren con OUTPUT INSERTED.id). */
DECLARE @tabla2 SYSNAME, @pkjoin NVARCHAR(MAX), @sql2 NVARCHAR(MAX), @trigger SYSNAME;

DECLARE cur CURSOR LOCAL FAST_FORWARD FOR
    SELECT c.TABLE_NAME,
           STUFF((
               SELECT ', t.' + QUOTENAME(k.COLUMN_NAME) + ' = i.' + QUOTENAME(k.COLUMN_NAME)
                 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
                 JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
                   ON k.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
                  AND k.TABLE_NAME = tc.TABLE_NAME
                WHERE tc.CONSTRAINT_TYPE = 'PRIMARY KEY'
                  AND k.TABLE_NAME = c.TABLE_NAME
                FOR XML PATH('')
           ), 1, 1, '') AS pkjoin
      FROM INFORMATION_SCHEMA.COLUMNS c
     WHERE c.COLUMN_NAME = 'updated_at'
     GROUP BY c.TABLE_NAME;

OPEN cur;
FETCH NEXT FROM cur INTO @tabla2, @pkjoin;

WHILE @@FETCH_STATUS = 0
BEGIN
    IF @pkjoin IS NOT NULL AND @pkjoin <> ''
    BEGIN
        SET @trigger = N'trg_aud_' + @tabla2;
        SET @sql2 = N'CREATE OR ALTER TRIGGER ' + QUOTENAME(@trigger) + N'
   ON ' + QUOTENAME(@tabla2) + N'
   AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE t
       SET updated_at = SYSDATETIME(),
           updated_by = CASE WHEN UPDATE(updated_by) AND i.updated_by IS NOT NULL
                             THEN i.updated_by
                             ELSE ISNULL(t.updated_by, SUSER_SNAME())
                        END
      FROM ' + QUOTENAME(@tabla2) + N' t
      INNER JOIN inserted i ON ' + @pkjoin + N';
END';
        EXEC sp_executesql @sql2;
    END

    FETCH NEXT FROM cur INTO @tabla2, @pkjoin;
END

CLOSE cur;
DEALLOCATE cur;
GO

PRINT 'Migracion 11 aplicada: RF13 automatico (defaults de alta + triggers AFTER UPDATE).';
GO
