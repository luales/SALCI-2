<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/partials.php';

$user    = auth_require();
$pdo     = db();
$esAdmin = $user['rol'] === 'ADMIN';

/* ---------------------------------------------------------------------
   Utilidades de la pantalla
   --------------------------------------------------------------------- */

function pos_flash(string $tipo, string $mensaje): void
{
    $_SESSION['pos_flash'] = ['tipo' => $tipo, 'msg' => $mensaje];
}

function pos_redirect(int $sucursalId, bool $esAdmin): never
{
    $url = 'pos.php' . ($esAdmin ? '?sucursal_id=' . $sucursalId : '');
    header('Location: ' . $url);
    exit;
}

function pos_comprador_valido(int $id): bool
{
    if ($id <= 0) {
        return false;
    }

    $st = db()->prepare('SELECT 1 FROM compradores WHERE id = :id AND activo = 1');
    $st->execute([':id' => $id]);

    return $st->fetchColumn() !== false;
}

/* ---------------------------------------------------------------------
   Sucursal activa (el operador nunca puede cambiarla)
   --------------------------------------------------------------------- */

$sucursales = $pdo->query(
    'SELECT id, nombre FROM sucursales WHERE activa = 1 ORDER BY id'
)->fetchAll();

if (!$sucursales) {
    http_response_code(500);
    exit('No hay sucursales activas.');
}

$idsValidos = array_map(static fn(array $s): int => (int) $s['id'], $sucursales);

if ($esAdmin) {
    $solicitada = (int) ($_POST['sucursal_id'] ?? $_GET['sucursal_id'] ?? 0);
    $sucursalId = in_array($solicitada, $idsValidos, true)
        ? $solicitada
        : (int) $sucursales[0]['id'];
} else {
    $sucursalId = (int) $user['sucursal_id'];
}

$nombresSucursal = [];
foreach ($sucursales as $s) {
    $nombresSucursal[(int) $s['id']] = (string) $s['nombre'];
}
$sucursalNombre = $nombresSucursal[$sucursalId] ?? 'Sucursal';

/* ---------------------------------------------------------------------
   Carrito en sesión
   --------------------------------------------------------------------- */

if (!isset($_SESSION['pos_cart']) || !is_array($_SESSION['pos_cart'])) {
    $_SESSION['pos_cart'] = [];
}

if (isset($_SESSION['pos_sucursal']) && (int) $_SESSION['pos_sucursal'] !== $sucursalId) {
    $_SESSION['pos_cart'] = [];
}
$_SESSION['pos_sucursal'] = $sucursalId;

$cart = &$_SESSION['pos_cart'];

/* Comprador recién creado: viene por GET desde comprador_nuevo.php */
if (isset($_GET['comprador_id'])) {
    $cid = (int) $_GET['comprador_id'];
    if (pos_comprador_valido($cid)) {
        $_SESSION['pos_comprador'] = $cid;
        pos_flash('ok', 'Comprador seleccionado.');
    }
    pos_redirect($sucursalId, $esAdmin);
}

