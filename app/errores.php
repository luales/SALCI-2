<?php
declare(strict_types=1);

/* ---------------------------------------------------------------------
   RNF06 — Usabilidad: operabilidad y ergonomía.
   Todo error entregado al usuario debe explicar CAUSA y ACCIÓN CORRECTIVA.
   Los manejadores globales cubren los errores no atrapados por cada página;
   render_flash_op() aplica el mismo formato a los errores ya controlados.
   --------------------------------------------------------------------- */

/**
 * Clasifica una excepción y devuelve causa + acción correctiva en español.
 *
 * @return array{causa:string, accion:string}
 */
function salci_error_clasificar(Throwable $e): array
{
    $mensaje = $e->getMessage();

    if ($e instanceof PDOException) {
        if (str_contains($mensaje, '23000')
            || stripos($mensaje, 'duplicate') !== false
            || stripos($mensaje, 'violation') !== false) {
            return [
                'causa'  => 'El dato infringió una restricción de la base (clave duplicada o referencia inválida).',
                'accion' => 'Revisa el SKU, RUT, usuario o sucursal e inténtalo con un valor único y existente.',
            ];
        }

        if (str_contains($mensaje, '1205') || stripos($mensaje, 'deadlock') !== false) {
            return [
                'causa'  => 'Otra operación tenía bloqueado el mismo registro.',
                'accion' => 'Espera un momento y vuelve a ejecutar la acción; no se perdió ningún dato.',
            ];
        }

        if (stripos($mensaje, 'could not connect') !== false
            || stripos($mensaje, 'sql server') !== false
            || stripos($mensaje, 'login failed') !== false) {
            return [
                'causa'  => 'No se pudo establecer la conexión con SQL Server.',
                'accion' => 'Verifica que el servicio de tu instancia esté iniciado y que DB_DSN en config.local.php sea correcto.',
            ];
        }

        return [
            'causa'  => 'SQL Server rechazó la sentencia ejecutada.',
            'accion' => 'Revisa los datos del formulario; si persiste, pide al administrador el registro en el log de errores.',
        ];
    }

    if ($e instanceof RuntimeException || $e instanceof InvalidArgumentException) {
        return [
            'causa'  => $mensaje,
            'accion' => 'Corrige el dato indicado y vuelve a intentarlo.',
        ];
    }

    return [
        'causa'  => $mensaje !== '' ? $mensaje : 'Se produjo un error inesperado en la aplicación.',
        'accion' => 'Recarga la página; si vuelve a ocurrir, informa al administrador con la hora y la pantalla donde sucedió.',
    ];
}

/** Arma el HTML de un error amigable, con detalle técnico sólo en APP_DEBUG. */
function salci_error_html(string $causa, string $accion, ?Throwable $e = null): string
{
    $causa  = htmlspecialchars($causa, ENT_QUOTES, 'UTF-8');
    $accion = htmlspecialchars($accion, ENT_QUOTES, 'UTF-8');
    $detalle = '';

    if (APP_DEBUG && $e !== null) {
        $tecnico = $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine();
        $detalle = '<pre class="error-detalle">'
            . htmlspecialchars($tecnico, ENT_QUOTES, 'UTF-8') . '</pre>';
    }

    return '<!doctype html><html lang="es"><head>
    <script src="assets/tema.js"></script><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Error · ' . htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') . '</title>'
        . '<link rel="stylesheet" href="assets/style.css"></head>'
        . '<body class="centrado"><main class="tarjeta error-pagina">'
        . '<p class="eyebrow">RNF06 · Error informado</p>'
        . '<h2>No se pudo completar la operación</h2>'
        . '<p class="error-bloque"><strong>Causa:</strong> ' . $causa . '</p>'
        . '<p class="error-bloque"><strong>Acción correctiva:</strong> ' . $accion . '</p>'
        . $detalle
        . '<p class="acciones"><a class="boton" href="index.php">Volver al panel</a></p>'
        . '</main></body></html>';
}

/** Manejador global de excepciones y errores fatales no atrapados. */
function salci_exception_handler(Throwable $e): void
{
    error_log('SALCI error no atrapado: ' . $e->getMessage()
        . ' en ' . $e->getFile() . ':' . $e->getLine());

    $info = salci_error_clasificar($e);
    $uri  = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));

    /* health.php debe seguir entregando JSON aunque falle algo inesperado. */
    if (str_contains($uri, 'health.php')) {
        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'status' => 'error',
            'causa'  => $info['causa'],
            'accion' => $info['accion'],
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    if (!headers_sent()) {
        http_response_code(500);
    }
    echo salci_error_html($info['causa'], $info['accion'], $e);
}

/**
 * Warnings y notices: se registran en el log y se muestran sólo en desarrollo.
 * Devuelve true para que PHP no los repita por su cuenta.
 */
function salci_error_handler(int $errno, string $errstr, string $errfile, int $errline): bool
{
    if (!(error_reporting() & $errno)) {
        return false;
    }

    error_log(sprintf('SALCI PHP [%d] %s en %s:%d', $errno, $errstr, $errfile, $errline));

    $uri = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));
    $esJsonOoCsv = str_contains($uri, 'health.php') || str_contains($uri, 'export_csv');

    /* RNF06: también los avisos se informan, incluso en producción. En modo
       desarrollo se muestra el texto exacto; en producción se resumen los
       datos sensibles pero igual aparecen causa y acción correctiva. */
    if (!headers_sent() && !$esJsonOoCsv) {
        $causa = APP_DEBUG
            ? htmlspecialchars($errstr, ENT_QUOTES, 'UTF-8')
            : 'Se produjo un aviso al procesar esta pantalla ('
              . htmlspecialchars(basename($errfile), ENT_QUOTES, 'UTF-8') . ').';
        $accion = APP_DEBUG
            ? 'revisa el código indicado en el log ('
              . htmlspecialchars(basename($errfile), ENT_QUOTES, 'UTF-8') . ':' . $errline . ').'
            : 'Recarga la página; si vuelve a ocurrir, informa al administrador con la hora y la pantalla.';

        echo '<div class="flash error"><strong>Causa:</strong> ' . $causa
            . ' <strong>Acción correctiva:</strong> ' . $accion . '</div>';
    }

    return true;
}

/** Última red de seguridad para fatales que no pasan por Throwable. */
function salci_shutdown_error(): void
{
    $ultimo = error_get_last();
    if ($ultimo === null || !in_array($ultimo['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    $causa = 'El servidor encontró un error fatal al procesar la solicitud.';
    $accion = 'Recarga la página y, si persiste, informa al administrador con la hora del incidente.';
    error_log('SALCI fatal: ' . $ultimo['message'] . ' en ' . $ultimo['file'] . ':' . $ultimo['line']);

    if (!headers_sent()) {
        http_response_code(500);
        echo salci_error_html($causa, $accion);
    } else {
        echo '<div class="flash error"><strong>Causa:</strong> ' . $causa
            . ' <strong>Acción correctiva:</strong> ' . $accion . '</div>';
    }
}

set_error_handler('salci_error_handler');
set_exception_handler('salci_exception_handler');
register_shutdown_function('salci_shutdown_error');
