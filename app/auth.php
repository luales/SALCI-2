<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/* ---------------------------------------------------------------------
   Sesión
   --------------------------------------------------------------------- */

function app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string
{
    app_session();

    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['csrf'];
}

function csrf_check(?string $token): bool
{
    app_session();

    return is_string($token)
        && !empty($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $token);
}

/* ---------------------------------------------------------------------
   Autenticación
   --------------------------------------------------------------------- */

/**
 * Valida usuario y contraseña contra la tabla usuarios.
 *
 * Esquema de hash (igual que en 02_seed.sql):
 *   SHA-256( salt_binario || password_utf8 )  -> 64 caracteres hex
 */
function auth_attempt(string $username, string $password): ?array
{
    $stmt = db()->prepare(
        'SELECT u.id, u.username, u.password_hash, u.salt, u.rol, u.sucursal_id,
                s.nombre AS sucursal
           FROM usuarios u
           LEFT JOIN sucursales s ON s.id = u.sucursal_id
          WHERE u.username = :usuario
            AND u.activo = 1'
    );
    $stmt->execute([':usuario' => $username]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $salt = hex2bin(trim((string) $row['salt']));
    if ($salt === false) {
        return null;
    }

    $calculado = hash('sha256', $salt . $password);
    $guardado  = strtolower(trim((string) $row['password_hash']));

    if (!hash_equals($guardado, $calculado)) {
        return null;
    }

    return [
        'id'          => (int) $row['id'],
        'username'    => (string) $row['username'],
        'rol'         => strtoupper((string) $row['rol']),
        'sucursal_id' => $row['sucursal_id'] !== null ? (int) $row['sucursal_id'] : null,
        'sucursal'    => $row['sucursal'],
    ];
}

/* ---------------------------------------------------------------------
   JWT HS256 (RF05): rol y sucursal en el payload, vigencia 24 horas.
   Se guarda dentro de la sesión PHP (HttpOnly + SameSite=Lax).
   --------------------------------------------------------------------- */

function auth_b64url(string $datos): string
{
    return rtrim(strtr(base64_encode($datos), '+/', '-_'), '=');
}

function auth_b64url_decode(string $datos): string
{
    $relleno = strlen($datos) % 4;
    if ($relleno > 0) {
        $datos .= str_repeat('=', 4 - $relleno);
    }

    return (string) base64_decode(strtr($datos, '-_', '+/'), true);
}

function auth_jwt_emitir(array $user): string
{
    $ahora = time();
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $payload = [
        'sub' => (int) $user['id'],
        'usr' => (string) $user['username'],
        'rol' => (string) $user['rol'],
        'suc' => (int) ($user['sucursal_id'] ?? 0),
        'iat' => $ahora,
        'exp' => $ahora + 86400,
    ];

    $sinFirma = auth_b64url((string) json_encode($header, JSON_UNESCAPED_SLASHES))
        . '.' . auth_b64url((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return $sinFirma . '.' . auth_b64url(hash_hmac('sha256', $sinFirma, JWT_SECRET, true));
}

function auth_jwt_validar(?string $token): ?array
{
    if (!is_string($token)) {
        return null;
    }

    $partes = explode('.', $token);
    if (count($partes) !== 3) {
        return null;
    }

    $esperada = auth_b64url(hash_hmac('sha256', $partes[0] . '.' . $partes[1], JWT_SECRET, true));
    if (!hash_equals($esperada, $partes[2])) {
        return null;
    }

    $payload = json_decode(auth_b64url_decode($partes[1]), true);
    if (!is_array($payload) || (int) ($payload['exp'] ?? 0) <= time()) {
        return null;
    }

    return $payload;
}

function auth_login(array $user): void
{
    app_session();
    session_regenerate_id(true);
    $_SESSION['user'] = $user;
    $_SESSION['access_token'] = auth_jwt_emitir($user);
}

function auth_user(): ?array
{
    app_session();

    return $_SESSION['user'] ?? null;
}

function auth_logout(): void
{
    app_session();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $p['path'],
            $p['domain'],
            $p['secure'],
            $p['httponly']
        );
    }

    session_destroy();
}

function auth_require(): array
{
    $user = auth_user();

    if ($user === null) {
        header('Location: login.php');
        exit;
    }

    /* RF05: el token firmado expira a las 24 h aunque la sesión siga viva. */
    if (auth_jwt_validar($_SESSION['access_token'] ?? null) === null) {
        auth_logout();
        header('Location: login.php?expirado=1');
        exit;
    }

    return $user;
}

/* ---------------------------------------------------------------------
   Rol -> saludo
   --------------------------------------------------------------------- */

function rol_saludo(string $rol): string
{
    return match ($rol) {
        'ADMIN'    => 'Hola admin',
        'OPERADOR' => 'Hola trabajador',
        default    => 'Hola usuario',
    };
}
