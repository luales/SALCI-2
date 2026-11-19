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

## 1. Requisitos en cada equipo

| Componente | Detalle |
|---|---|
| XAMPP | PHP 8.x con `pdo_sqlsrv` y `sqlsrv` activos |
| SQL Server | Express, instancia `SQLEXPRESS` (o ajustar el DSN) |
| ODBC | Driver 17 for SQL Server |
| Git | Para clonar y sincronizar |

Verificar PHP:

```bat
C:\xampp\php\php.exe -m | findstr sqlsrv
```

Deben aparecer `pdo_sqlsrv` y `sqlsrv`.

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

---

## 6. Trabajo en equipo

Reglas para que la base no se desincronice:

1. **La fuente de verdad es `db/01_schema.sql`.** Si cambias una tabla, cambia
   el script y haz commit. No modifiques la base "a mano" y dejes el script
   atrás: el resto no recibe el cambio.
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

Restaurar (reemplaza la base actual):

```bat
sqlcmd -S .\SQLEXPRESS -E -C -Q "ALTER DATABASE SALCI SET SINGLE_USER WITH ROLLBACK IMMEDIATE"
sqlcmd -S .\SQLEXPRESS -E -C -Q "RESTORE DATABASE SALCI FROM DISK = N'C:\Program Files\Microsoft SQL Server\MSSQL17.SQLEXPRESS\MSSQL\Backup\SALCI.bak' WITH REPLACE"
sqlcmd -S .\SQLEXPRESS -E -C -Q "ALTER DATABASE SALCI SET MULTI_USER"
```
