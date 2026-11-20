<?php
declare(strict_types=1);

/* ---------------------------------------------------------------------
   Operaciones compartidas por los formularios del prototipo.
   Centraliza transacciones, bloqueo de stock, auditoría y RF02.
   --------------------------------------------------------------------- */

require_once __DIR__ . '/auth.php';

function op_admin(array $user): bool
{
    return ($user['rol'] ?? '') === 'ADMIN';
}

/** Sucursal efectiva: el OPERADOR nunca puede salirse de la suya (RF11). */
function op_contexto_sucursal(
    array $user,
    PDO $pdo,
    string $param = 'sucursal_id',
    bool $permitirTodas = false
): array {
    $esAdmin = op_admin($user);
    $sucursales = $pdo->query(
        'SELECT id, nombre FROM sucursales WHERE activa = 1 ORDER BY id'
    )->fetchAll();

    $ids = array_map(static fn(array $s): int => (int) $s['id'], $sucursales);
    $nombres = [];
    foreach ($sucursales as $s) {
        $nombres[(int) $s['id']] = (string) $s['nombre'];
    }

    $pedida = (int) ($_GET[$param] ?? $_POST[$param] ?? 0);

    if (!$esAdmin) {
        $id = (int) $user['sucursal_id'];
    } elseif ($pedida > 0 && in_array($pedida, $ids, true)) {
        $id = $pedida;
    } elseif ($permitirTodas) {
        $id = 0;
    } else {
        $id = $ids[0] ?? 0;
    }

    return [
        'es_admin'   => $esAdmin,
        'sucursales' => $sucursales,
        'id'         => $id,
        'nombre'     => $id === 0 ? 'Todas las sucursales' : ($nombres[$id] ?? 'Sucursal'),
        'todas'      => $id === 0,
    ];
}

function op_flash(string $tipo, string $mensaje): void
{
    app_session();
    $_SESSION['op_flash'] = ['tipo' => $tipo, 'msg' => $mensaje];
}

function op_flash_take(): ?array
{
    app_session();
    $flash = $_SESSION['op_flash'] ?? null;
    unset($_SESSION['op_flash']);

    return is_array($flash) ? $flash : null;
}

