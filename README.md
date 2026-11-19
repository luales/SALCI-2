# SALCI — Base de Datos

Sistema de Alertas Logísticas y Control de Inventario.
Proyecto de Bases de Datos — Evaluación 4 (hito).

**Estado:** base de datos implementada, poblada, consultada y respaldada.
Prototipo PHP: login, panel por rol, alta de compradores, venta POS (RF15),
dashboard, stock en vivo y exportación CSV (RF03/RF10/RF11/RF14) funcionando.

---

## 1. Entorno (verificado en este equipo)

| Componente | Versión / estado |
|---|---|
| XAMPP / PHP | 8.5.12 ZTS x64 (`C:\xampp`) |
| Extensiones | `pdo_sqlsrv` y `sqlsrv` cargadas |
| SQL Server | 2025 Express, instancia `SQLEXPRESS` |
| Autenticación | mixta (Windows + SQL) |
| ODBC | Driver 17 for SQL Server |

> TCP/IP está deshabilitado en la instancia: la conexión funciona por
> *shared memory* desde el mismo equipo. Suficiente para el prototipo local.
> Si se necesita conectar desde otro PC, habilitar TCP con puerto fijo 1433
> en *SQL Server Configuration Manager*.

## 2. Estructura del proyecto

```
SALCI PHP/
├─ db/
│  ├─ 01_schema.sql     12 tablas, 22 FK, 31 índices, 1 vista
│  ├─ 02_seed.sql       datos de demostración (borra y recarga)
│  ├─ 03_queries.sql    las 4 consultas de la pauta + apoyo
│  ├─ 04_backup.sql     respaldo .bak + verificación
│  ├─ 05_migracion_pos.sql  agrega productos.precio a bases v3 ya creadas
│  ├─ 06_migracion_productos_activo.sql  baja lógica de productos
│  ├─ 07_migracion_lotes_anulado.sql     anulación de ingresos (RF04)
│  └─ diagrama.dbml     modelo para dbdiagram.io (Figura 3)
├─ docs/
│  ├─ MER-corregido.md  corrección RF15/RF08 + normalización
│  ├─ auditoria-3FN.md  auditoría formal de normalización
│  ├─ como-ejecutar.md  guía para clonar y ejecutar en otro PC
│  └─ capturas/         login, panel admin y panel trabajador
└─ app/                    prototipo PHP
   ├─ config.php           DSN y parámetros
   ├─ config.local.php.example  plantilla de configuración local
   ├─ db.php               conexión PDO única
   ├─ auth.php             sesión, hash y roles
   ├─ login.php            formulario de ingreso
   ├─ index.php            panel (Hola admin / Hola trabajador)
   ├─ logout.php           cierre de sesión
   ├─ partials.php         barra de navegación y formato de dinero
   ├─ pos.php              punto de venta RF15 (carrito + cobro atómico)
   ├─ pos_ticket.php       comprobante de la venta
   ├─ comprador_nuevo.php  alta de compradores RF08
   ├─ reportes.php         dashboard, stock en vivo y tablas 1/2/N:N
   ├─ reportes_lib.php     consultas compartidas con el CSV
   ├─ export_csv.php       descargas UTF-8 con BOM (RF14)
   ├─ operaciones.php       transacciones, stock, auditoría y RF02
   ├─ productos.php        CRUD de la tabla 1 (catálogo global)
   ├─ producto_form.php    alta / edición / consulta
   ├─ producto_guardar.php INSERT/UPDATE con inventario por sucursal
   ├─ producto_estado.php  baja lógica (activo = 0)
   ├─ proveedores.php      CRUD de contrapartes RF08 (1:N con lotes)
   ├─ proveedor_guardar.php / proveedor_estado.php
   ├─ ingresos.php         altas/ediciones/anulación de lotes RF04
   ├─ ingreso_guardar.php  inserta lote + movimiento INGRESO
   ├─ lote_anular.php      reversa stock con AJUSTE
   ├─ despachos.php        CRUD de órdenes de entrega RF09
   ├─ despacho_guardar.php / despacho_estado.php
   ├─ transferencias.php   doble asiento inter-sucursal RF12
   ├─ transferencia_guardar.php
   ├─ usuarios.php         CRUD de cuentas RF06 (último ADMIN protegido)
   ├─ usuario_guardar.php / usuario_estado.php
   ├─ sucursales.php       CRUD de sedes RF07 (eliminación restringida)
   ├─ sucursal_guardar.php / sucursal_estado.php
   ├─ ventas.php           CRUD de la tabla N:N (ventas + venta_detalle)
   ├─ venta_anular.php     baja con reversa de stock y kardex
   └─ assets/style.css     estilos
```

