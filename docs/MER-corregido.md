# Validación del modelo — Corrección RF15 / RF08

**Proyecto:** SALCI — Sistema de Alertas Logísticas y Control de Inventario
**Documento base:** *SALCI 30%* (Evaluación 3, 9 de noviembre de 2026)
**Motivo:** observación del docente sobre la fila RF15 del informe.

---

## 1. Qué dice el documento (verificación)

La fila **RF15 – Venta POS** del informe termina literalmente así:

> "se necesita proveedor, comprador y venta en tablas independientes"

Es decir, la corrección **ya está escrita dentro del propio requerimiento**: no es una
sugerencia, es parte del alcance. Y el diagrama físico de la página 13 confirma que el
modelo entregado **no la cumple**:

- No existe la entidad `ventas`.
- `proveedores` y `compradores` no existen como entidades.
- `transacciones` guarda `proveedor_rut`, `proveedor_nombre`, `comprador_rut` y
  `comprador_nombre` como texto plano.
- `despachos_pendientes` repite `comprador_rut` y `comprador_nombre`.
- `RF08` pedía justamente lo contrario: registrar los datos de la contraparte una sola vez.

## 2. Hallazgos

| # | Hallazgo | Evidencia | Norma | Severidad |
|---|----------|-----------|-------|-----------|
| 1 | No existe la entidad `VENTAS` | MER pág. 13; RF15 | Requisito RF15 | **Alta** |
| 2 | Proveedor y comprador almacenados como texto en `transacciones` y `despachos_pendientes` | MER pág. 13 | 1FN / 3FN | **Alta** |
| 3 | `transacciones` es una tabla polimórfica: mezcla ingresos, salidas y transferencias, y para lograrlo deja `lote`, `destino`, `proveedor_*` y `comprador_*` como columnas opcionales | MER pág. 13 | 1FN (grupos repetitivos / semántica mezclada) | Alta |
| 4 | El modelo **no tiene ninguna relación N:N real**, y la pauta exige "INFORME DE TRANSACCIONES (N:N)" | Pauta Ev. 4, pág. 2 | Rúbrica de consultas SQL | **Alta** |
| 5 | Las relaciones se declaran por `sku`, pero `sku` **no es único**: se repite una vez por sucursal | MER pág. 13 (`PRODUCTOS.sku` + `sucursal_id`) | Integridad referencial | **Alta** (el script no compila) |
| 6 | `usuarios` no tiene columna de *salt*, pero RNF03 exige SHA-256 **con salting** | MER pág. 13; RNF03 | RNF03 | Media |
| 7 | `productos.movimientos` es un contador derivado de `transacciones` | MER pág. 13 | Redundancia controlada | Baja |

**El hallazgo 5 es el más grave técnicamente:** hoy el MER dibuja `LOTES.sku → PRODUCTOS.sku`
y `TRANSACCIONES.sku → PRODUCTOS.sku`, pero esa FK **no se puede crear** en SQL Server,
porque `PRODUCTOS` solo garantiza unicidad de `(sucursal_id, sku)`. Al implementar, el
script falla o queda sin integridad referencial.

**El hallazgo 4 es el que más afecta la nota:** sin `ventas` + `venta_detalle` no hay
N:N que mostrar, y la pauta pide explícitamente un informe N:N.

## 3. Corrección aplicada

### 3.1 Entidades nuevas

| Tabla | Rol | Reemplaza a |
|-------|-----|-------------|
| `proveedores` | Maestro de proveedores (RUT, nombre, teléfono, email) | `transacciones.proveedor_rut`, `transacciones.proveedor_nombre` |
| `compradores` | Maestro de compradores/clientes | `transacciones.comprador_*`, `despachos_pendientes.comprador_*` |
| `ventas` | Cabecera de la venta POS | (no existía) |
| `venta_detalle` | Líneas de la venta | (no existía) |

### 3.2 Entidades modificadas

