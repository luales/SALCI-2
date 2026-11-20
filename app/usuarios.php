<?php
declare(strict_types=1);

require_once __DIR__ . '/operaciones.php';
require_once __DIR__ . '/partials.php';

$user = auth_require();
if (!op_admin($user)) {
    http_response_code(403);
    exit('Solo el administrador puede gestionar cuentas.');
}

$pdo = db();
$editar = (int) ($_GET['editar'] ?? 0);
$target = null;
if ($editar > 0) {
    $st = $pdo->prepare('SELECT * FROM usuarios WHERE id = :id');
    $st->execute([':id' => $editar]);
    $target = $st->fetch();
}

$usuarios = $pdo->query(
    'SELECT u.id, u.username, u.rol, u.activo, u.sucursal_id,
            s.nombre AS sucursal, u.created_at
       FROM usuarios u
       LEFT JOIN sucursales s ON s.id = u.sucursal_id
      ORDER BY u.activo DESC, u.username'
)->fetchAll();

$sucursales = $pdo->query(
    'SELECT id, nombre FROM sucursales WHERE activa = 1 ORDER BY nombre'
)->fetchAll();

$adminsActivos = (int) $pdo->query(
    "SELECT COUNT(*) FROM usuarios WHERE rol = 'ADMIN' AND activo = 1"
)->fetchColumn();

$v = static fn(string $k): string => (string) ($target[$k] ?? '');
?>
<!doctype html>
<html lang="es">
<head>
    <script src="assets/tema.js"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#050a12">
    <title>Usuarios · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <?php render_barra($user, 'usuarios'); ?>

    <main class="panel panel-ancho">
        <section class="bienvenida">
            <p class="eyebrow">CRUD · RF05/RF06</p>
            <h1>Usuarios del sistema</h1>
            <p>
                Administradores activos: <strong><?= $adminsActivos ?></strong>.
                El sistema prohíbe dar de baja al último administrador activo.
            </p>
        </section>

        <?php render_flash_op(); ?>

        <section class="detalle seccion">
            <h2><?= $target ? 'Editar cuenta' : 'Nueva cuenta' ?></h2>
            <form method="post" action="usuario_guardar.php" class="formulario">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int) ($target['id'] ?? 0) ?>">

                <label for="username">Usuario</label>
                <input id="username" name="username" type="text" maxlength="50" required
                       value="<?= e($v('username')) ?>">

                <label for="password">
                    Contraseña <?= $target ? '(vacío = conservar la actual)' : '(mínimo 8 caracteres)' ?>
                </label>
                <input id="password" name="password" type="password" maxlength="100"
                       <?= $target ? '' : 'required' ?>>

                <label for="rol">Rol</label>
                <select id="rol" name="rol" required>
                    <option value="ADMIN" <?= $v('rol') === 'ADMIN' ? 'selected' : '' ?>>ADMIN</option>
                    <option value="OPERADOR" <?= $v('rol') === 'OPERADOR' ? 'selected' : '' ?>>OPERADOR</option>
                </select>

                <label for="sucursal_id">Sucursal (obligatoria para OPERADOR)</label>
                <select id="sucursal_id" name="sucursal_id">
                    <option value="0">Sin sucursal (solo ADMIN global)</option>
                    <?php foreach ($sucursales as $s): ?>
                        <option value="<?= (int) $s['id'] ?>"
                            <?= (int) ($target['sucursal_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>>
                            <?= e((string) $s['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <p class="acciones">
                    <button class="boton" type="submit">Guardar cuenta</button>
                    <?php if ($target): ?>
                        <a class="btn-mini btn-secundario" href="usuarios.php">Cancelar</a>
                    <?php endif; ?>
                </p>
            </form>
        </section>

        <section class="detalle seccion">
            <div class="tabla-scroll">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>#</th><th>Usuario</th><th>Rol</th><th>Sucursal</th>
                            <th>Estado</th><th>Creado</th><th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($usuarios as $u): ?>
                        <tr>
                            <td><?= (int) $u['id'] ?></td>
                            <td><strong><?= e((string) $u['username']) ?></strong></td>
                            <td><?= e((string) $u['rol']) ?></td>
                            <td><?= e((string) ($u['sucursal'] ?: 'Global')) ?></td>
                            <td><?= (int) $u['activo'] === 1 ? 'Activo' : 'De baja' ?></td>
                            <td><?= e(substr((string) $u['created_at'], 0, 10)) ?></td>
                            <td>
                                <div class="acciones-tabla">
                                    <a class="btn-mini" href="usuarios.php?editar=<?= (int) $u['id'] ?>">Editar</a>
                                    <?php if ((int) $u['activo'] === 1): ?>
                                        <form method="post" action="usuario_estado.php">
                                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                            <input type="hidden" name="accion" value="baja">
                                            <button class="btn-mini btn-secundario" type="submit">Eliminar</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="usuario_estado.php">
                                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                            <input type="hidden" name="accion" value="alta">
                                            <button class="btn-mini" type="submit">Reactivar</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