## 3. Puesta en marcha

```bat
cd "C:\Users\gerso\Documents\SALCI PHP"

sqlcmd -S .\SQLEXPRESS -E -C -i db\01_schema.sql
sqlcmd -S .\SQLEXPRESS -E -C -f 65001 -i db\02_seed.sql
sqlcmd -S .\SQLEXPRESS -E -C -f 65001 -i db\03_queries.sql
sqlcmd -S .\SQLEXPRESS -E -C -i db\04_backup.sql
```

Para reconstruir desde cero:

```bat
sqlcmd -S .\SQLEXPRESS -E -C -Q "ALTER DATABASE SALCI SET SINGLE_USER WITH ROLLBACK IMMEDIATE; DROP DATABASE SALCI;"
sqlcmd -S .\SQLEXPRESS -E -C -i db\01_schema.sql
```

Si la base ya existía antes del POS (esquema v3), ejecutar una sola vez:

```bat
sqlcmd -S .\SQLEXPRESS -E -C -f 65001 -i db\05_migracion_pos.sql
sqlcmd -S .\SQLEXPRESS -E -C -f 65001 -i db\06_migracion_productos_activo.sql
sqlcmd -S .\SQLEXPRESS -E -C -f 65001 -i db\07_migracion_lotes_anulado.sql
```

`02_seed.sql` **borra** los datos existentes y vuelve a cargar la demo.
Es útil antes de una presentación; no ejecutar sobre datos reales.

> La base de datos no se versiona en Git: solo viajan los scripts. Cada
> integrante ejecuta `01_schema.sql` y `02_seed.sql` en su propio SQL Server.
> Guía completa para otro equipo: [`docs/como-ejecutar.md`](docs/como-ejecutar.md).

### 3.1 Abrir el prototipo

Apache de XAMPP sirve la carpeta `app/` mediante una unión de directorios:

```
C:\xampp\htdocs\salci  ->  C:\Users\gerso\Documents\SALCI PHP\app
```

Con Apache iniciado, abrir:

```
http://localhost/salci/login.php
```

- `admin` / `Salci2026!` → **Hola admin**
- `cajero.chillan` / `Salci2026!` → **Hola trabajador**

Desde el panel, **Abrir punto de venta** lleva a `pos.php`. El operador queda
fijo a su sucursal; el administrador puede cambiar de sucursal en el POS.
Para cobrar: elegir comprador (o dejar *Consumidor final*), agregar productos
y presionar **Cobrar**. El comprobante queda en `pos_ticket.php`.

**Ver reportes** abre `reportes.php`: indicadores, top 5, stock con filas
críticas en rojo, alertas, las consultas 1:N y N:N, kardex y links de descarga.

Si la unión no existe (por ejemplo, en otro equipo):

```bat
mklink /J "C:\xampp\htdocs\salci" "C:\Users\gerso\Documents\SALCI PHP\app"
```

Alternativa sin Apache (servidor embebido de PHP):

```bat
C:\xampp\php\php.exe -S 127.0.0.1:8080 -t "C:\Users\gerso\Documents\SALCI PHP\app"
```

y abrir `http://127.0.0.1:8080/login.php`.

### 3.2 Diseño de la interfaz

Tema oscuro con paneles de vidrio (*Glassmorphism*): fondo azul profundo,
halos de color de baja intensidad, desenfoque `backdrop-filter`, bordes
translúcidos y acento turquesa. El contraste se mantiene alto para reducir
la fatiga visual en jornadas largas.

Las capturas para el informe están en `docs/capturas/`:

## 4. Usuarios de demostración

| Usuario | Rol | Sucursal |
|---|---|---|
| `admin` | ADMIN | global |
| `supervisor` | ADMIN | Chillán |
| `cajero.chillan` | OPERADOR | Chillán |
| `cajero.conce` | OPERADOR | Concepción |
| `cajero.santiago` | OPERADOR | Santiago |

