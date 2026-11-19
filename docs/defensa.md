# Guion de presentación y defensa oral

Duración sugerida: 8–10 minutos de demostración + 5 minutos de preguntas.
Todo se ejecuta sobre SQL Server local `.\SQLEXPRESS` y XAMPP. Si se pide
reiniciar: `sqlcmd -S .\SQLEXPRESS -E -C -f 65001 -i db\02_seed.sql`.

---

## 1. Introducción (30 s)

> “SALCI es un sistema de inventario y logística para tres sucursales. El
> modelo quedó en 12 tablas, normalizado hasta 3FN, con SQL Server 2025 Express
> como SGBD y PHP 8 con PDO como capa de aplicación. La base no se sube a
> GitHub: cada integrante la recrea con `01_schema.sql` y `02_seed.sql`.
> El prototipo cubre login JWT, panel por rol, POS, CRUD de las tres tablas
> exigidas, reportes y exportación CSV.”

---

## 2. Modelo de datos (1 min)

Mostrar `docs/MER-corregido.md` o `db/diagrama.dbml` y decir:

- **Tabla 1** (catálogo): `productos`.
- **Tabla 2 (1:N)**: `proveedores → lotes` y `sucursales → inventario`.
- **Tabla N:N**: `ventas ↔ productos` resuelta por `venta_detalle`.
- **Transacciones**: `transacciones` es el kardex; cada movimiento guarda
  `tipo`, `cantidad`, `stock_resultante`, `sucursal_id` y `grupo_id`.
- **Usuarios**: `usuarios` con `rol` y `sucursal_id`; ADMIN ve todas,
  OPERADOR solo la suya.
- `proveedores`, `compradores` y `ventas` son tablas independientes, como
  pide la observación del informe.
- El stock **no** está en `productos`: está en `inventario(producto_id,
  sucursal_id)` para cumplir 3FN y evitar repetir el catálogo por sede.

---

## 3. Recorrido de demostración (5–6 min)

| # | Acción en el navegador | Qué se debe señalar |
|---|---|---|
| 1 | `http://localhost/salci/login.php` → `admin` / `Salci2026!` | Login real contra SQL Server; credencial incorrecta muestra error; sin sesión redirige al login. |
| 2 | Panel principal | Muestra `DB_NAME()`, driver `sqlsrv`, versión, stock crítico y despachos pendientes. Al lado, “Token RF05 (JWT HS256)” con su vigencia de 24 h. Captura `panel-admin.png`. |
| 3 | **Punto de venta** → agregar un producto → **Cobrar** | Se crea `ventas` + `venta_detalle` en una sola transacción, descuenta `inventario`, inserta movimiento y muestra el ticket. |
| 4 | **Productos** → crear, editar, dar de baja y reactivar | CRUD de la tabla 1. Baja lógica con `activo = 0`; el POS deja de mostrarlo. Captura `productos.png`. |
| 5 | **Ingresos** → nuevo lote con proveedor → anularlo | CRUD de la tabla 2 (1:N): alta registra `INGRESO`; anular revierte con `AJUSTE` sin borrar el lote por sus FK. Captura `ingresos.png`. |
| 6 | **Despachos** → crear → `EN_RUTA` → `COMPLETADO` | RF09 con estados y `fecha_entrega`. Captura `despachos.png`. |
| 7 | **Transferencias** → origen Chillán, destino Concepción | RF12: un mismo `grupo_id` con `TRANSFERENCIA_SALIDA` + `TRANSFERENCIA_INGRESO`; stock original no cambia, se mueve entre sedes. Captura `transferencias.png`. |
| 8 | **Usuarios** y **Sucursales** | RF06/RF07: alta, edición, baja lógica; bloqueo de última cuenta ADMIN y de sucursales con usuarios/stock/movimientos. Capturas `usuarios.png`, `sucursales.png`. |
| 9 | **Ventas** → **Ver** ticket → **Anular** | CRUD de la tabla N:N: el alta viene del POS, la lectura del ticket y la baja devuelve stock con `AJUSTE`. Captura `ventas.png`. |
| 10 | **Reportes** → filtrar por sucursal → descargar CSV | RF13/RF14: dashboard, stock en vivo, tablas 1/2/N:N y CSV con BOM UTF-8. Captura `reportes.png`. |

---

## 4. Las 4 consultas SQL (1 min)

Abrir `db/03_queries.sql` y ejecutarlo con:

```bat
sqlcmd -S .\SQLEXPRESS -E -C -f 65001 -i db\03_queries.sql
```

| Pauta | Consulta | Qué demuestra |
|---|---|---|
| 1 | Validación de login | Compara `password_hash` contra `SHA-256(salt || password)` solo si `activo = 1`. |
| 2 | Informe tabla 1 | Catálogo de `productos` con estado y stock agregado por sede. |
| 3 | Informe tabla 2 (1:N) | `proveedores → lotes`, con cantidad y total por lote; segunda variante `sucursales → inventario`. |
| 4 | Informe de transacciones (N:N) | `ventas → venta_detalle → productos`, con líneas, subtotal, IVA y total. |

Las consultas de apoyo alimentan el dashboard, top 5, alertas RF02, kardex,
transferencias y los CSV.

---

## 5. Respuestas probables (3 min)

**¿Por qué 3FN y no una sola tabla con stock en `productos`?**
Porque el mismo producto existe en varias sucursales con stock distinto. Si el
stock estuviera en `productos`, se repetiría el nombre/precio por cada sede y
habría dependencias parciales. `inventario` separa la identidad del producto de
su existencia por sucursal.

**¿Cómo se evita que dos cajeros vendan el último artículo?**
`op_mover_stock()` actualiza `inventario` con `UPDLOCK, ROWLOCK`, valida
`stock >= cantidad` y escribe el movimiento dentro de la misma transacción.
Si no hay stock, se hace `ROLLBACK` y no se crea la venta.

**¿Qué pasa si falla el correo o el webhook de una alerta?**
La alerta ya quedó en `registro_alertas` y el stock ya se confirmó. RF02 se
envía **después del COMMIT** como mejor esfuerzo; un fallo externo se registra
en logs pero nunca revierte el inventario. Sin constantes configuradas no se
intenta envío.

**¿JWT o sesión PHP?**
Se emite un JWT HS256 firmado con `JWT_SECRET` que lleva `sub`, `rol`,
`suc` y `exp` a 24 h. Se guarda dentro de la sesión PHP (cookie HttpOnly y
SameSite=Lax) para no exponerlo a JavaScript; `auth_require()` valida firma y
vencimiento antes de cada página. Al vencer, cierra sesión y avisa en el login.

**¿Por qué baja lógica en vez de `DELETE`?**
Los lotes, ventas y usuarios tienen historial y foreign keys. Borrarlos
rompería el kardex o la auditoría. Por eso se usa `activo = 0`,
`lotes.anulado = 1` y `ventas.estado = 'ANULADA'`, con reversa de stock
cuando corresponde.

**¿Cómo se generó el respaldo?**
`db/04_backup.sql` ejecuta `BACKUP DATABASE SALCI TO DISK = '...\SALCI.bak'`
y luego `RESTORE VERIFYONLY`. SQL Server Express no admite `COMPRESSION`.

---

## 6. Evidencias incluidas

`docs/capturas/`: `login.png`, `panel-admin.png`, `panel-trabajador.png`,
`pos.png`, `productos.png`, `ingresos.png`, `despachos.png`,
`transferencias.png`, `usuarios.png`, `sucursales.png`, `ventas.png`,
`reportes.png`.
