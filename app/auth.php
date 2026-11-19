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

function auth_login(array $user): void
{
    app_session();
    session_regenerate_id(true);
    $_SESSION['user'] = $user;
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
