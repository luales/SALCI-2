/* =====================================================================
   SALCI — Verificación objetiva de RNF02 y RNF05
   ---------------------------------------------------------------------
   RNF02 (fiabilidad): SQL Server no tiene PRAGMA foreign_keys; las claves
   foráneas nativas equivalen a esa directiva y deben estar activas
   (is_disabled = 0) y confiables (is_not_trusted = 0). También se revisan
   los CHECK. op_tx() en operaciones.php garantiza la atomicidad.

   RNF05 (eficiencia): los índices clustered (1) y nonclustered (2) de SQL
   Server son B-Tree. Se comprueba que existan sobre sku, username y
   sucursal_id, las columnas de alta selectividad del informe.

   Si tu base no se llama SALCI, reemplaza USE SALCI; por USE <tu-base>; en
   una copia temporal. verificar_rnf.ps1 ya lo hace automáticamente.
     $server = '.\SQLEXPRESS'
     $db     = 'SALCI'
     $sql = (Get-Content db\10_verificacion_rnf.sql -Raw).Replace('USE SALCI;', "USE $db;")
     $sql | Set-Content $env:TEMP\rnf.sql -Encoding utf8
     sqlcmd -S $server -E -C -b -f 65001 -i $env:TEMP\rnf.sql
   ===================================================================== */

USE SALCI;
GO

SET NOCOUNT ON;
GO

WITH idx AS (
    SELECT
        OBJECT_NAME(i.object_id)                   AS tabla,
        COL_NAME(ic.object_id, ic.column_id)       AS columna,
        i.name                                     AS indice,
        CASE i.type WHEN 1 THEN 'CLUSTERED' ELSE 'NONCLUSTERED' END AS tipo,
        i.is_unique,
        ic.key_ordinal
    FROM sys.indexes i
    JOIN sys.index_columns ic
      ON ic.object_id = i.object_id
     AND ic.index_id  = i.index_id
    WHERE i.type IN (1, 2)
      AND i.is_hypothetical = 0
      AND i.object_id > 0
)
SELECT 'RNF02' AS requisito,
       'Claves foráneas activas y confiables' AS verificacion,
       CASE WHEN COUNT(*) = 0 THEN 'OK' ELSE 'REVISAR' END AS resultado,
       COUNT(*) AS hallazgos
FROM sys.foreign_keys
WHERE is_disabled = 1 OR is_not_trusted = 1

UNION ALL

SELECT 'RNF02',
       'Restricciones CHECK activas',
       CASE WHEN COUNT(*) = 0 THEN 'OK' ELSE 'REVISAR' END,
       COUNT(*)
FROM sys.check_constraints
WHERE is_disabled = 1 OR is_not_trusted = 1

UNION ALL

SELECT 'RNF05', 'Índice B-Tree sobre productos.sku',
       CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'REVISAR' END, COUNT(*)
FROM idx WHERE tabla = 'productos' AND columna = 'sku'

UNION ALL

SELECT 'RNF05', 'Índice B-Tree sobre usuarios.username',
       CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'REVISAR' END, COUNT(*)
FROM idx WHERE tabla = 'usuarios' AND columna = 'username'

UNION ALL

SELECT 'RNF05', 'Índice B-Tree sobre inventario.sucursal_id',
       CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'REVISAR' END, COUNT(*)
FROM idx WHERE tabla = 'inventario' AND columna = 'sucursal_id'

UNION ALL

SELECT 'RNF05', 'Índices B-Tree sobre cualquier sucursal_id',
       CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'REVISAR' END, COUNT(*)
FROM idx WHERE columna = 'sucursal_id' AND key_ordinal = 1;
GO

/* Evidencia detallada de los índices exigidos. */
WITH idx AS (
    SELECT
        OBJECT_NAME(i.object_id)             AS tabla,
        COL_NAME(ic.object_id, ic.column_id) AS columna,
        i.name                               AS indice,
        CASE i.type WHEN 1 THEN 'CLUSTERED' ELSE 'NONCLUSTERED' END AS tipo,
        i.is_unique
    FROM sys.indexes i
    JOIN sys.index_columns ic
      ON ic.object_id = i.object_id
     AND ic.index_id  = i.index_id
    WHERE i.type IN (1, 2)
      AND i.is_hypothetical = 0
      AND i.object_id > 0
)
SELECT tabla, columna, indice, tipo,
       CASE WHEN is_unique = 1 THEN 'UNIQUE' ELSE 'NO UNIQUE' END AS unicidad
FROM idx
WHERE (tabla = 'productos'  AND columna = 'sku')
   OR (tabla = 'usuarios'    AND columna = 'username')
   OR (tabla = 'inventario'  AND columna = 'sucursal_id')
   OR (tabla = 'ventas'      AND columna = 'sucursal_id')
   OR (tabla = 'transacciones' AND columna = 'sucursal_id')
ORDER BY tabla, columna, indice;
GO

/* Conteo total de FK y CHECK como evidencia de integridad nativa. */
SELECT
    (SELECT COUNT(*) FROM sys.foreign_keys)   AS foreign_keys_totales,
    (SELECT COUNT(*) FROM sys.check_constraints) AS checks_totales;
GO
