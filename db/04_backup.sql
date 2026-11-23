/* =====================================================================
   PROYECTO SALCI - Script 04: Respaldo y restauracion
   ---------------------------------------------------------------------
   Genera un respaldo completo (.bak) en la carpeta por defecto de la
   instancia y verifica que el archivo se pueda leer.

   Ejecutar:  sqlcmd -S .\SQLEXPRESS -E -C -i db\04_backup.sql
   ===================================================================== */

USE master;
GO

/* La ruta se deriva de la instancia para no depender del nombre MSSQL17.
   SERVERPROPERTY no garantiza la barra final, por eso se agrega solo si falta. */
DECLARE @dir NVARCHAR(400) = CAST(SERVERPROPERTY('InstanceDefaultBackupPath') AS NVARCHAR(400));
DECLARE @ruta NVARCHAR(400) =
    @dir + CASE WHEN RIGHT(@dir, 1) = N'\' THEN N'' ELSE N'\' END + N'SALCI.bak';

/* Si tu base no se llama SALCI, reemplaza el token SALCI por el nombre real
   en una copia temporal de este archivo (ver README sección 8). La ruta del
   .bak se deriva de la instancia, no de una carpeta fija. */

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

   -- @ruta se redeclara en este lote: la variable del BACKUP ya no existe.
   DECLARE @dir_r NVARCHAR(400) = CAST(SERVERPROPERTY('InstanceDefaultBackupPath') AS NVARCHAR(400));
   DECLARE @ruta NVARCHAR(400) =
       @dir_r + CASE WHEN RIGHT(@dir_r, 1) = N'\' THEN N'' ELSE N'\' END + N'SALCI.bak';

   RESTORE DATABASE SALCI
   FROM DISK = @ruta
   WITH REPLACE, RECOVERY, STATS = 10;
   GO

   ALTER DATABASE SALCI SET MULTI_USER;
   GO
   --------------------------------------------------------------------- */
