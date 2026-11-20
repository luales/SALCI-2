<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/operaciones.php';
require_once __DIR__ . '/partials.php';

$user = auth_require();
$pdo  = db();

$sucursalId = (int) ($_POST['sucursal_id'] ?? $_GET['sucursal_id'] ?? 0);
$pedido     = (string) ($_POST['volver'] ?? $_GET['volver'] ?? '');
$volver     = in_array($pedido, ['pos.php', 'despachos.php', 'compradores.php'], true)
    ? $pedido : 'pos.php';

$error = null;
$datos = [
    'rut'       => '',
    'nombre'    => '',
    'telefono'  => '',
    'email'     => '',
    'direccion' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'La sesión expiró. Vuelve a intentarlo.';
    } else {
        foreach (array_keys($datos) as $campo) {
            $datos[$campo] = trim((string) ($_POST[$campo] ?? ''));
        }
        /* RF08: misma normalización que proveedores (mayúsculas) para que el
           índice único no acepte el mismo RUT con distinta caja. */
        $datos['rut'] = op_rut($datos['rut']) ?? '';

        if ($datos['nombre'] === '') {
            $error = 'El nombre del comprador es obligatorio.';
        } elseif (mb_strlen($datos['nombre'], 'UTF-8') > 150) {
            $error = 'El nombre no puede superar los 150 caracteres.';
        } elseif ($datos['rut'] !== '' && mb_strlen($datos['rut'], 'UTF-8') > 12) {
            $error = 'El RUT no puede superar los 12 caracteres.';
        } elseif ($datos['telefono'] !== '' && mb_strlen($datos['telefono'], 'UTF-8') > 30) {
            $error = 'El teléfono no puede superar los 30 caracteres.';
        } elseif ($datos['email'] !== '' && !filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'El correo electrónico no es válido.';
        } elseif ($datos['direccion'] !== '' && mb_strlen($datos['direccion'], 'UTF-8') > 200) {
            $error = 'La dirección no puede superar los 200 caracteres.';
        } else {
            try {
                $st = $pdo->prepare(
                    'INSERT INTO compradores
                        (rut, nombre, telefono, email, direccion, created_by)
                     OUTPUT INSERTED.id
                     VALUES (:rut, :nombre, :telefono, :email, :direccion, :by)'
                );
                $st->bindValue(':rut', $datos['rut'] !== '' ? $datos['rut'] : null,
                    $datos['rut'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $st->bindValue(':nombre', $datos['nombre']);
                $st->bindValue(':telefono', $datos['telefono'] !== '' ? $datos['telefono'] : null,
                    $datos['telefono'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $st->bindValue(':email', $datos['email'] !== '' ? $datos['email'] : null,
                    $datos['email'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $st->bindValue(':direccion', $datos['direccion'] !== '' ? $datos['direccion'] : null,
                    $datos['direccion'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $st->bindValue(':by', (string) $user['username']);
                $st->execute();

                $nuevoId = (int) $st->fetchColumn();
                $st->closeCursor();

                $destino = $volver . '?comprador_id=' . $nuevoId
                    . ($sucursalId > 0 ? '&sucursal_id=' . $sucursalId : '');

                header('Location: ' . $destino);
                exit;
            } catch (PDOException $e) {
                $codigo = (int) ($e->errorInfo[1] ?? 0);

                if ($codigo === 2601 || $codigo === 2627) {
                    $error = 'Ese RUT ya está registrado en otro comprador.';
                } else {
                    $error = APP_DEBUG
                        ? 'No se pudo guardar el comprador: ' . $e->getMessage()
                        : 'No se pudo guardar el comprador. Intenta nuevamente.';
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <script src="assets/tema.js"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#050a12">
    <title>Nuevo comprador · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <?php render_barra($user, 'pos'); ?>

    <main class="panel panel-estrecho">
        <section class="bienvenida">
            <p class="eyebrow">RF08 · Contrapartes</p>
            <h1>Nuevo comprador</h1>
            <p>
                El comprador es una entidad independiente. La venta guarda solo su
                <code>comprador_id</code>, sin duplicar nombre ni RUT.
            </p>
        </section>

        <?php if ($error !== null): ?>
            <p class="alerta" role="alert"><?= e($error) ?></p>
        <?php endif; ?>

        <section class="detalle">
            <form method="post" action="comprador_nuevo.php" novalidate>
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="sucursal_id" value="<?= $sucursalId ?>">
                <input type="hidden" name="volver" value="<?= e($volver) ?>">

                <label for="nombre">Nombre *</label>
                <input id="nombre" name="nombre" type="text" maxlength="150" required
                       value="<?= e($datos['nombre']) ?>" autofocus>

                <label for="rut">RUT (opcional)</label>
                <input id="rut" name="rut" type="text" maxlength="12"
                       placeholder="12.345.678-9" value="<?= e($datos['rut']) ?>">

                <label for="telefono">Teléfono</label>
                <input id="telefono" name="telefono" type="text" maxlength="30"
                       value="<?= e($datos['telefono']) ?>">

                <label for="email">Correo electrónico</label>
                <input id="email" name="email" type="email" maxlength="120"
                       value="<?= e($datos['email']) ?>">

                <label for="direccion">Dirección</label>
                <input id="direccion" name="direccion" type="text" maxlength="200"
                       value="<?= e($datos['direccion']) ?>">

                <div class="acciones-form">
                    <button type="submit" class="btn-guardar">Guardar comprador</button>
                    <a class="btn-mini btn-secundario" href="<?= e($volver) ?>">Volver</a>
                </div>
            </form>
        </section>
    </main>
</body>
</html>