**Clave de todos:** `Salci2026!`

### Esquema de hash (debe coincidir en PHP)

```
salt = 16 bytes aleatorios, guardados como 32 caracteres hex
hash = SHA-256( salt_binario || password_utf8 ), guardado como 64 hex
```

```php
$salt = bin2hex(random_bytes(16));
$hash = hash('sha256', hex2bin($salt) . $password);   // minúsculas
$ok   = hash_equals($usuario['password_hash'], $hash);
```

En SQL se verifica igual:

```sql
CONVERT(CHAR(64), HASHBYTES('SHA2_256',
    CONVERT(VARBINARY(32), salt, 2)
  + CONVERT(VARBINARY(MAX), CONVERT(VARCHAR(100), @password))), 2)
```

> La conversión intermedia a `VARCHAR` es necesaria porque `NVARCHAR`
> se convierte a bytes UTF-16. Con contraseñas ASCII el resultado coincide
> con `hash('sha256', ...)` de PHP.

## 5. Conexión desde PHP

```php
$dsn = 'sqlsrv:Server=.\\SQLEXPRESS;Database=SALCI;TrustServerCertificate=1';
$pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
```

> No pasar `PDO::ATTR_TIMEOUT` ni `PDO::ATTR_PERSISTENT` en el constructor:
> este build de `pdo_sqlsrv` los rechaza. Configurarlos después de conectar
> o usar `LoginTimeout` en el DSN.
>
> Tampoco incluir `CharacterSet` en el DSN: `pdo_sqlsrv` lo rechaza y
> siempre trabaja en UTF-8.

Constantes opcionales en `app/config.php` (o `config.local.php`):

| Constante | Uso |
|---|---|
| `JWT_SECRET` | Clave HS256 del token RF05 (cambiar en producción). |
| `RF02_EMAIL_DESTINO` | Correo que recibe alertas de stock crítico. |
| `RF02_WEBHOOK_URL` | URL que recibe un `POST` JSON con la alerta. |

Si las dos constantes RF02 quedan vacías, las alertas se guardan igual en
`registro_alertas` y no se intenta envío externo.

## 6. Modelo de datos

12 tablas: `sucursales`, `usuarios`, `productos`, `inventario`,
`proveedores`, `compradores`, `lotes`, `ventas`, `venta_detalle`,
`transacciones`, `despachos_pendientes`, `registro_alertas`.

Relaciones que exige la pauta:

- **1:N** — `proveedores → lotes`, `sucursales → inventario`
- **N:N** — `ventas ↔ productos` a través de `venta_detalle`
- **Usuarios** — `usuarios` con rol y sucursal

`proveedores`, `compradores` y `ventas` son tablas independientes (observación
del profesor): la venta guarda `comprador_id`, nunca nombre ni RUT en texto.
`productos.precio` es el precio de lista; `venta_detalle.precio_unitario`
congela el precio al momento de vender, para no alterar boletas antiguas.

Detalle del modelo y su justificación en `docs/MER-corregido.md`.

### 6.1 Venta POS (RF15, RF01, RF02, RF08)

Un solo `BEGIN TRAN` hace: validar stock con `UPDLOCK`, insertar `ventas`,
insertar cada `venta_detalle`, descontar `inventario`, insertar el `SALIDA` en
`transacciones` y generar la alerta si el saldo queda bajo el mínimo. Ante
cualquier error se hace `ROLLBACK`: no queda venta a medias ni stock descontado.
El carrito se maneja en sesión y el cobro usa CSRF + *redirect-after-post*.

### 6.2 Reportes (RF03, RF10, RF11, RF14)

`reportes.php` ejecuta las mismas SQL que `db/03_queries.sql` (pautas 2, 3 y 4
más dashboard, top 5, alertas y kardex). El filtro de sucursal se aplica en
cada consulta: el OPERADOR no puede cambiarlo y el ADMIN puede elegir una sede
o ver el consolidado. `export_csv.php` usa las mismas consultas, genera BOM
UTF-8, separador `;` y escapa celdas que podrían interpretarse como fórmulas.

### 6.3 CRUD de productos (tabla 1)

