/* =====================================================================
   PROYECTO SALCI - Script 04: Respaldo y restauracion
   ---------------------------------------------------------------------
   Genera un respaldo completo (.bak) en la carpeta por defecto de la
   instancia y verifica que el archivo se pueda leer.

   Ejecutar:  sqlcmd -S .\SQLEXPRESS -E -C -i db\04_backup.sql
   ===================================================================== */

USE master;
GO

DECLARE @ruta NVARCHAR(400) =
    N'C:\Program Files\Microsoft SQL Server\MSSQL17.SQLEXPRESS\MSSQL\Backup\SALCI.bak';

PRINT 'Respaldando en: ' + @ruta;

BEGIN TRY
    /* SQL Server Express no soporta COMPRESSION */
    BACKUP DATABASE SALCI
    TO DISK = @ruta
    WITH INIT, CHECKSUM, STATS = 10;

    PRINT 'Verificando el respaldo...';

    RESTORE VERIFYONLY
    FROM DISK = @ruta
    WITH CHECKSUM;

    PRINT 'Respaldo generado y verificado correctamente.';
END TRY
BEGIN CATCH
    PRINT 'ERROR al respaldar: ' + ERROR_MESSAGE();
    THROW;
END CATCH;
GO

/* ---------------------------------------------------------------------
   RESTAURACION (ejecutar solo si se necesita volver al respaldo)
   ---------------------------------------------------------------------
   USE master;
   GO

   -- Cerrar conexiones activas sobre la base
   ALTER DATABASE SALCI SET SINGLE_USER WITH ROLLBACK IMMEDIATE;
   GO

   RESTORE DATABASE SALCI
   FROM DISK = N'C:\Program Files\Microsoft SQL Server\MSSQL17.SQLEXPRESS\MSSQL\Backup\SALCI.bak'
   WITH REPLACE, RECOVERY, STATS = 10;
   GO

   ALTER DATABASE SALCI SET MULTI_USER;
   GO
   --------------------------------------------------------------------- */