function op_redir(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/** CSRF con mensaje y retorno a la pantalla de origen. */
function op_csrf_or_fail(string $volver): void
{
    if (!csrf_check($_POST['csrf'] ?? null)) {
        op_flash('error', 'La sesión expiró. Vuelve a intentarlo.');
        op_redir($volver);
    }
}

function op_auditoria(array $user): array
{
    return [
        'by' => (string) $user['username'],
        'at' => date('Y-m-d H:i:s'),
    ];
}

/** Transacción con XACT_ABORT y notificaciones posteriores al COMMIT (RF02). */
function op_tx(PDO $pdo, callable $fn): mixed
{
    $pdo->exec('SET XACT_ABORT ON');
    $pdo->beginTransaction();

    try {
        $resultado = $fn();
        $pdo->commit();
        op_notificar_cola();

        return $resultado;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        op_alertas_limpiar();
        throw $e;
    }
}

/** Cola de alertas por conexión: solo se notifica si la TX terminó bien. */
function &op_alertas(): array
{
    static $colas = [];
    $clave = spl_object_id(db());
    if (!isset($colas[$clave])) {
        $colas[$clave] = [];
    }

    return $colas[$clave];
}

function op_alertas_limpiar(): void
{
    $cola =& op_alertas();
    $cola = [];
}

function op_notificar_cola(): void
{
    $cola =& op_alertas();
    foreach ($cola as $alerta) {
        op_notificar($alerta);
    }
    $cola = [];
}

/** Crea la fila de inventario en 0 si el producto aún no existe en esa sede. */
function op_asegurar_inventario(
    PDO $pdo,
    int $productoId,
    int $sucursalId,
    array $user
): void {
    $st = $pdo->prepare(
        'SELECT 1 FROM inventario WHERE producto_id = :producto AND sucursal_id = :sucursal'
    );
    $st->execute([':producto' => $productoId, ':sucursal' => $sucursalId]);
    if ($st->fetchColumn() !== false) {
        return;
    }

    $aud = op_auditoria($user);
    $ins = $pdo->prepare(
        'INSERT INTO inventario
            (producto_id, sucursal_id, stock, created_by, updated_by)
         VALUES (:producto, :sucursal, 0, :by1, :by2)'
    );
    $ins->execute([
        ':producto' => $productoId,
        ':sucursal' => $sucursalId,
        ':by1'      => $aud['by'],
        ':by2'      => $aud['by'],
    ]);
}

/**
 * Mueve stock de forma atómica y deja el asiento en el kardex (RF01/RF12/RF13).
 * $delta > 0 entrada, $delta < 0 salida. Devuelve el saldo resultante.
 */
function op_mover_stock(
    PDO $pdo,
    int $productoId,
    int $sucursalId,
    int $delta,
    string $tipo,
    array $user,
    ?int $loteId = null,
    ?int $ventaDetalleId = null,
    ?string $grupoId = null
): int {
    if ($delta === 0) {
        throw new RuntimeException('El movimiento no puede ser cero.');
    }

    $st = $pdo->prepare(
        'SELECT stock
           FROM inventario WITH (UPDLOCK, ROWLOCK)
          WHERE producto_id = :producto AND sucursal_id = :sucursal'
    );
    $st->execute([':producto' => $productoId, ':sucursal' => $sucursalId]);
    $stock = $st->fetchColumn();

    if ($stock === false) {
        throw new RuntimeException('No existe inventario para ese producto en la sucursal.');
    }

    $nuevo = (int) $stock + $delta;
    if ($nuevo < 0) {
        throw new RuntimeException('Stock insuficiente: saldo disponible ' . (int) $stock . '.');
    }

    $aud = op_auditoria($user);
    $upd = $pdo->prepare(
        'UPDATE inventario
            SET stock = :nuevo, updated_at = :at, updated_by = :by
          WHERE producto_id = :producto AND sucursal_id = :sucursal'
    );
    $upd->execute([
        ':nuevo'    => $nuevo,
        ':at'       => $aud['at'],
        ':by'       => $aud['by'],
        ':producto' => $productoId,
        ':sucursal' => $sucursalId,
    ]);

    $mov = $pdo->prepare(
        'INSERT INTO transacciones
            (tipo, producto_id, sucursal_id, cantidad, stock_resultante,
             lote_id, venta_detalle_id, usuario_id, grupo_id, fecha,
             created_at, created_by, updated_at, updated_by)
         VALUES
            (:tipo, :producto, :sucursal, :cantidad, :stock,
             :lote, :detalle, :usuario, :grupo, :fecha,
             :creat, :cby, :upat, :upy)'
    );
    $mov->execute([
        ':tipo'     => $tipo,
        ':producto' => $productoId,
        ':sucursal' => $sucursalId,
        ':cantidad' => abs($delta),
        ':stock'    => $nuevo,
        ':lote'     => $loteId,
        ':detalle'  => $ventaDetalleId,
        ':usuario'  => (int) $user['id'],
        ':grupo'    => $grupoId,
        ':fecha'    => $aud['at'],
        ':creat'    => $aud['at'],
        ':cby'      => $aud['by'],
        ':upat'     => $aud['at'],
        ':upy'      => $aud['by'],
    ]);

    op_alerta_si_critico($pdo, $productoId, $sucursalId, $nuevo, $user);

    return $nuevo;
}

/** RF02: alerta en BD y encolamiento de correo/webhook (sin revertir la venta). */
function op_alerta_si_critico(
    PDO $pdo,
    int $productoId,
    int $sucursalId,
    int $stock,
    array $user
): void {
    $st = $pdo->prepare('SELECT nombre, stock_minimo FROM productos WHERE id = :id');
    $st->execute([':id' => $productoId]);
    $prod = $st->fetch();

    if (!$prod || $stock > (int) $prod['stock_minimo']) {
        return;
    }

    /* RF02: se evalúa el saldo DESPUÉS de cada transacción. No se suprime la
       alerta por existir una anterior sin leer: eso impediría avisar de nuevas
       caídas del stock. Cada evento crítico queda registrado y notificado. */

    $suc = $pdo->prepare('SELECT nombre FROM sucursales WHERE id = :id');
    $suc->execute([':id' => $sucursalId]);
    $sucursal = (string) $suc->fetchColumn();

    $mensaje = 'Stock crítico: ' . $stock . ' unidades (mínimo '
        . (int) $prod['stock_minimo'] . ')';

    $al = $pdo->prepare(
        'INSERT INTO registro_alertas
            (producto_id, sucursal_id, mensaje, stock_al_momento, created_by, fecha)
         VALUES
            (:producto, :sucursal, :mensaje, :stock, :by, :fecha)'
    );
    $al->execute([
        ':producto' => $productoId,
        ':sucursal' => $sucursalId,
        ':mensaje'  => $mensaje,
        ':stock'    => $stock,
        ':by'       => 'SISTEMA',
        ':fecha'    => op_auditoria($user)['at'],
    ]);

    $cola =& op_alertas();
    $cola[] = [
        'producto' => (string) $prod['nombre'],
        'sucursal' => $sucursal,
        'mensaje'  => $mensaje,
        'stock'    => $stock,
        'minimo'   => (int) $prod['stock_minimo'],
        'usuario'  => (string) $user['username'],
    ];
}

/** Notifica las alertas insertadas durante una transacción ya confirmada. */
function op_notificar_alertas_nuevas(PDO $pdo, int $desdeId): void
{
    $st = $pdo->prepare(
        'SELECT a.id, a.mensaje, a.stock_al_momento, a.created_by,
                p.nombre AS producto, s.nombre AS sucursal,
                p.stock_minimo AS minimo
           FROM registro_alertas a
           JOIN productos p ON p.id = a.producto_id
           JOIN sucursales s ON s.id = a.sucursal_id
          WHERE a.id > :desde
          ORDER BY a.id'
    );
    $st->execute([':desde' => $desdeId]);

    foreach ($st->fetchAll() as $alerta) {
        op_notificar([
            'producto' => (string) $alerta['producto'],
            'sucursal' => (string) $alerta['sucursal'],
            'mensaje'  => (string) $alerta['mensaje'],
            'stock'    => (int) $alerta['stock_al_momento'],
            'minimo'   => (int) $alerta['minimo'],
            'usuario'  => (string) ($alerta['created_by'] ?: 'SISTEMA'),
        ]);
    }
}

/**
 * POST JSON usado por RF02 (Resend y webhook).
 * Prefiere cURL; si la extensión no está cargada usa streams HTTP nativos,
 * de modo que el prototipo funcione también en el Apache de XAMPP sin curl.
 *
 * @return array{codigo:int,cuerpo:string,error:string}
 */
function op_http_post_json(string $url, string $payload, array $headers, int $timeout): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $respuesta = curl_exec($ch);
        $codigo    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error     = (string) curl_error($ch);
        curl_close($ch);

        return [
            'codigo' => $codigo,
            'cuerpo' => $respuesta === false ? '' : (string) $respuesta,
            'error'  => $error,
        ];
    }

    $esquema = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    $host    = (string) parse_url($url, PHP_URL_HOST);
    if (!in_array($esquema, ['http', 'https'], true) || $host === '') {
        return ['codigo' => 0, 'cuerpo' => '', 'error' => 'URL de notificación no válida.'];
    }
    if (!ini_get('allow_url_fopen')) {
        return ['codigo' => 0, 'cuerpo' => '', 'error' => 'allow_url_fopen está deshabilitado.'];
    }

    $ctx = stream_context_create([
        'http' => [
            'method'          => 'POST',
            'header'          => implode("\r\n", $headers) . "\r\nConnection: close\r\n",
            'content'         => $payload,
            'timeout'         => $timeout,
            'ignore_errors'   => true,
            'protocol_version' => 1.1,
        ],
    ]);

    $respuesta = @file_get_contents($url, false, $ctx);
    $codigo    = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $linea) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $linea, $m) === 1) {
                $codigo = (int) $m[1];
            }
        }
    }

    if ($respuesta === false) {
        return [
            'codigo' => $codigo,
            'cuerpo' => '',
            'error'  => 'No se pudo conectar con ' . $esquema . '://' . $host . '.',
        ];
    }

    return ['codigo' => $codigo, 'cuerpo' => (string) $respuesta, 'error' => ''];
}

