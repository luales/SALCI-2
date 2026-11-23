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
defined('APP_DEBUG') || define('APP_DEBUG', false);  // true solo en desarrollo, vía config.local.php

/* Conexión a SQL Server Express por shared memory (mismo equipo).
   Si se conecta desde otro PC, habilitar TCP/IP y usar
   Server=192.168.x.x,1433 (o Server=NOMBRE_PC\SQLEXPRESS).
   PDO_SQLSRV siempre trabaja en UTF-8: no se debe indicar
   CharacterSet en el DSN (lo rechaza). */
defined('DB_DSN')  || define('DB_DSN', 'sqlsrv:Server=.\\SQLEXPRESS;Database=SALCI;TrustServerCertificate=1');
defined('DB_USER') || define('DB_USER', null);       // null = autenticación de Windows
defined('DB_PASS') || define('DB_PASS', null);

defined('SESSION_NAME') || define('SESSION_NAME', 'salci_sid');

/* RF02: alertas críticas por correo y webhook.
   Vacío = solo se registra en registro_alertas (no se intenta red externa).
   Un fallo de red NUNCA revierte la transacción de stock. */
defined('RF02_EMAIL_DESTINO') || define('RF02_EMAIL_DESTINO', '');
defined('RF02_WEBHOOK_URL')   || define('RF02_WEBHOOK_URL', '');

/* RF02 correo vía Resend (API key con permiso sending_access).
   RF02_RESEND_FROM debe ser un dominio verificado o, en pruebas,
   SALCI <onboarding@resend.dev>. La key se define en config.local.php. */
defined('RF02_RESEND_API_KEY') || define('RF02_RESEND_API_KEY', '');
defined('RF02_RESEND_FROM')    || define('RF02_RESEND_FROM', 'SALCI <onboarding@resend.dev>');

/* RF05: la clave de firma NO se versiona. Se exige en config.local.php.
   La validación está al final de este archivo, después de cargar errores.php. */

/* ---------------------------------------------------------------------
   RNF01 — Eficiencia: tiempo de respuesta.
   Se mide desde el primer include de cualquier petición y se publica en la
   cabecera X-Response-Time-Ms (exigida por el informe). Además se registra
   en el log cualquier respuesta por encima del objetivo de 500 ms.
   --------------------------------------------------------------------- */
$GLOBALS['salci_t0'] = microtime(true);

if (function_exists('header_register_callback')) {
    header_register_callback(static function (): void {
        if (headers_sent()) {
            return;
        }
        $ms = (microtime(true) - ($GLOBALS['salci_t0'] ?? microtime(true))) * 1000;
        header('X-Response-Time-Ms: ' . number_format($ms, 2, '.', ''));
    });
}

register_shutdown_function(static function (): void {
    $ms = (microtime(true) - ($GLOBALS['salci_t0'] ?? microtime(true))) * 1000;
    if ($ms > 500) {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? 'cli');
        error_log(sprintf('SALCI RNF01: respuesta lenta %.2f ms en %s', $ms, $uri));
    }
});

/** Tiempo transcurrido hasta este momento (para mostrarlo en el panel). */
function salci_response_ms(): float
{
    return (microtime(true) - ($GLOBALS['salci_t0'] ?? microtime(true))) * 1000;
}

/* RNF06: manejadores globales (causa + acción correctiva). */
require_once __DIR__ . '/errores.php';

/* RF05: fallo explícito si no se configuró una clave de firma real. */
if (!defined('JWT_SECRET')
    || strlen((string) JWT_SECRET) < 32
    || stripos((string) JWT_SECRET, 'PEG-AQUI') !== false) {
    throw new RuntimeException(
        'Falta una clave JWT_SECRET válida en app/config.local.php. '
        . 'Cópiala desde config.local.php.example y genera una con: '
        . 'php -r "echo bin2hex(random_bytes(32));"'
    );
}
