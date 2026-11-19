<?php
declare(strict_types=1);

/* ---------------------------------------------------------------------
   Configuración general del prototipo
   ---------------------------------------------------------------------
   Los valores se pueden sobreescribir sin tocar este archivo:
   copiar config.local.php.example a config.local.php y definir ahí
   las constantes. config.local.php está en .gitignore.
   --------------------------------------------------------------------- */

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    require $local;
}

defined('APP_NAME')  || define('APP_NAME', 'SALCI');
defined('APP_DEBUG') || define('APP_DEBUG', true);   // en producción: false

/* Conexión a SQL Server Express por shared memory (mismo equipo).
   Si se conecta desde otro PC, habilitar TCP/IP y usar
   Server=192.168.x.x,1433 (o Server=NOMBRE_PC\SQLEXPRESS).
   PDO_SQLSRV siempre trabaja en UTF-8: no se debe indicar
   CharacterSet en el DSN (lo rechaza). */
defined('DB_DSN')  || define('DB_DSN', 'sqlsrv:Server=.\\SQLEXPRESS;Database=SALCI;TrustServerCertificate=1');
defined('DB_USER') || define('DB_USER', null);       // null = autenticación de Windows
defined('DB_PASS') || define('DB_PASS', null);

defined('SESSION_NAME') || define('SESSION_NAME', 'salci_sid');
