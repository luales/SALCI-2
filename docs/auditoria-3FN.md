# Auditoría de normalización — Tercera Forma Normal (3FN)

**Proyecto:** SALCI
**Documento auditado:** *SALCI 30%*, sección 7 "MER normalizado", pág. 14
**Modelo evaluado:** el de la Evaluación 3 (7 tablas) y el corregido v3 (12 tablas)

---

## 0. Resumen

El informe afirma que la base está "normalizada hasta la Tercera Forma Normal (3FN)".
**Esa afirmación no se sostiene** con el modelo que el propio informe muestra.

| Modelo | ¿Cumple 3FN? | Detalle |
|--------|--------------|---------|
| Original (7 tablas) | **No** | 3 de 7 tablas violan 2FN/3FN |
| Corregido v2 (11 tablas) | **Casi** | `productos` seguía violando 2FN y 3FN |
| **Corregido v3 (12 tablas)** | **Sí** | `productos` + `inventario`; quedan solo redundancias históricas justificadas |

La corrección que pidió el profesor (proveedores, compradores y ventas independientes)
**es precisamente la corrección de 3FN** que el informe decía tener y no tenía.

---

## 1. Criterio aplicado

Se usan las definiciones formales, no la intuición:

- **1FN**: todos los atributos son atómicos y no hay grupos repetitivos.
- **2FN**: 1FN + ningún atributo no primo depende de **una parte** de una clave candidata.
- **3FN**: 2FN + para toda dependencia funcional no trivial `X → A`, se cumple que
  `X` es superclave **o** `A` es primo.

Por eso, para cada tabla hay que declarar primero sus **claves candidatas** y luego sus
**dependencias funcionales (FD)**. Una tabla "con ID autoincremental" no está en 3FN por
tener ID: eso no dice nada sobre las dependencias.

---

## 2. Lo que afirma el informe (pág. 14) y por qué falla

> "1FN: Se establecieron claves primarias autoincrementales únicas (id) en todas las
> tablas, garantizando atributos únicos y evitando grupos repetitivos entre sí."

La 1FN trata de **atomicidad y grupos repetitivos**, no de claves primarias. Tener
`IDENTITY` no demuestra 1FN. El modelo sí cumple 1FN, pero por otra razón: no hay
atributos multivaluados. La afirmación es verdadera por accidente y el argumento es falso.

> "2FN: Se crearon tablas independientes (sucursales, productos) para eliminar las
> dependencias funcionales parciales derivadas de las transacciones."

La 2FN se refiere a dependencias parciales **respecto de una clave compuesta**. Crear
tablas no elimina nada por sí solo, y además **`productos` sí tiene una dependencia
parcial** (ver 3.1).

> "3FN: Se eliminó la duplicación de datos separando REGISTRO_ALERTAS y LOTES en sus
> propias tablas..."

Separar lotes y alertas evita **grupos repetitivos** (eso es 1FN), no dependencias
transitivas. Y las dependencias transitivas que sí existían **no se corrigieron**:
`proveedor_rut → proveedor_nombre` y `comprador_rut → comprador_nombre`.

**Conclusión de la verificación:** el informe usa el vocabulario correcto pero aplica las
pruebas equivocadas. La sección 7 debe reescribirse.

---

## 3. Auditoría del modelo original (7 tablas)

### 3.1 `productos` — viola 2FN y 3FN

```
productos(id PK, sku, nombre, stock, stock_minimo, movimientos, sucursal_id, ...)
UNIQUE (sucursal_id, sku)
```

Claves candidatas: `{id}` y `{(sucursal_id, sku)}`.

Dependencia funcional de negocio:

```
sku → nombre        (el nombre de un SKU es el mismo en todas las sucursales)
```

- **2FN**: `nombre` depende de `sku`, que es **parte** de la clave candidata
  `(sucursal_id, sku)`. Dependencia parcial → **viola 2FN**.
- **3FN**: `sku` no es superclave y `nombre` no es primo → **viola 3FN**.

El síntoma visible: el nombre del producto se repite una vez por sucursal.

### 3.2 `transacciones` — viola 3FN

```
transacciones(id PK, sku, tipo, cantidad, stock_resultante, lote, destino,
              sucursal_id, fecha, created_by,
              proveedor_rut, proveedor_nombre,
              comprador_rut, comprador_nombre)
```

Clave candidata: `{id}` (clave simple, por lo tanto cumple 2FN).

Dependencias funcionales:

```
proveedor_rut → proveedor_nombre
comprador_rut → comprador_nombre
```

