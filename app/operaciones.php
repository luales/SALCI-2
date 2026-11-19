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
             lote_id, venta_detalle_id, usuario_id, grupo_id, fecha)
         VALUES
            (:tipo, :producto, :sucursal, :cantidad, :stock,
             :lote, :detalle, :usuario, :grupo, :fecha)'
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

    $ex = $pdo->prepare(
        'SELECT 1 FROM registro_alertas
          WHERE producto_id = :producto AND sucursal_id = :sucursal AND leida = 0'
    );
    $ex->execute([':producto' => $productoId, ':sucursal' => $sucursalId]);
    if ($ex->fetchColumn() !== false) {
        return;
    }

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

/** Mejor esfuerzo: si falla correo/webhook NO se revierte la base de datos. */
function op_notificar(array $alerta): void
{
    $asunto = 'SALCI · Stock crítico · ' . $alerta['producto'];
    $cuerpo = $alerta['producto'] . ' (' . $alerta['sucursal'] . ")\n"
        . $alerta['mensaje'] . "\nRegistrado por: " . $alerta['usuario'];

    $destino = defined('RF02_EMAIL_DESTINO') ? (string) RF02_EMAIL_DESTINO : '';
    if ($destino !== '' && filter_var($destino, FILTER_VALIDATE_EMAIL)) {
        try {
            @mail($destino, $asunto, $cuerpo, 'Content-Type: text/plain; charset=UTF-8');
        } catch (Throwable $e) {
            error_log('SALCI RF02 correo: ' . $e->getMessage());
        }
    }

    $url = defined('RF02_WEBHOOK_URL') ? (string) RF02_WEBHOOK_URL : '';
    if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
        $payload = json_encode($alerta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payload,
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                    CURLOPT_TIMEOUT        => 2,
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_RETURNTRANSFER => true,
                ]);
                curl_exec($ch);
                curl_close($ch);
            } else {
                file_get_contents($url, false, stream_context_create([
                    'http' => [
                        'method'  => 'POST',
                        'header'  => "Content-Type: application/json\r\n",
                        'content' => (string) $payload,
                        'timeout' => 2,
                    ],
                ]));
            }
        } catch (Throwable $e) {
            error_log('SALCI RF02 webhook: ' . $e->getMessage());
        }
    }
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