`productos.php` es el ABM del catálogo global. El ADMIN crea, edita y da de baja;
el OPERADOR solo consulta y ve el stock de su sucursal (RF11). El alta crea
`inventario` en 0 para todas las sucursales activas y la baja es lógica
(`activo = 0`, migración 06) para no perder el historial de ventas y lotes.
Los renglones con `stock <= stock_minimo` se pintan de rojo (RF10).

### 6.4 Ingresos y proveedores (tabla 2, 1:N · RF04/RF08)

`proveedores.php` mantiene RUT/nombre/teléfono/correo en su propia tabla y
`ingresos.php` administra los lotes asociados. Un ingreso inserta el lote,
incrementa `inventario` con bloqueo de fila y escribe un `INGRESO` en el
kardex. Editar solo cambia metadatos; **Anular** revierte la cantidad con un
movimiento `AJUSTE` y marca `lotes.anulado = 1`, sin destruir el historial.

### 6.5 Despachos y transferencias (RF09/RF12)

`despachos.php` registra solicitudes por sucursal con comprador independiente
(RF08) y avanza `PENDIENTE → EN_RUTA → COMPLETADO`, con `fecha_entrega` y
auditoría; cancelar es una baja lógica del estado. `transferencias.php` exige
origen ≠ destino, stock suficiente en origen y crea dos asientos con el mismo
`grupo_id`: `TRANSFERENCIA_SALIDA` y `TRANSFERENCIA_INGRESO`. El operador solo
puede transferir desde su propia sucursal.

### 6.6 Usuarios y sucursales (RF06/RF07)

`usuarios.php` permite crear, editar y eliminar cuentas (baja lógica). Las
contraseñas se guardan con SHA-256 + sal de 16 bytes, igual que el seed, y
cambiar el rol a OPERADOR exige una sucursal. RF06 se respeta: no se puede
deshabilitar la propia cuenta ni al último ADMIN activo. `sucursales.php` da de
alta, edita, deshabilita y elimina sedes; la eliminación se bloquea si hay
usuarios, existencias o movimientos asociados.

### 6.7 Ventas N:N, token RF05 y notificaciones RF02

El alta de ventas se hace en el POS; `ventas.php` listado y
`pos_ticket.php` dan la lectura, y `venta_anular.php` la baja lógica:
reversa cada línea con un `AJUSTE`, actualiza el kardex y marca la venta
`ANULADA`. No se permite anular dos veces.

`auth.php` emite un JWT HS256 con `sub`, `usr`, `rol`, `suc` e `exp` a 24 h.
`auth_require()` valida la firma antes de permitir el acceso; al vencer,
cierra la sesión y muestra el aviso en `login.php`. El panel muestra la
vigencia del token.

Las alertas RF02 se envían **después del `COMMIT`** con correo/webhook
mejor esfuerzo: si el destino falla, el movimiento de stock ya confirmado no
se revierte.

## 7. Correspondencia con la rúbrica

| Indicador de la pauta | Entregable |
|---|---|
| Scripts del modelo físico y relaciones | `db/01_schema.sql` |
| Consultas SQL (login, tabla 1, 1:N, N:N) | `db/03_queries.sql` |
| Prototipo con interfaz gráfica y CRUD | `app/` (login JWT, panel por rol, CRUD de productos, lotes/proveedores, despachos, transferencias, usuarios, sucursales y ventas N:N; POS, reportes y CSV) |
| Prueba en entorno local y respaldo | `db/02_seed.sql` + `db/04_backup.sql` |
| Normalización hasta 3FN | `docs/auditoria-3FN.md` |

## 8. Respaldo

`db/04_backup.sql` genera y verifica:

```
C:\Program Files\Microsoft SQL Server\MSSQL17.SQLEXPRESS\MSSQL\Backup\SALCI.bak
```

> SQL Server Express **no** soporta `BACKUP ... WITH COMPRESSION`.
> La carpeta de respaldos requiere permisos de administrador para copiar
> el archivo a otro medio.

## 9. Evidencias y defensa

- `docs/defensa.md`: guion de presentación, recorrido de demostración y
  respuestas a las preguntas habituales de la defensa.
- `docs/capturas/`: capturas del demo limpio (login, panel, POS y los CRUD).
- `docs/MER-corregido.md` y `db/diagrama.dbml`: modelo y relaciones.