| Tabla | Antes | Ahora |
|-------|-------|-------|
| `lotes` | `sku` (texto) | `producto_id` FK + `proveedor_id` FK |
| `transacciones` | `sku`, `lote`, `destino`, `proveedor_rut`, `proveedor_nombre`, `comprador_rut`, `comprador_nombre` | `producto_id`, `lote_id`, `venta_detalle_id`, `grupo_id`, `usuario_id` |
| `despachos_pendientes` | `sku`, `comprador_rut`, `comprador_nombre` | `producto_id`, `comprador_id`, `venta_id` |
| `registro_alertas` | `sku_producto` | `producto_id` + `stock_al_momento` |
| `usuarios` | `password_hash` | `password_hash` + `salt` |
| `productos` | `sucursal_id`, `stock`, `movimientos` | catálogo global: solo `sku` (UNIQUE), `nombre` y `stock_minimo` |
| `inventario` | (no existía) | **nueva**: `stock` por `(producto_id, sucursal_id)` |

`transacciones` deja de ser una tabla "para todo" y pasa a ser el **kardex inmutable**
(un registro por movimiento), con `CHECK` que obliga a que cada tipo tenga su documento:

- `INGRESO` → lote opcional (RF04 dice "opcionalmente").
- `SALIDA` → exige `venta_detalle_id` (no puede haber salida por venta sin línea de venta).
- `TRANSFERENCIA_SALIDA` / `TRANSFERENCIA_INGRESO` → comparten `grupo_id` (doble asiento, RF12).
- `AJUSTE` → sin documento.

## 4. Modelo resultante: 12 entidades

```
SUCURSALES ──1:N──> USUARIOS, INVENTARIO, LOTES, VENTAS,
                    TRANSACCIONES, DESPACHOS_PENDIENTES, REGISTRO_ALERTAS

PRODUCTOS ──1:N──> INVENTARIO, LOTES, VENTA_DETALLE, TRANSACCIONES,
                   DESPACHOS_PENDIENTES, REGISTRO_ALERTAS
PRODUCTOS + SUCURSALES ──N:M──> INVENTARIO (producto_id, sucursal_id, stock)

PROVEEDORES ──1:N──> LOTES
COMPRADORES ──1:N──> VENTAS, DESPACHOS_PENDIENTES

VENTAS ──1:N──> VENTA_DETALLE ──N:1──> PRODUCTOS        (N:N ventas ↔ productos)

TRANSACCIONES ──N:1──> PRODUCTOS, SUCURSALES, USUARIOS   (kardex)
```

Cardinalidades que exige la pauta: **1:N** (sucursal→productos, proveedor→lotes),
**N:N** (ventas↔productos vía `venta_detalle`; transacciones↔productos↔sucursales),
y **tabla de usuarios** del sistema. ✔

### Nota sobre transferencias inter-sucursal (RF12)

Como el stock vive en la fila de `productos` de cada sucursal, una transferencia genera
**dos filas** en `transacciones` con el mismo `grupo_id`:

```sql
-- origen
INSERT INTO transacciones(tipo, grupo_id, producto_id, sucursal_id, cantidad, stock_resultante, usuario_id)
VALUES ('TRANSFERENCIA_SALIDA', @grupo, @prod_origen, @suc_origen, @n, @stock_origen - @n, @usuario);

-- destino
INSERT INTO transacciones(tipo, grupo_id, producto_id, sucursal_id, cantidad, stock_resultante, usuario_id)
VALUES ('TRANSFERENCIA_INGRESO', @grupo, @prod_destino, @suc_destino, @n, @stock_destino + @n, @usuario);
```

## 5. Impacto en las 4 consultas de la pauta

| Consulta exigida | Tablas que la resuelven |
|------------------|-------------------------|
| 1. Valida login | `usuarios` (+ `sucursales`) |
| 2. Informe tabla 1 | `productos` / `vw_stock_en_vivo` |
| 3. Informe tabla 2 (1:N) | `proveedores → lotes` o `sucursales → productos` |
| 4. Informe de transacciones (N:N) | `ventas ↔ venta_detalle ↔ productos` (+ `compradores`, `usuarios`) |

