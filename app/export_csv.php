<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/reportes_lib.php';

$user = auth_require();
$ctx  = informes_contexto($user, db());
$tipo = (string) ($_GET['tipo'] ?? '');

$permitidos = ['stock', 'ventas', 'proveedores', 'alertas', 'kardex'];

if (!in_array($tipo, $permitidos, true)) {
    http_response_code(400);
    exit('Informe no válido.');
}

[$cabeceras, $filas] = csv_informe(db(), $tipo, (int) $ctx['sucursal_id']);

header('Content-Type: text/csv; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Content-Disposition: attachment; filename="salci_' . $tipo . '_' . date('Ymd') . '.csv"');

$salida = fopen('php://output', 'wb');
fwrite($salida, "\xEF\xBB\xBF");       // BOM UTF-8 para Excel en español
fputcsv($salida, $cabeceras, ';');

foreach ($filas as $fila) {
    $seguro = array_map(static function ($valor): string {
        $texto = (string) $valor;

        /* Evita que Excel interprete celdas como fórmulas (RF14). */
        if (preg_match('/^[=@+]/', $texto)) {
            $texto = "'" . $texto;
        }

        return $texto;
    }, $fila);

    fputcsv($salida, $seguro, ';');
}

fclose($salida);
exit;