Ninguno de los determinantes es superclave y los dependientes no son primos →
**viola 3FN**. Es una dependencia transitiva: `id → proveedor_rut → proveedor_nombre`.

El síntoma: el nombre del mismo proveedor se repite en cada transacción, y si cambia hay
que actualizarlo en todas (anomalía de actualización).

### 3.3 `despachos_pendientes` — viola 3FN

```
despachos_pendientes(id PK, sku, destino, cantidad, sucursal_id, estado,
                     fecha_creacion, created_by, comprador_rut, comprador_nombre)
```

Misma dependencia: `comprador_rut → comprador_nombre` → **viola 3FN**.

### 3.4 Resto del modelo original

| Tabla | Claves candidatas | FD problemática | Veredicto |
|-------|-------------------|-----------------|-----------|
| `sucursales` | `{id}`, `{nombre}` | — | ✔ 3FN |
| `usuarios` | `{id}`, `{username}` | — | ✔ 3FN |
| `lotes` | `{id}` | — | ✔ 3FN (su problema es de FK, no de normalización) |
| `registro_alertas` | `{id}` | — | ✔ 3FN |

### 3.5 Resultado

**3 de 7 tablas violan la 3FN.** El informe no puede afirmar que el modelo está en 3FN.

---

## 4. Auditoría del modelo corregido v3 (12 tablas)

### 4.1 Lo que quedó resuelto

| Tabla | FD que antes violaba | Estado |
|-------|----------------------|--------|
| `proveedores` | `proveedor_rut → nombre, telefono, email` | ✔ `rut` es clave candidata, no hay violación |
| `compradores` | `comprador_rut → nombre, telefono, email` | ✔ idem |
| `transacciones` | datos de contraparte | ✔ eliminados; solo quedan FK |
| `despachos_pendientes` | datos de comprador | ✔ eliminados; FK `comprador_id` |

Resultado: **las tres violaciones reales del modelo original están corregidas.**

### 4.2 Lo que sigue pendiente

| Tabla | Claves candidatas | Observación | Estado |
|-------|-------------------|-------------|--------|
| `productos` | `{id}`, `{sku}` | `sku → nombre` pero `sku` **es** clave candidata | ✔ 3FN |
| `inventario` | `{id}`, `{(producto_id, sucursal_id)}` | `stock` depende de la clave completa | ✔ 3FN |
| `lotes` | `{id}` | `sucursal_id` ya no es derivable del producto | ✔ 3FN |
| `ventas` | `{id}` | `usuario_id → sucursal_id` | ⚠ snapshot histórico justificado |
| `despachos_pendientes` | `{id}` | `venta_id → sucursal_id, comprador_id` cuando hay venta | ⚠ justificable |
| `transacciones` | `{id}` | `lote_id → producto_id` y `venta_detalle_id → producto_id` | ⚠ parcial, propio del kardex |
| `venta_detalle` | `{id}`, `{(venta_id, producto_id)}` | `subtotal` es calculado | ⚠ columna calculada |
| `sucursales`, `usuarios`, `proveedores`, `compradores`, `registro_alertas` | — | — | ✔ 3FN |

**Conclusión:** el modelo v3 corrige las violaciones del modelo original y también la
dependencia parcial de `productos`. Las tablas restantes solo conservan instantáneas
históricas, que no son dependencias transitivas entre atributos no clave.

---

## 5. El hallazgo de `productos` (ya corregido)

El problema no es el nombre de una columna, es que la tabla **mezclaba dos hechos distintos**:

1. El **catálogo** del producto (SKU, nombre, umbral mínimo).
2. El **stock físico** de ese producto en una sucursal.

Solución aplicada en la base `SALCI`:

```
productos  (id, sku UNIQUE, nombre, stock_minimo, audit)
inventario (id, producto_id, sucursal_id, stock, audit)
             UNIQUE (producto_id, sucursal_id)
```

Con eso:

- `sku → nombre` queda en `productos`, donde `sku` **es** clave candidata → 3FN ✔
- `stock` depende de `(producto_id, sucursal_id)` → 3FN ✔
- `lotes.sucursal_id` deja de ser redundante (un lote llega físicamente a una sede) ✔
- RF11 ("catálogo global") se resuelve sin duplicar nombres ✔
- RF12 (transferencia) es un `UPDATE` a dos filas de `inventario` ✔

**Impacto técnico: bajo.** Todas las FK ya usaban `producto_id`; solo cambió `productos`
(se le quitaron `sucursal_id`, `stock` y `movimientos`), se agregó `inventario` y se
reescribió la vista `vw_stock_en_vivo`.

