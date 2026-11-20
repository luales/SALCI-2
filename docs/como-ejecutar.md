# Cómo ejecutar SALCI en otro equipo

## La idea en una frase

GitHub guarda **código y scripts**, no la base de datos. Cada integrante crea
su propia copia local de `SALCI` ejecutando los scripts, y todos quedan con la
misma estructura y los mismos datos de demostración.

```
GitHub (código .php + .sql)
        │  git clone
        ▼
Tu PC ──► SQL Server local ──► base SALCI (tu copia)
```

No hay una base "central" compartida: cada uno tiene la suya. Por eso una venta
que hagas tú no aparece en el equipo, salvo que trabajen sobre un servidor común
(ver sección 6).

---

## 0. Este equipo (donde ya está corriendo)

En este PC la instancia `.\SQLEXPRESS` está **detenida** y no se puede iniciar
sin permisos de administrador. La instancia en ejecución es `.\SQLEXPRESS01` y
la base de trabajo es **`prueba`** (no `SALCI`). La tabla original del curso se
conservó como `productos_antiguo`.

- Repositorio: `C:\Users\aleks\Documents\SALCI-2`
- Apache: ya corriendo; unión creada en `C:\xampp\htdocs\salci`
- URL: `http://localhost/salci/login.php`
- `app/config.local.php`: `Server=.\SQLEXPRESS01;Database=prueba` + `JWT_SECRET` propio

Para recargar el demo en este equipo:

```powershell
$sql = (Get-Content db\02_seed.sql -Raw) -replace 'USE SALCI;', 'USE prueba;'
$tmp = Join-Path $env:TEMP 'seed_prueba.sql'
$sql | Set-Content $tmp -Encoding utf8
sqlcmd -S .\SQLEXPRESS01 -E -C -b -f 65001 -i $tmp
```

El seed reinicia los `IDENTITY`, así que los IDs del demo son siempre los
mismos. Para una instalación nueva en otro PC, sigue las secciones 1–5.

---

## 1. Requisitos en cada equipo

| Componente | Detalle |
|---|---|
| XAMPP | PHP 8.x con `pdo_sqlsrv` y `sqlsrv` activos |
| SQL Server | Express, instancia `SQLEXPRESS` (o ajustar el DSN) |
| ODBC | Microsoft ODBC Driver 17 o 18 for SQL Server |
| Git | Para clonar y sincronizar |

Instalar primero el **Microsoft ODBC Driver for SQL Server** (17 o 18,
64 bits) desde el sitio de Microsoft; `pdo_sqlsrv` no funciona sin él.

Si `pdo_sqlsrv` no aparece, activar en `C:\xampp\php\php.ini`:

```ini
extension=pdo_sqlsrv
extension=sqlsrv
```

y reiniciar Apache. Verificar:

```bat
C:\xampp\php\php.exe -m | findstr sqlsrv
```

Deben aparecer `pdo_sqlsrv` y `sqlsrv`.

La autenticación de Windows funciona si Apache corre con tu usuario. Si
Apache está instalado como servicio y da `Login failed for user`, crea un login
SQL y defínelo en `config.local.php` con `DB_USER` / `DB_PASS`.

Si el servicio de SQL Server está detenido, inicia el de tu instancia desde
`services.msc` (o con `Start-Service 'MSSQL$SQLEXPRESS'` en PowerShell como
administrador).

---

## 2. Clonar el repositorio

```bash
git clone https://github.com/luales/SALCI-2.git
cd SALCI-2
```

---

## 3. Crear la base de datos

Desde la carpeta del proyecto:

```bat
sqlcmd -S .\SQLEXPRESS -E -C -i db\01_schema.sql
sqlcmd -S .\SQLEXPRESS -E -C -f 65001 -i db\02_seed.sql
```

- `01_schema.sql` crea la base `SALCI` si no existe y todas las tablas.
- `02_seed.sql` carga los datos de demostración.
- `01_schema.sql` ya incluye `productos.precio`, `productos.activo`,
  `lotes.anulado`, las columnas de auditoría de RF13 y el `ck_transacciones_origen`
  actualizado. Si tu base venía de una versión anterior, aplica una sola vez
  las migraciones `05_migracion_pos.sql`, `06_migracion_productos_activo.sql`
  y `07_migracion_lotes_anulado.sql`; para RF13 ejecuta tambien
  `08_migracion_auditoria.sql` con `-I` y luego
  `09_migracion_ajuste_origen.sql`, antes de cargar el seed.

> **Aviso:** `02_seed.sql` borra los datos actuales y los reemplaza por los de
> demo. No ejecutarlo si alguien ya cargó información que quiere conservar.

Comprobar que quedó bien:

```bat
sqlcmd -S .\SQLEXPRESS -E -C -d SALCI -Q "SELECT COUNT(*) AS tablas FROM sys.tables"
```

Debe devolver 12.

---

## 4. Publicar el prototipo

### Opción A — Apache de XAMPP

Crear una unión de directorios (una sola vez):

```bat
mklink /J "C:\xampp\htdocs\salci" "C:\ruta\completa\SALCI-2\app"
```

En este equipo la unión ya existe y apunta a
`C:\Users\aleks\Documents\SALCI-2\app`.

Iniciar Apache desde el panel de XAMPP y abrir:

```
http://localhost/salci/login.php
```

### Opción B — servidor embebido de PHP

```bat
C:\xampp\php\php.exe -S 127.0.0.1:8080 -t app
```

Abrir `http://127.0.0.1:8080/login.php`.

---

## 5. Entrar al sistema