/* ---------------------------------------------------------------------
   Acciones POST (agregar / quitar / vaciar / comprador / cobrar)
   --------------------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        pos_flash('error', 'La sesión expiró. Vuelve a intentarlo.');
        pos_redirect($sucursalId, $esAdmin);
    }

    $accion = (string) ($_POST['accion'] ?? '');

    if ($accion === 'agregar') {
        $pid  = (int) ($_POST['producto_id'] ?? 0);
        $cant = (int) ($_POST['cantidad'] ?? 0);

        if ($pid <= 0 || $cant <= 0) {
            pos_flash('error', 'Cantidad inválida.');
            pos_redirect($sucursalId, $esAdmin);
        }

        $st = $pdo->prepare(
            'SELECT p.nombre, COALESCE(i.stock, 0) AS stock
               FROM productos p
               LEFT JOIN inventario i
                      ON i.producto_id = p.id AND i.sucursal_id = :suc
              WHERE p.id = :pid AND p.activo = 1'
        );
        $st->execute([':suc' => $sucursalId, ':pid' => $pid]);
        $prod = $st->fetch();

        if (!$prod) {
            pos_flash('error', 'El producto no existe.');
            pos_redirect($sucursalId, $esAdmin);
        }

        $enCarrito = (int) ($cart[$pid] ?? 0);
        $nuevo     = $enCarrito + $cant;

        if ($nuevo > (int) $prod['stock']) {
            pos_flash(
                'error',
                'Stock insuficiente de ' . $prod['nombre'] . ': disponible '
                . (int) $prod['stock'] . ', solicitado ' . $nuevo . '.'
            );
            pos_redirect($sucursalId, $esAdmin);
        }

        $cart[$pid] = $nuevo;
        pos_flash('ok', 'Agregado al carrito: ' . $prod['nombre'] . ' x' . $cant . '.');
        pos_redirect($sucursalId, $esAdmin);
    }

    if ($accion === 'quitar') {
        $pid = (int) ($_POST['producto_id'] ?? 0);
        unset($cart[$pid]);
        pos_redirect($sucursalId, $esAdmin);
    }

    if ($accion === 'limpiar') {
        $cart = [];
        $_SESSION['pos_comprador'] = null;
        pos_flash('ok', 'Carrito vaciado.');
        pos_redirect($sucursalId, $esAdmin);
    }

    if ($accion === 'comprador') {
        $cid = (int) ($_POST['comprador_id'] ?? 0);
        if ($cid > 0 && pos_comprador_valido($cid)) {
            $_SESSION['pos_comprador'] = $cid;
            pos_flash('ok', 'Comprador actualizado.');
        } else {
            $_SESSION['pos_comprador'] = null;
            pos_flash('ok', 'Venta de mostrador (sin datos de comprador).');
        }
        pos_redirect($sucursalId, $esAdmin);
    }

    if ($accion === 'cobrar') {
        if (!$cart) {
            pos_flash('error', 'El carrito está vacío.');
            pos_redirect($sucursalId, $esAdmin);
        }

        $compradorId = isset($_SESSION['pos_comprador'])
            ? (int) $_SESSION['pos_comprador']
            : 0;

        if ($compradorId > 0 && !pos_comprador_valido($compradorId)) {
            $compradorId = 0;
            $_SESSION['pos_comprador'] = null;
        }

        $compradorDb = $compradorId > 0 ? $compradorId : null;

        /* Bloquear en orden estable para reducir el riesgo de deadlock. */
        $pids = array_keys($cart);
        sort($pids);

        $pdo->exec('SET XACT_ABORT ON');
        $pdo->beginTransaction();

        try {
            $items          = [];
            $subtotalCents  = 0;

            foreach ($pids as $pid) {
                $cant = (int) $cart[$pid];
                if ($cant <= 0) {
                    throw new RuntimeException('Cantidad inválida en el carrito.');
                }

                /* Bloqueo de la fila de inventario hasta el COMMIT. */
                $st = $pdo->prepare(
                    'SELECT stock
                       FROM inventario WITH (UPDLOCK, ROWLOCK)
                      WHERE producto_id = :pid AND sucursal_id = :suc'
                );
                $st->execute([':pid' => $pid, ':suc' => $sucursalId]);
                $stock = $st->fetchColumn();

                if ($stock === false) {
                    throw new RuntimeException(
                        'Un producto del carrito ya no tiene inventario en esta sucursal.'
                    );
                }

                $stock = (int) $stock;

                $st = $pdo->prepare(
                    'SELECT sku, nombre, precio, stock_minimo
                       FROM productos WHERE id = :pid AND activo = 1'
                );
                $st->execute([':pid' => $pid]);
                $prod = $st->fetch();

                if (!$prod) {
                    throw new RuntimeException('Un producto del carrito ya no existe o está de baja.');
                }

                if ($stock < $cant) {
                    throw new RuntimeException(
                        'Stock insuficiente de ' . $prod['nombre'] . ': disponible '
                        . $stock . ', solicitado ' . $cant . '.'
                    );
                }

                $precioCents = (int) round(((float) $prod['precio']) * 100);
                $subtotalCents += $precioCents * $cant;

                $items[] = [
                    'producto_id'  => $pid,
                    'nombre'       => (string) $prod['nombre'],
                    'cantidad'     => $cant,
                    'precioCents'  => $precioCents,
                    'stock'        => $stock,
                    'stock_minimo' => (int) $prod['stock_minimo'],
                ];
            }

            $ivaCents   = (int) round($subtotalCents * 0.19);
            $totalCents = $subtotalCents + $ivaCents;
            $decimal    = static fn(int $c): string => number_format($c / 100, 2, '.', '');

            $ins = $pdo->prepare(
                "INSERT INTO ventas
                    (sucursal_id, comprador_id, usuario_id, subtotal, iva, total,
                     estado, created_by)
                 OUTPUT INSERTED.id
                 VALUES (:suc, :comp, :usuario, :sub, :iva, :total, 'COMPLETADA', :by)"
            );
            $ins->bindValue(':suc', $sucursalId, PDO::PARAM_INT);
            $ins->bindValue(
                ':comp',
                $compradorDb,
                $compradorDb === null ? PDO::PARAM_NULL : PDO::PARAM_INT
            );
            $ins->bindValue(':usuario', (int) $user['id'], PDO::PARAM_INT);
            $ins->bindValue(':sub', $decimal($subtotalCents));
            $ins->bindValue(':iva', $decimal($ivaCents));
            $ins->bindValue(':total', $decimal($totalCents));
            $ins->bindValue(':by', (string) $user['username']);
            $ins->execute();

            $ventaId = (int) $ins->fetchColumn();
            $ins->closeCursor();

            if ($ventaId <= 0) {
                throw new RuntimeException('No se pudo obtener el folio de la venta.');
            }

            foreach ($items as $it) {
                $insD = $pdo->prepare(
                    'INSERT INTO venta_detalle
                        (venta_id, producto_id, cantidad, precio_unitario)
                     OUTPUT INSERTED.id
                     VALUES (:venta, :producto, :cantidad, :precio)'
                );
                $insD->bindValue(':venta', $ventaId, PDO::PARAM_INT);
                $insD->bindValue(':producto', $it['producto_id'], PDO::PARAM_INT);
                $insD->bindValue(':cantidad', $it['cantidad'], PDO::PARAM_INT);
                $insD->bindValue(':precio', $decimal($it['precioCents']));
                $insD->execute();

                $detalleId = (int) $insD->fetchColumn();
                $insD->closeCursor();

                if ($detalleId <= 0) {
                    throw new RuntimeException('No se pudo registrar el detalle de la venta.');
                }

                $nuevoStock = $it['stock'] - $it['cantidad'];

                $upd = $pdo->prepare(
                    'UPDATE inventario
                        SET stock = :nuevo,
                            updated_at = SYSDATETIME(),
                            updated_by = :by
                      WHERE producto_id = :producto AND sucursal_id = :suc'
                );
                $upd->bindValue(':nuevo', $nuevoStock, PDO::PARAM_INT);
                $upd->bindValue(':by', (string) $user['username']);
                $upd->bindValue(':producto', $it['producto_id'], PDO::PARAM_INT);
                $upd->bindValue(':suc', $sucursalId, PDO::PARAM_INT);
                $upd->execute();

                if ($upd->rowCount() !== 1) {
                    throw new RuntimeException('No se pudo actualizar el inventario.');
                }

                $mov = $pdo->prepare(
                    "INSERT INTO transacciones
                        (tipo, producto_id, sucursal_id, cantidad, stock_resultante,
                         venta_detalle_id, usuario_id)
                     VALUES ('SALIDA', :producto, :suc, :cantidad, :stock, :detalle, :usuario)"
                );
                $mov->bindValue(':producto', $it['producto_id'], PDO::PARAM_INT);
                $mov->bindValue(':suc', $sucursalId, PDO::PARAM_INT);
                $mov->bindValue(':cantidad', $it['cantidad'], PDO::PARAM_INT);
                $mov->bindValue(':stock', $nuevoStock, PDO::PARAM_INT);
                $mov->bindValue(':detalle', $detalleId, PDO::PARAM_INT);
                $mov->bindValue(':usuario', (int) $user['id'], PDO::PARAM_INT);
                $mov->execute();

                /* RF02: alerta si el saldo queda en o bajo el mínimo,
                   sin duplicar una alerta no leída del mismo producto/sucursal. */
                if ($nuevoStock <= $it['stock_minimo']) {
                    $ex = $pdo->prepare(
                        'SELECT 1 FROM registro_alertas
                          WHERE producto_id = :producto
                            AND sucursal_id = :suc
                            AND leida = 0'
                    );
                    $ex->execute([':producto' => $it['producto_id'], ':suc' => $sucursalId]);

                    if ($ex->fetchColumn() === false) {
                        $al = $pdo->prepare(
                            'INSERT INTO registro_alertas
                                (producto_id, sucursal_id, mensaje, stock_al_momento, created_by)
                             VALUES (:producto, :suc, :mensaje, :stock, :by)'
                        );
                        $al->bindValue(':producto', $it['producto_id'], PDO::PARAM_INT);
                        $al->bindValue(':suc', $sucursalId, PDO::PARAM_INT);
                        $al->bindValue(
                            ':mensaje',
                            'Stock critico: ' . $nuevoStock . ' unidades (minimo '
                            . $it['stock_minimo'] . ')'
                        );
                        $al->bindValue(':stock', $nuevoStock, PDO::PARAM_INT);
                        $al->bindValue(':by', 'SISTEMA');
                        $al->execute();
                    }
                }
            }

            $pdo->commit();
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            pos_flash('error', $e->getMessage());
            pos_redirect($sucursalId, $esAdmin);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            pos_flash(
                'error',
                APP_DEBUG
                    ? 'No se pudo completar la venta: ' . $e->getMessage()
                    : 'No se pudo completar la venta. Intenta nuevamente.'
            );
            pos_redirect($sucursalId, $esAdmin);
        }

        $_SESSION['pos_cart']      = [];
        $_SESSION['pos_comprador'] = null;

        header('Location: pos_ticket.php?id=' . $ventaId);
        exit;
    }

    /* Acción desconocida */
    pos_redirect($sucursalId, $esAdmin);
}