---

## 6. Redundancias que sí son defendibles

No todo lo que repite un dato viola 3FN. Estas son **desnormalizaciones controladas**,
y conviene declararlas explícitamente en el informe:

| Columna | Por qué se conserva |
|---------|---------------------|
| `ventas.sucursal_id` | La venta debe conservar la sede donde ocurrió, aunque el operador sea reasignado después. Es un hecho histórico, no un dato derivado. |
| `transacciones.stock_resultante` | Snapshot del kardex: permite auditar el saldo de cada movimiento sin recalcular toda la historia. |
| `transacciones.producto_id`, `sucursal_id` | Necesarios para AJUSTE y TRANSFERENCIA, que no tienen documento de origen. |
| `inventario.stock` | Saldo operativo para el POS; se actualiza en la misma transacción. El kardex es la fuente de verdad. |
| `ventas.subtotal/iva/total` | Valor emitido al cliente. Si mañana cambia un precio, la venta histórica no debe cambiar. |
| `venta_detalle.subtotal` | Columna calculada: el SGBD garantiza que no se desincronice. |
| `registro_alertas.stock_al_momento` | Evidencia del momento en que se disparó la alerta. |

`productos.movimientos` se eliminó: era un contador derivable con `COUNT` sobre
`transacciones`, sin valor histórico propio.

---

## 7. Texto corregido para la sección 7 del informe

> El modelo fue verificado aplicando las definiciones formales de 1FN, 2FN y 3FN sobre
> las claves candidatas de cada relación.
>
> **1FN.** Todos los atributos son atómicos. Los lotes de recepción y las alertas se
> separaron de `PRODUCTOS` porque un producto puede tener N ingresos y N alertas; si se
> hubieran modelado dentro del catálogo habría grupos repetitivos.
>
> **2FN.** Se verificó que ningún atributo no primo dependa de una parte de una clave
> compuesta. La tabla `PRODUCTOS` se dividió en `PRODUCTOS` (catálogo global, con `sku`
> como clave candidata) e `INVENTARIO` (stock por sucursal, determinado por la clave
> compuesta `producto_id + sucursal_id`), eliminando la dependencia parcial `sku → nombre`.
>
> **3FN.** Se eliminaron las dependencias transitivas del modelo anterior. En
> `TRANSACCIONES` y `DESPACHOS_PENDIENTES`, el nombre de la contraparte dependía del RUT y
> no de la clave del movimiento (`proveedor_rut → proveedor_nombre`,
> `comprador_rut → comprador_nombre`). Se independizaron `PROVEEDORES` y `COMPRADORES`,
> dejando en las tablas operativas únicamente las claves foráneas.
>
> **Desnormalizaciones controladas.** Se conservan algunos atributos derivados por
> razones de auditoría e integridad histórica: `stock_resultante` en el kardex,
> `stock_al_momento` en las alertas y los totales en `VENTAS`. No constituyen
> dependencias transitivas entre atributos no clave, sino instantáneas del hecho
> registrado, y se actualizan dentro de la misma transacción SQL.

---

## 8. Preguntas que puede hacer el docente (y la respuesta)

| Pregunta | Respuesta |
|----------|-----------|
| ¿Por qué `ventas.sucursal_id` si `usuarios.sucursal_id` ya la dice? | Porque el operador puede ser reasignado; la venta debe conservar la sede donde ocurrió. Es un hecho histórico, no un derivado. |
| ¿Por qué guardar `stock` si se puede sumar el kardex? | Porque el POS necesita el saldo en tiempo real (RNF01, < 500 ms) y el kardex es la fuente de verdad para auditoría. Se actualizan en la misma transacción. |
| ¿Por qué `stock_resultante` si se recalcula? | Para auditar cada movimiento sin recorrer toda la historia. Si el kardex y el saldo difieren, el campo permite detectar el punto exacto. |
| ¿Por qué el total en `ventas` si es la suma del detalle? | Porque es el monto emitido al cliente. Un cambio futuro de precios no debe alterar una venta pasada. |
| ¿`transacciones` no es una tabla "para todo"? | No: es un kardex, un registro por movimiento. Los `CHECK` obligan a que cada tipo tenga su documento (`SALIDA` exige `venta_detalle_id`, `INGRESO` admite `lote_id`, las transferencias comparten `grupo_id`). |
| ¿Por qué `productos` tiene `sku` único y no `id` como clave natural? | Se usa clave sustitutiva `id` para las FK y `sku` como clave candidata de negocio, que es la que garantiza la unicidad del catálogo. |