/** Envío por Resend API (RF02). La key viaja solo en el servidor. */
function op_enviar_resend(string $apiKey, string $destino, string $asunto, string $cuerpo): void
{
    $from  = defined('RF02_RESEND_FROM') ? (string) RF02_RESEND_FROM : 'SALCI <onboarding@resend.dev>';
    $datos = json_encode([
        'from'    => $from,
        'to'      => [$destino],
        'subject' => $asunto,
        'text'    => $cuerpo,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($datos === false) {
        error_log('SALCI RF02 Resend: no se pudo serializar el correo.');

        return;
    }

    $res = op_http_post_json(
        'https://api.resend.com/emails',
        $datos,
        [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($datos),
        ],
        5
    );

    if ($res['error'] !== '') {
        error_log('SALCI RF02 Resend: ' . $res['error']);

        return;
    }

    if ($res['codigo'] < 200 || $res['codigo'] >= 300) {
        error_log('SALCI RF02 Resend HTTP ' . $res['codigo'] . ': ' . mb_substr($res['cuerpo'], 0, 300));
    }
}

/** Registro local de los canales RF02 cuando no hay proveedor externo. */
function op_log_rf02(string $canal, string $contenido): void
{
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $archivo = $dir . '/rf02_' . $canal . '.log';
    @file_put_contents(
        $archivo,
        '[' . date('c') . '] ' . trim($contenido) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

/** Mejor esfuerzo: si falla correo/webhook NO se revierte la base de datos. */
function op_notificar(array $alerta): void
{
    $asunto = 'SALCI · Stock crítico · ' . $alerta['producto'];
    $cuerpo = $alerta['producto'] . ' (' . $alerta['sucursal'] . ")\n"
        . $alerta['mensaje'] . "\nRegistrado por: " . $alerta['usuario'];

    $destino = defined('RF02_EMAIL_DESTINO') ? (string) RF02_EMAIL_DESTINO : '';
    if ($destino !== '' && filter_var($destino, FILTER_VALIDATE_EMAIL)) {
        $apiKey = defined('RF02_RESEND_API_KEY') ? trim((string) RF02_RESEND_API_KEY) : '';

        try {
            if ($apiKey !== '') {
                op_enviar_resend($apiKey, $destino, $asunto, $cuerpo);
            } else {
                /* Sin API key: se intenta mail() y, si el equipo no tiene SMTP,
                   queda constancia del correo en logs/rf02_correo.log. */
                $enviado = @mail($destino, $asunto, $cuerpo, 'Content-Type: text/plain; charset=UTF-8');
                if (!$enviado) {
                    op_log_rf02('correo', "PARA: $destino\nASUNTO: $asunto\n$cuerpo");
                }
            }
        } catch (Throwable $e) {
            error_log('SALCI RF02 correo: ' . $e->getMessage());
        }
    }

    $url = defined('RF02_WEBHOOK_URL') ? (string) RF02_WEBHOOK_URL : '';
    $payload = json_encode($alerta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    /* Sin URL configurada se conserva el POST simulado en el log local. */
    if ($url === '' && $payload !== false) {
        op_log_rf02('webhook', $payload);
    }

    if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
        if ($payload === false) {
            error_log('SALCI RF02 webhook: no se pudo serializar la alerta.');
        } else {
            try {
                $res = op_http_post_json(
                    $url,
                    $payload,
                    [
                        'Content-Type: application/json',
                        'Content-Length: ' . strlen($payload),
                    ],
                    2
                );
                if ($res['error'] !== '') {
                    error_log('SALCI RF02 webhook: ' . $res['error']);
                } elseif ($res['codigo'] < 200 || $res['codigo'] >= 300) {
                    error_log('SALCI RF02 webhook HTTP ' . $res['codigo']);
                }
            } catch (Throwable $e) {
                error_log('SALCI RF02 webhook: ' . $e->getMessage());
            }
        }
    }
}

/** Hash SALCI: SHA-256(salt_binario || password), igual que 02_seed.sql. */
function op_hash_password(string $password): array
{
    $salt = bin2hex(random_bytes(16));

    return [
        'salt' => $salt,
        'hash' => hash('sha256', hex2bin($salt) . $password),
    ];
}

function op_texto(?string $valor, int $maximo): string
{
    return mb_substr(trim((string) $valor), 0, $maximo);
}

function op_rut(?string $valor): ?string
{
    $rut = mb_strtoupper(trim((string) $valor));

    return $rut === '' ? null : mb_substr($rut, 0, 12);
}

function op_fecha(?string $valor): ?string
{
    $valor = trim((string) $valor);
    if ($valor === '') {
        return null;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $valor);
    if ($dt === false || $dt->format('Y-m-d') !== $valor) {
        return null;
    }

    return $valor . ' 12:00:00';
}