/* ---------------------------------------------------------------------
   Datos para renderizar
   --------------------------------------------------------------------- */

$flash = $_SESSION['pos_flash'] ?? null;
unset($_SESSION['pos_flash']);

$compradorId = isset($_SESSION['pos_comprador'])
    ? (int) $_SESSION['pos_comprador']
    : 0;

if ($compradorId > 0 && !pos_comprador_valido($compradorId)) {
    $compradorId = 0;
    $_SESSION['pos_comprador'] = null;
}

$compradorActual = null;
if ($compradorId > 0) {
    $st = $pdo->prepare(
        'SELECT id, nombre, rut FROM compradores WHERE id = :id AND activo = 1'
    );
    $st->execute([':id' => $compradorId]);
    $compradorActual = $st->fetch() ?: null;
}

$compradores = $pdo->query(
    'SELECT id, nombre, rut FROM compradores WHERE activo = 1 ORDER BY nombre'
)->fetchAll();

$productos = $pdo->prepare(
    'SELECT p.id, p.sku, p.nombre, p.precio, p.stock_minimo,
            COALESCE(i.stock, 0) AS stock
       FROM productos p
       LEFT JOIN inventario i
              ON i.producto_id = p.id AND i.sucursal_id = :suc
      WHERE p.activo = 1
      ORDER BY p.nombre'
);
$productos->execute([':suc' => $sucursalId]);
$productos = $productos->fetchAll();

