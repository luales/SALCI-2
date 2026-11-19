<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$error    = null;
$aviso    = isset($_GET['expirado']) ? 'Tu acceso expiró (24 h). Inicia sesión nuevamente.' : null;
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'La sesión expiró. Vuelve a intentarlo.';
    } elseif ($username === '' || $password === '') {
        $error = 'Ingresa usuario y contraseña.';
    } else {
        $user = auth_attempt($username, $password);

        if ($user === null) {
            $error = 'Usuario o contraseña incorrectos.';
        } else {
            auth_login($user);
            header('Location: index.php');
            exit;
        }
    }
}

if (auth_user() !== null) {
    header('Location: index.php');
    exit;
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#050a12">
    <title>Ingreso · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="centrado">
    <div class="acceso">
    <main class="tarjeta">
        <header class="marca">
            <span class="logo">S</span>
            <div>
                <h1><?= APP_NAME ?></h1>
                <p>Sistema de inventario y logística</p>
            </div>
        </header>

        <h2>Iniciar sesión</h2>

        <?php if ($error !== null): ?>
            <p class="alerta" role="alert"><?= e($error) ?></p>
        <?php elseif ($aviso !== null): ?>
            <p class="alerta" role="status"><?= e($aviso) ?></p>
        <?php endif; ?>

        <form method="post" action="login.php" autocomplete="on" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

            <label for="username">Usuario</label>
            <input id="username" name="username" type="text"
                   value="<?= e($username) ?>" required autofocus
                   autocomplete="username">

            <label for="password">Contraseña</label>
            <input id="password" name="password" type="password"
                   required autocomplete="current-password">

            <button type="submit">Entrar</button>
        </form>

        <footer class="pista">
            <strong>Demo:</strong> admin · cajero.chillan<br>
            <strong>Clave:</strong> Salci2026!
        </footer>
    </main>
    </div>
</body>
</html>