| Usuario | Rol | Clave |
|---|---|---|
| `admin` | ADMIN (global) | `Salci2026!` |
| `supervisor` | ADMIN (Chillán) | `Salci2026!` |
| `cajero.chillan` | OPERADOR (Chillán) | `Salci2026!` |
| `cajero.conce` | OPERADOR (Concepción) | `Salci2026!` |
| `cajero.santiago` | OPERADOR (Santiago) | `Salci2026!` |

Si tu SQL Server no usa autenticación de Windows, o la instancia tiene otro
nombre:

```bat
copy app\config.local.php.example app\config.local.php
```

Editar `app\config.local.php` con tus datos. Ese archivo **no** se sube a Git.
Además de `DB_DSN`, conviene generar una clave JWT propia:

```bat
C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

y pegar el resultado en `define('JWT_SECRET', '...');` dentro de ese archivo.
Esa clave cubre el RNF03 junto con el hash SHA-256 con sal de las contraseñas.

Con Apache iniciado se puede comprobar el RNF04 sin iniciar sesión:

```
http://localhost/salci/health.php
```

Devuelve JSON con `"status": "ok"` y el nombre de la base. Si SQL Server no
responde, devuelve HTTP 503. La cabecera `X-Response-Time-Ms` (RNF01) está en
**todas** las respuestas; se ve con `curl -I http://localhost/salci/login.php`.
El detalle completo de los seis RNF está en [`rnf.md`](rnf.md).

---

## 6. Trabajo en equipo

Reglas para que la base no se desincronice:

1. **La fuente de verdad es `db/01_schema.sql`.** Si cambias una tabla, cambia
   el script y haz commit. No modifiques la base "a mano" y dejes el script
   atrás: el resto no recibe el cambio. Después de un `git pull`, si aparecen
   scripts `05_*` a `09_*`, ejecútalos una vez en tu base local.
2. **Los datos no se versionan.** Cada uno carga `02_seed.sql` cuando necesita
   volver al estado de demo.
3. **Antes de presentar**, ejecutar `02_seed.sql` para que todos vean lo mismo.
4. **No subir respaldos ni credenciales.** `.gitignore` ya excluye `*.bak`,
   logs y `config.local.php`.

Flujo normal:

```bash
git pull
# ... cambios ...
git add .
git commit -m "descripción"
git push
```

### ¿Y si queremos una sola base para todo el equipo?

Es posible, pero no es necesario para la entrega. Se necesita:

1. Un PC que actúe como servidor con SQL Server.
2. Habilitar TCP/IP en *SQL Server Configuration Manager* (puerto 1433).
3. Crear un login SQL para la aplicación (no usar `sa`).
4. Permitir el puerto en el firewall.
5. Cambiar el DSN en `config.local.php` de cada equipo:

```php
define('DB_DSN', 'sqlsrv:Server=192.168.1.50,1433;Database=SALCI;TrustServerCertificate=1');
define('DB_USER', 'salci_app');
define('DB_PASS', '...');
```

Ventaja: todos ven los mismos datos en vivo.
Desventaja: si el servidor se apaga o la red falla, nadie puede trabajar, y
dos personas editando a la vez pueden pisarse.

Para la evaluación local, la opción recomendada es **cada uno con su base**.

---

## 7. Problemas comunes

| Síntoma | Causa probable | Solución |
|---|---|---|
| HTTP 500 al entrar | No conecta a SQL Server | Revisar `DB_DSN` y que el servicio esté iniciado |
| `Error Locating Server/Instance Specified` | La instancia está detenida o el nombre es otro | Iniciar `MSSQL$SQLEXPRESS` o usar `.\SQLEXPRESS01` en `DB_DSN` |
| `Cannot open database "prueba"` | Falta cargar la base de este equipo | Ejecutar las migraciones 05–09 y el seed con `USE prueba` (§0) |
| "Tu acceso expiró (24 h)" | Venció el JWT | Entrar de nuevo; es el comportamiento esperado de RF05 |
| `An invalid keyword 'CharacterSet'` | DSN con `CharacterSet` | Quitarlo: `pdo_sqlsrv` usa UTF-8 por defecto |
| `Login failed for user` | Autenticación | Usar Windows (`DB_USER = null`) o crear login SQL |
| `Cannot open database "SALCI"` | Falta ejecutar los scripts | Ejecutar `01_schema.sql` y `02_seed.sql` |
| Apache no inicia | Puerto 80 ocupado | Detener el otro proceso o usar el servidor embebido |
| La página sale sin estilos | Unión mal creada | Revisar `C:\xampp\htdocs\salci\assets\style.css` |

---

## 8. Respaldo

El respaldo no se guarda en Git. Para generarlo en tu equipo:

```bat
sqlcmd -S .\SQLEXPRESS -E -C -i db\04_backup.sql
```

En este equipo, para respaldar la base `prueba`:

```bat
sqlcmd -S .\SQLEXPRESS01 -E -C -Q "BACKUP DATABASE prueba TO DISK = 'C:\Program Files\Microsoft SQL Server\MSSQL17.SQLEXPRESS01\MSSQL\Backup\prueba.bak' WITH INIT, CHECKSUM"
```

Restaurar (reemplaza la base actual):

```bat
sqlcmd -S .\SQLEXPRESS -E -C -Q "ALTER DATABASE SALCI SET SINGLE_USER WITH ROLLBACK IMMEDIATE"
sqlcmd -S .\SQLEXPRESS -E -C -Q "RESTORE DATABASE SALCI FROM DISK = N'C:\Program Files\Microsoft SQL Server\MSSQL17.SQLEXPRESS\MSSQL\Backup\SALCI.bak' WITH REPLACE"
sqlcmd -S .\SQLEXPRESS -E -C -Q "ALTER DATABASE SALCI SET MULTI_USER"
```