/* Carrito con datos actuales (el stock puede haber cambiado desde que se agregó) */
$items        = [];
$subtotalCents = 0;
$hayProblema  = false;

foreach ($cart as $pid => $cant) {
    $cant = (int) $cant;

    $st = $pdo->prepare(
        'SELECT p.id, p.sku, p.nombre, p.precio,
                COALESCE(i.stock, 0) AS stock
           FROM productos p
           LEFT JOIN inventario i
                  ON i.producto_id = p.id AND i.sucursal_id = :suc
          WHERE p.id = :pid AND p.activo = 1'
    );
    $st->execute([':suc' => $sucursalId, ':pid' => (int) $pid]);
    $prod = $st->fetch();

    if (!$prod) {
        unset($cart[$pid]);
        continue;
    }

    $stock = (int) $prod['stock'];
    $precioCents = (int) round(((float) $prod['precio']) * 100);
    $lineaCents  = $precioCents * $cant;

    if ($cant > $stock) {
        $hayProblema = true;
    }

    $subtotalCents += $lineaCents;

    $items[] = [
        'producto_id' => (int) $prod['id'],
        'sku'         => (string) $prod['sku'],
        'nombre'      => (string) $prod['nombre'],
        'cantidad'    => $cant,
        'stock'       => $stock,
        'precioCents' => $precioCents,
        'lineaCents'  => $lineaCents,
    ];
}

