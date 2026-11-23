# Requerimientos no funcionales (RNF01–RNF06)

Basado en el informe `SALCI 30%`, sección 4.3 **Requerimientos No Funcionales**
(atributos ISO 9126). Cada requisito está implementado y se puede comprobar en
cualquier instalación local con XAMPP, PHP, SQL Server Express y
`http://localhost/salci/`. El verificador detecta la instancia y la base desde
`app/config.local.php` (o, si no existe, desde `app/config.php`).

## Comprobación en un solo comando

Desde la raíz del repositorio, con Apache y SQL Server iniciados:

```powershell
powershell -ExecutionPolicy Bypass -File verificar_rnf.ps1

# También puedes indicarlos de forma explícita:
powershell -ExecutionPolicy Bypass -File verificar_rnf.ps1 -ServerInstance '.\SQLEXPRESS' -Database SALCI
```

Si no se pasan parámetros, el script lee `DB_DSN` de `config.local.php`, verifica
que la instancia responda y, si no, prueba `.\SQLEXPRESS`, `.\SQLEXPRESS01`,
`(local)\SQLEXPRESS` y `localhost\SQLEXPRESS`; elige la primera base
`SALCI`/`prueba` disponible (o la primera base de usuario que encuentre).

Comprueba automáticamente: cabecera `X-Response-Time-Ms` (RNF01), `health.php`
contra la base detectada (RNF04), login admin + JWT (RNF03), tema y pantalla de
error (RNF06), los 6 chequeos SQL de FK/CHECK/índices (RNF02/RNF05) y los 12
triggers de auditoría de RF13. Al terminar imprime
`Resultado: 7 correctos, 0 a revisar` y devuelve código 0.

Para revisar cada requisito por separado, usar los comandos de las secciones siguientes.

| ID | Atributo ISO 9126 | Implementación | Verificación |
|---|---|---|---|
| RNF01 | Eficiencia — tiempo de respuesta | `app/config.php` publica `X-Response-Time-Ms` en **todas** las respuestas y registra en el log las que superan 500 ms | `curl -I http://localhost/salci/login.php` → cabecera presente; panel la muestra en "Estado de la conexión" |
| RNF02 | Fiabilidad — integridad transaccional | Claves foráneas y CHECK nativos de SQL Server (equivalentes a `PRAGMA foreign_keys=ON`) + `op_tx()` en `app/operaciones.php` | `db/10_verificacion_rnf.sql` → 0 FK desactivadas y 0 CHECK desactivados |
| RNF03 | Funcionalidad — seguridad de acceso | SHA-256 con sal de 16 bytes en `app/operaciones.php` (`op_hash_password`) y verificación con `hash_equals` en `app/auth.php`; JWT HS256 firmado con `JWT_SECRET`, expira en 24 h y exige `alg = HS256` | Login con admin/operador; token visible en el panel |
| RNF04 | Fiabilidad — disponibilidad | `app/health.php`: `GET /salci/health.php`, público, JSON con estado de PHP y SQL Server, 200 si está bien / 503 si no | `curl -i http://localhost/salci/health.php` |
| RNF05 | Eficiencia — rendimiento de almacenamiento | Índices B-Tree (UNIQUE/CLUSTERED/NONCLUSTERED) sobre `productos.sku`, `usuarios.username` y `sucursal_id` en las tablas operativas | `db/10_verificacion_rnf.sql` → todas las filas en `OK` |
| RNF06 | Usabilidad — operabilidad y ergonomía | `app/errores.php` informa **causa + acción correctiva** en errores controlados y no controlados; diseño responsive con modo oscuro slate-blue y conmutador persistente en `app/assets/tema.js` | Provocar un error; botón ◐/◑ en la barra y en el login |

---

## RNF01 — Tiempo de respuesta

`config.php` se carga antes que cualquier otra lógica (lo incluye `db.php`, que a
su vez incluyen `auth.php` y `operaciones.php`). Guarda `microtime(true)` en
`$GLOBALS['salci_t0']` y registra un `header_register_callback` que añade la
cabecera justo antes de enviar los headers, así que funciona incluso en
respuestas que terminan con `exit` (redirects, CSV, `health.php`).

```bat
curl -I http://localhost/salci/login.php
REM X-Response-Time-Ms: 12.34
```

Objetivo: **< 500 ms en el 99%** de las transacciones. Si una respuesta pasa de
500 ms se escribe `SALCI RNF01: respuesta lenta ...` en el log de PHP; eso
permite medir el porcentaje real con el tiempo. La cabecera la emite PHP, por lo
que aparece en las respuestas dinámicas (páginas, CSV y `health.php`); los archivos
estáticos que sirve Apache directamente (`style.css`, `tema.js`) no pasan por PHP.