Antes de la corrección, la consulta 4 **no tenía un N:N que demostrar**.

## 6. Validación técnica ejecutada

El script `db/01_schema.sql` se ejecutó en la instancia local
**SQL Server 2025 Express (`.\SQLEXPRESS`)** sobre una base temporal `SALCI_VALID`:

| Prueba | Resultado |
|--------|-----------|
| Creación de las 12 tablas, FKs, CHECK e índices | ✔ Sin errores |
| Índices filtrados (`rut`) y columna calculada (`subtotal`) | ✔ Crean correctamente |
| Vista `vw_stock_en_vivo` | ✔ Crea correctamente |
| INSERT de datos de prueba (2 sucursales, 2 usuarios, venta con detalle) | ✔ |
| Consulta N:N ventas ↔ productos | ✔ Devuelve la venta con su comprador y línea |
| FK huérfana (`venta_detalle.venta_id = 999`) | ✔ Rechazada por `fk_venta_detalle_venta` |
| `SALIDA` sin `venta_detalle_id` | ✔ Rechazada por `ck_transacciones_origen` |

La base de prueba se eliminó al terminar y luego se creó la base definitiva:

| Objeto | Cantidad |
|--------|----------|
| Tablas | 12 |
| Claves foráneas | 22 |
| Índices | 31 (incluye PK y `UNIQUE`) |
| Vistas | 1 (`vw_stock_en_vivo`) |

### Diagrama actualizado

`db/diagrama.dbml` contiene el modelo completo en formato **DBML**. Se pega en
<https://dbdiagram.io> (el mismo estilo de la Figura 3 original) y se exporta como PNG
para reemplazar la figura del informe.

## 7. Normalización 3FN aplicada (`productos` / `inventario`)

`PRODUCTOS` mezclaba el catálogo con el stock. Eso producía la dependencia parcial
`(sucursal_id, sku) → nombre`, que viola 2FN y 3FN (ver `docs/auditoria-3FN.md`).
Se separó en:

```
productos  (id, sku UNIQUE, nombre, stock_minimo)                 -- catálogo global
inventario (producto_id, sucursal_id, stock)                      -- stock por sede
             UNIQUE (producto_id, sucursal_id)
```

Consecuencias:

- `sku` pasa a ser clave candidata global: `sku → nombre` ya no viola 3FN.
- `stock` depende de `(producto_id, sucursal_id)` → 3FN.
- `lotes.sucursal_id` deja de ser redundante (un lote llega físicamente a una sede).
- RF11 (catálogo global) y RF12 (transferencia) se resuelven directo.
- Se eliminó `productos.movimientos`, que era un contador derivable con `COUNT` sobre
  `transacciones`.

## 8. Texto sugerido para la sección 7 del informe

> El modelo físico fue corregido a partir de la observación sobre RF15. Se independizaron
> las entidades `PROVEEDORES`, `COMPRADORES` y `VENTAS`, eliminando los atributos
> `proveedor_rut`, `proveedor_nombre`, `comprador_rut` y `comprador_nombre` que se
> almacenaban como texto en `TRANSACCIONES` y `DESPACHOS_PENDIENTES`. Con ello se elimina
> la dependencia transitiva entre el RUT de la contraparte y su nombre, se cumple la
> Tercera Forma Normal y se habilita la reutilización de los datos maestros (RF08).
> La entidad `VENTAS` se separó de `TRANSACCIONES`, que pasa a operar como kardex
> inmutable de movimientos de inventario; la relación entre ventas y productos se
> resuelve mediante `VENTA_DETALLE`, configurando la relación N:N exigida. Además,
> `PRODUCTOS` se normalizó separando el catálogo global (`sku` único) del stock físico por
> sucursal (`INVENTARIO`), con lo que se elimina la dependencia parcial
> `(sucursal_id, sku) → nombre` que impedía cumplir la Segunda Forma Normal.