$ivaCents   = (int) round($subtotalCents * 0.19);
$totalCents = $subtotalCents + $ivaCents;

$compradorNombre = $compradorActual !== null
    ? (string) $compradorActual['nombre']
    : 'Consumidor final';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#050a12">
    <title>Punto de venta · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <?php render_barra($user, 'pos'); ?>

    <main class="panel panel-ancho pos">
        <section class="bienvenida">
            <p class="eyebrow">RF15 · Venta POS</p>
            <h1>Punto de venta</h1>
            <p>
                Venta rápida en <strong><?= e($sucursalNombre) ?></strong>.
                Al cobrar se descuenta el stock y se registra el movimiento en el kardex
                dentro de la misma transacción.
            </p>
        </section>

        <?php if ($flash !== null): ?>
            <p class="alerta <?= $flash['tipo'] === 'ok' ? 'ok' : '' ?>" role="status">
                <?= e((string) $flash['msg']) ?>
            </p>
        <?php endif; ?>

        <div class="pos-barra">
            <?php if ($esAdmin): ?>
                <form method="get" action="pos.php" class="fila">
                    <label for="sucursal">Sucursal</label>
                    <select id="sucursal" name="sucursal_id" onchange="this.form.submit()">
                        <?php foreach ($sucursales as $s): ?>
                            <option value="<?= (int) $s['id'] ?>"
                                <?= (int) $s['id'] === $sucursalId ? 'selected' : '' ?>>
                                <?= e((string) $s['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button type="submit" class="btn-mini">Cambiar</button></noscript>
                </form>
            <?php else: ?>
                <p class="fila">
                    <span class="etiqueta-fila">Sucursal</span>
                    <span class="chip"><?= e($sucursalNombre) ?></span>
                </p>
            <?php endif; ?>

            <form method="post" action="pos.php" class="fila">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <?php if ($esAdmin): ?>
                    <input type="hidden" name="sucursal_id" value="<?= $sucursalId ?>">
                <?php endif; ?>
                <input type="hidden" name="accion" value="comprador">

                <label for="comprador">Comprador</label>
                <select id="comprador" name="comprador_id">
                    <option value="0" <?= $compradorId === 0 ? 'selected' : '' ?>>
                        Consumidor final (sin datos)
                    </option>
                    <?php foreach ($compradores as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"
                            <?= $compradorId === (int) $c['id'] ? 'selected' : '' ?>>
                            <?= e((string) $c['nombre']) ?><?= $c['rut'] !== null ? ' · ' . e((string) $c['rut']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-mini">Aplicar</button>
                <a class="btn-mini btn-secundario"
                   href="comprador_nuevo.php<?= $esAdmin ? '?sucursal_id=' . $sucursalId : '' ?>">
                    Nuevo comprador
                </a>
            </form>
        </div>

        <div class="pos-grid">
            <section class="detalle">
                <h2>Catálogo y stock</h2>

                <label for="buscar" class="etiqueta-fila">Buscar</label>
                <input id="buscar" class="buscador" type="search"
                       placeholder="SKU o nombre del producto…" autocomplete="off">

                <div class="tabla-scroll">
                    <table class="tabla" id="tabla-productos">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Producto</th>
                                <th class="num">Precio</th>
                                <th class="num">Stock</th>
                                <th class="num">Agregar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($productos as $p): ?>
                                <?php
                                $stock    = (int) $p['stock'];
                                $minimo   = (int) $p['stock_minimo'];
                                $critico  = $stock <= $minimo;
                                $sinStock = $stock <= 0;
                                $buscar   = mb_strtolower((string) $p['sku'] . ' ' . (string) $p['nombre'], 'UTF-8');
                                ?>
                                <tr class="<?= $critico ? 'critico' : '' ?>"
                                    data-buscar="<?= e($buscar) ?>">
                                    <td class="mono"><?= e((string) $p['sku']) ?></td>
                                    <td>
                                        <?= e((string) $p['nombre']) ?>
                                        <?php if ($critico): ?>
                                            <span class="stock-critico">crítico</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="num"><?= pesos((int) round(((float) $p['precio']) * 100)) ?></td>
                                    <td class="num <?= $critico ? 'stock-critico' : '' ?>"><?= $stock ?></td>
                                    <td class="num">
                                        <?php if ($sinStock): ?>
                                            <span class="tenue">Sin stock</span>
                                        <?php else: ?>
                                            <form method="post" action="pos.php" class="fila-mini">
                                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                                <?php if ($esAdmin): ?>
                                                    <input type="hidden" name="sucursal_id" value="<?= $sucursalId ?>">
                                                <?php endif; ?>
                                                <input type="hidden" name="accion" value="agregar">
                                                <input type="hidden" name="producto_id" value="<?= (int) $p['id'] ?>">
                                                <input type="number" name="cantidad" value="1"
                                                       min="1" max="<?= $stock ?>"
                                                       aria-label="Cantidad de <?= e((string) $p['nombre']) ?>">
                                                <button type="submit" class="btn-mini">Agregar</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <aside class="detalle carrito">
                <h2>Carrito</h2>

                <?php if (!$items): ?>
                    <p class="vacio">Sin productos todavía. Agrega desde el catálogo.</p>
                <?php else: ?>
                    <div class="tabla-scroll">
                        <table class="tabla">
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th class="num">Cant.</th>
                                    <th class="num">Subtotal</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $it): ?>
                                    <tr class="<?= $it['cantidad'] > $it['stock'] ? 'critico' : '' ?>">
                                        <td>
                                            <?= e($it['nombre']) ?><br>
                                            <span class="mono"><?= e($it['sku']) ?></span>
                                        </td>
                                        <td class="num"><?= (int) $it['cantidad'] ?></td>
                                        <td class="num"><?= pesos($it['lineaCents']) ?></td>
                                        <td>
                                            <form method="post" action="pos.php">
                                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                                <?php if ($esAdmin): ?>
                                                    <input type="hidden" name="sucursal_id" value="<?= $sucursalId ?>">
                                                <?php endif; ?>
                                                <input type="hidden" name="accion" value="quitar">
                                                <input type="hidden" name="producto_id" value="<?= (int) $it['producto_id'] ?>">
                                                <button type="submit" class="btn-mini btn-peligro"
                                                        aria-label="Quitar <?= e($it['nombre']) ?>">×</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <dl class="totales">
                        <dt>Subtotal</dt>
                        <dd><?= pesos($subtotalCents) ?></dd>
                        <dt>IVA 19%</dt>
                        <dd><?= pesos($ivaCents) ?></dd>
                        <dt class="total-final">Total</dt>
                        <dd class="total-final"><?= pesos($totalCents) ?></dd>
                    </dl>

                    <p class="nota">
                        Comprador: <strong><?= e($compradorNombre) ?></strong><br>
                        Sucursal: <strong><?= e($sucursalNombre) ?></strong>
                    </p>

                    <?php if ($hayProblema): ?>
                        <p class="alerta" role="alert">
                            El stock cambió y ya no alcanza para una línea del carrito.
                            Quita el producto o ajusta la cantidad antes de cobrar.
                        </p>
                    <?php endif; ?>

                    <form method="post" action="pos.php">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <?php if ($esAdmin): ?>
                            <input type="hidden" name="sucursal_id" value="<?= $sucursalId ?>">
                        <?php endif; ?>
                        <input type="hidden" name="accion" value="cobrar">
                        <button type="submit" class="btn-cobrar" <?= $hayProblema ? 'disabled' : '' ?>>
                            Cobrar <?= pesos($totalCents) ?>
                        </button>
                    </form>

                    <form method="post" action="pos.php" class="limpiar">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <?php if ($esAdmin): ?>
                            <input type="hidden" name="sucursal_id" value="<?= $sucursalId ?>">
                        <?php endif; ?>
                        <input type="hidden" name="accion" value="limpiar">
                        <button type="submit" class="btn-mini btn-secundario">Vaciar carrito</button>
                    </form>
                <?php endif; ?>
            </aside>
        </div>
    </main>

    <script>
    (function () {
        var buscar = document.getElementById('buscar');
        if (!buscar) { return; }
        buscar.addEventListener('input', function () {
            var q = buscar.value.trim().toLowerCase();
            document.querySelectorAll('#tabla-productos tbody tr').forEach(function (tr) {
                var texto = tr.getAttribute('data-buscar') || '';
                tr.hidden = q !== '' && texto.indexOf(q) === -1;
            });
        });
    }());
    </script>
</body>
</html>