Para provocar la señal de alerta del objetivo se puede crear un script temporal
que duerma 650 ms:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
usleep(650000);
echo 'lento';
```

Al pedirlo con `curl -i` la cabecera marca ~650 ms y aparece esto en
`C:\xampp\apache\logs\error.log` (en este XAMPP el log de PHP está en el de
Apache, no en `php_error_log`):

```
SALCI RNF01: respuesta lenta 658.55 ms en /salci/__rnf_tiempo_tmp.php
```

Borrar el script temporal después de la prueba.

## RNF02 — Integridad transaccional

El informe pide `PRAGMA foreign_keys=ON`, que es la directiva de SQLite. En SQL
Server las FK son nativas y se aplican por defecto: **no existe** esa directiva;
el equivalente es verificar que `sys.foreign_keys.is_disabled = 0` y
`is_not_trusted = 0`. Todas las escrituras multi-tabla del prototipo pasan por
`op_tx()` (INGRESOS, DESPACHOS, transferencias, anulación de venta, POS), que
usa `beginTransaction() / commit() / rollBack()`.

El propio `verificar_rnf.ps1` hace este reemplazo. Para hacerlo a mano:

```powershell
$server = '.\SQLEXPRESS'
$db     = 'SALCI'
$sql = (Get-Content db\10_verificacion_rnf.sql -Raw).Replace('USE SALCI;', "USE $db;")
$sql | Set-Content $env:TEMP\rnf.sql -Encoding utf8
sqlcmd -S $server -E -C -b -f 65001 -i $env:TEMP\rnf.sql
```

## RNF03 — Seguridad de acceso

- Contraseñas: `op_hash_password()` genera 16 bytes aleatorios (`salt`, 32 hex)
  y guarda `SHA-256(salt_binario || password)` en 64 hex. `auth_attempt()`
  compara con `hash_equals()`.
- Autorización: JWT HS256 con `sub`, `usr`, `rol`, `suc`, `iat`, `exp` (24 h).
  Se valida la firma, el algoritmo HS256, la vigencia y que `iat` no esté en el
  futuro. Se guarda en la sesión PHP (`HttpOnly` + `SameSite=Lax`) y
  `auth_require()` fuerza el token en cada pantalla privada.
- La clave de ejemplo de `config.php` se sobreescribe en `config.local.php`
  (ignorado por Git) con una clave aleatoria de 64 caracteres hex.

## RNF04 — Disponibilidad y monitoreo

`health.php` no llama a `auth_require()`: es público, sin sesión y sin CSRF,
para que cualquier monitor pueda consultarlo.

```json
{
  "status": "ok",
  "app": "SALCI",
  "timestamp": "2026-11-19T...",
  "target": "99.9%",
  "checks": {
    "php": { "ok": true, "version": "8.x" },
    "database": { "ok": true, "driver": "sqlsrv", "name": "SALCI", "detail": null }
  },
  "response_ms": 8.42
}
```

Si SQL Server no responde, devuelve HTTP **503** con `"status": "error"`,
más `causa` y `accion` (RNF06).

Para comprobar el 503 sin detener el servicio se puede cambiar temporalmente
`Database=<tu-base>` por `Database=base_inexistente` en `config.local.php`, esperar
**más de 2 segundos** (XAMPP tiene `opcache.revalidate_freq=2`, si se pide antes
sigue sirviendo la configuración cacheada), pedir `health.php` con `curl -i` y
**restaurar el archivo enseguida**:

```
HTTP/1.1 503 Service Unavailable
Content-Type: application/json; charset=utf-8
{ "status": "error", "causa": "No se pudo establecer la conexión con SQL Server.", ... }
```

`db.php` propaga la `PDOException` en vez de terminar con `exit()`, por lo que
`health.php` puede convertirla en JSON 503; las páginas normales siguen
recibiendo la tarjeta HTML con causa y acción (HTTP 500). Con el mismo cambio
de configuración, `index.php` con sesión iniciada devuelve 500 y muestra:

```
Causa: No se pudo establecer la conexión con SQL Server.
Acción correctiva: Verifica que el servicio de tu instancia esté iniciado y que DB_DSN en config.local.php sea correcto.
```

El objetivo operativo de 99,9% se documenta aquí y se vigila con este endpoint;
el prototipo no incluye un balanceador ni failover.

## RNF05 — Rendimiento de almacenamiento

SQL Server implementa índices B-Tree con `type = 1` (CLUSTERED) y `type = 2`
(NONCLUSTERED). Ya están en `01_schema.sql`:

- `uq_productos_sku` → `productos(sku)`
- `uq_usuarios_username` → `usuarios(username)`
- `ix_inventario_sucursal`, `ix_ventas_sucursal_fecha`,
  `ix_transacciones_suc_fec`, `ix_despachos_suc_estado`,
  `ix_usuarios_sucursal`, `ix_alertas_pendientes` → `sucursal_id`

El script `db/10_verificacion_rnf.sql` lista la evidencia y marca `OK` o
`REVISAR`. No se crean índices duplicados sólo por cumplir el texto del informe.

## RNF06 — Errores claros e interfaz responsive

- **Errores:** `errores.php` instala `set_error_handler`,
  `set_exception_handler` y un `register_shutdown_function`. Toda excepción no
  atrapada muestra una tarjeta con `Causa:` y `Acción correctiva:`. Los errores
  de los formularios (que ya se atrapan con `try/catch`) reciben el mismo
  sufijo desde `render_flash_op()` en `partials.php`, y `db.php` usa el mismo
  formato si falla la conexión. En `APP_DEBUG = true` se añade el detalle
  técnico; en producción no se filtra.
- **Interfaz:** paleta oscura slate-blue (`#0b1220` / `#93c5fd`), tema claro
  opcional, botón ◐/◑ en la barra y en el login, preferencia guardada en
  `localStorage` y aplicada desde el `<head>` para evitar parpadeo. Media
  queries en 980/720/560 px, tablas con scroll horizontal y estilo de impresión
  para el comprobante POS.

### Cómo comprobar RNF06

1. Entrar a `http://localhost/salci/login.php` y pulsar ◐: cambia a claro y
   recarga manteniendo el tema.
2. Enviar un formulario con un SKU duplicado: el aviso incluye la causa y la
   acción correctiva.
3. Generar un error no atrapado (por ejemplo, renombrar temporalmente una tabla
   en una copia de pruebas) y verificar la tarjeta roja con ambos campos.
