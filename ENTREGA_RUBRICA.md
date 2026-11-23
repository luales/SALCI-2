# Evaluación 4 — SALCI: índice único de entrega y verificación

Este archivo es el **acceso directo** a todo lo que pide la pauta. Contiene la
rúbrica completa (Diseño de BD y Programación + Presentación Oral + formato APA),
el lugar exacto donde está cada evidencia en el repositorio y los comandos para
comprobarla.

> **Estado actual:** prototipo presentable, interfaz sin credenciales demo ni
> etiquetas `RF/RNF/PAUTA`, base recargada con la semilla oficial y validaciones
> en verde (funcional `67/67`, RNF `7/7`).

**Resultados de aprendizaje evaluados:**

- **RA2:** interpreta sentencias básicas de SQL y modifica datos mediante
  operaciones de consulta y manipulación, aplicándolas en escenarios
  organizacionales simulados. (CD2N2)
- **RA3:** aplica herramientas de reporting para estructurar reportes
  personalizados, considerando formatos adecuados para distintas audiencias
  organizacionales. (CD2N2)

**Condiciones de la pauta:** entrega desde el jueves 26 de noviembre; utilizar
la misma base de datos de la Evaluación 3; presentar y defender el proyecto
integrador con habilidades comunicacionales, argumentación técnica,
responsabilidad y ética profesional.

---

## 0. Accesos rápidos

| Necesito... | Abrir |
|---|---|
| Ver el sistema | `http://localhost/salci/login.php` |
| Recrear la base | [`db/01_schema.sql`](db/01_schema.sql) → [`db/02_seed.sql`](db/02_seed.sql) |
| Ver las 4 consultas de la pauta | [`db/03_queries.sql`](db/03_queries.sql) |
| Generar/verificar respaldo | [`db/04_backup.sql`](db/04_backup.sql) |
| Verificar RNF y RF13 | [`verificar_rnf.ps1`](verificar_rnf.ps1) |
| Ver evidencia SQL de RNF | [`db/10_verificacion_rnf.sql`](db/10_verificacion_rnf.sql) |
| Guion para defender | [`docs/defensa.md`](docs/defensa.md) |
| Instalar en otro equipo | [`docs/como-ejecutar.md`](docs/como-ejecutar.md) |
| Modelo y relaciones | [`docs/MER-corregido.md`](docs/MER-corregido.md) · [`db/diagrama.dbml`](db/diagrama.dbml) |
| Normalización 3FN | [`docs/auditoria-3FN.md`](docs/auditoria-3FN.md) |
| Capturas del prototipo | [`docs/capturas/`](docs/capturas/) |
| Diagramas editables | [`docs/figuras/`](docs/figuras/) |
| Alertas RF02 | [`docs/rf02-resend.md`](docs/rf02-resend.md) |

**Comandos de comprobación desde la raíz del repo:**

```powershell
# 1) Base limpia con los datos de la pauta
sqlcmd -S .\SQLEXPRESS -E -C -b -f 65001 -i db\02_seed.sql

# 2) RNF01-RNF06 + RF13 (imprime "Resultado: 7 correctos, 0 a revisar")
powershell -ExecutionPolicy Bypass -File verificar_rnf.ps1

# 3) Las 4 consultas exigidas
sqlcmd -S .\SQLEXPRESS -E -C -b -f 65001 -I -i db\03_queries.sql

# 4) Evidencia SQL de FK/CHECK/índices
sqlcmd -S .\SQLEXPRESS -E -C -b -f 65001 -i db\10_verificacion_rnf.sql
```

Para otra instancia/base, no editar `config.php`: copiar
`app/config.local.php.example` a `app/config.local.php`, generar `JWT_SECRET` y
ajustar `DB_DSN`. El verificador también acepta
`-ServerInstance '.\SQLEXPRESS01' -Database 'prueba'`.

---

## 1. Actividades a entregar

| Actividad de la pauta | Dónde está |
|---|---|
| 1. Scripts de creación y relaciones en SGBD | [`db/01_schema.sql`](db/01_schema.sql), migraciones [`db/05_*`](db/) a [`db/11_*`](db/), [`db/diagrama.dbml`](db/diagrama.dbml) |
| 2. Consultas SQL requeridas | [`db/03_queries.sql`](db/03_queries.sql), [`app/reportes_lib.php`](app/reportes_lib.php) |
| 3. Prototipo funcional con interfaz gráfica | [`app/`](app/), [`docs/capturas/`](docs/capturas/) |
| 4. Presentación oral y defensa | [`docs/defensa.md`](docs/defensa.md), este archivo §5 |

---

## 2. Rúbrica — Diseño de BD y Programación

| # | Indicador de la pauta | Nivel “Excelente” | Evidencia en el repo | Cómo verificarlo | Estado |
|---|---|---|---|---|---|
| 1 | **Scripts (modelo físico) de creación y relaciones funcionales en SGBD. Base con tablas que soporten el negocio; relaciones 1:N, N:N y tabla de usuarios del sistema.** | Funcionan correctamente, bien estructurados y optimizados. | [`db/01_schema.sql`](db/01_schema.sql): 12 tablas, 22 FK, 31 índices, 1 vista y 12 triggers RF13. [`docs/MER-corregido.md`](docs/MER-corregido.md), [`docs/auditoria-3FN.md`](docs/auditoria-3FN.md). Usuarios con `rol` y `sucursal_id`. | Crear una BD temporal y ejecutar `01_schema.sql`: da 12 tablas / 22 FK / 12 triggers. | **OK** |
| 2 | **Consultas SQL correctas y alineadas a necesidades: (1) valida login, (2) informe tabla 1, (3) informe tabla 2 1:N, (4) informe de transacciones N:N.** | Correctas, completas, optimizadas y alineadas. | [`db/03_queries.sql`](db/03_queries.sql) con las 4 consultas; [`app/reportes.php`](app/reportes.php) y [`app/reportes_lib.php`](app/reportes_lib.php) las reutilizan. | `sqlcmd ... -i db\03_queries.sql` → salida sin errores; login devuelve `credencial_valida = 1`. | **OK** |
| 3 | **Prototipo con interfaz gráfica clara y funcional. Incluye acceso y navegación dentro del sistema.** | Completa, clara, amigable y coherente. | [`app/login.php`](app/login.php), [`app/partials.php`](app/partials.php) (barra de navegación), [`app/index.php`](app/index.php), [`app/assets/style.css`](app/assets/style.css), [`docs/capturas/`](docs/capturas/). | `login.php` 200; login admin → panel; tema oscuro persistente. Sin textos demo en la UI. | **OK** |
| 4 | **Operaciones CRUD básicas sin errores: (1) tabla 1, (2) tabla 2 1:N, (3) tabla de transacciones N:N.** | CRUD completas, funcionales y sin errores. | Tabla 1: [`app/productos.php`](app/productos.php). 1:N: [`app/ingresos.php`](app/ingresos.php), [`app/proveedores.php`](app/proveedores.php), [`app/sucursales.php`](app/sucursales.php). N:N: [`app/ventas.php`](app/ventas.php), [`app/pos.php`](app/pos.php), [`app/venta_anular.php`](app/venta_anular.php). | Recorrido de 10 pasos en [`docs/defensa.md`](docs/defensa.md) §3. Batería funcional local: **67/67**. | **OK** |
| 5 | **Prueba el prototipo en entorno local y describe su implementación y respaldo.** | Prueba completa y describe claramente implementación y respaldo básico. | [`README.md`](README.md) §3, [`docs/como-ejecutar.md`](docs/como-ejecutar.md), [`db/02_seed.sql`](db/02_seed.sql), [`db/04_backup.sql`](db/04_backup.sql), [`verificar_rnf.ps1`](verificar_rnf.ps1). | Comandos del §0. Respaldo generado y validado con `RESTORE VERIFYONLY`. Base final: 3/8/13/7/3 (sucursales/productos/lotes/ventas/despachos). | **OK** |

### 2.1 Las 4 consultas, una por una

| Pauta | Consulta | Archivo / sección |
|---|---|---|
| 1 | Valida login contra `SHA-256(salt ‖ password)` y `activo = 1` | [`db/03_queries.sql`](db/03_queries.sql) → `[PAUTA 1]` |
| 2 | Informe tabla 1: catálogo con stock por sucursal y semáforo | [`db/03_queries.sql`](db/03_queries.sql) → `[PAUTA 2]` |
| 3 | Informe tabla 2 (1:N): proveedores → lotes y sucursales → inventario | [`db/03_queries.sql`](db/03_queries.sql) → `[PAUTA 3]` |
| 4 | Informe de transacciones (N:N): ventas → detalle → productos | [`db/03_queries.sql`](db/03_queries.sql) → `[PAUTA 4]` |

### 2.2 CRUD por tabla

| Tipo | Alta | Consulta | Edición | Baja/reversa |
|---|---|---|---|---|
| Tabla 1 — `productos` | [`app/producto_form.php`](app/producto_form.php) | [`app/productos.php`](app/productos.php) | [`app/producto_form.php`](app/producto_form.php) | [`app/producto_estado.php`](app/producto_estado.php) (baja lógica) |
| 1:N — lotes/proveedores/sucursales | [`app/ingreso_guardar.php`](app/ingreso_guardar.php) | [`app/ingresos.php`](app/ingresos.php), [`app/proveedores.php`](app/proveedores.php) | [`app/ingresos.php`](app/ingresos.php) (`?editar=`) | [`app/lote_anular.php`](app/lote_anular.php) (ajuste de stock) |
| N:N — ventas/transacciones | [`app/pos.php`](app/pos.php) | [`app/ventas.php`](app/ventas.php), [`app/reportes.php`](app/reportes.php) | [`app/pos_ticket.php`](app/pos_ticket.php) (consulta del comprobante) | [`app/venta_anular.php`](app/venta_anular.php) (devuelve stock y escribe ajuste) |

---

## 3. Requerimientos RF/RNF del informe (respaldo de la pauta)

El informe pide RF01–RF15 y RNF01–RNF06. Estos son los puntos de control rápidos:

| Bloque | Evidencia |
|---|---|
| RF01 stock atómico, RF02 alertas, RF12 transferencias, RF13 auditoría | [`app/operaciones.php`](app/operaciones.php), [`db/11_migracion_auditoria_auto.sql`](db/11_migracion_auditoria_auto.sql) |
| RF03 dashboard por sucursal, RF10 stock en vivo, RF11 segmentación, RF14 CSV | [`app/reportes_lib.php`](app/reportes_lib.php), [`app/reportes.php`](app/reportes.php) |
| RF04–RF05 lotes y contrapartes, RF06 usuarios, RF07 sucursales, RF08 proveedores/compradores, RF09 despachos, RF15 POS | [`app/`](app/) |
| RNF01 tiempo de respuesta | Cabecera `X-Response-Time-Ms` en [`app/config.php`](app/config.php) |
| RNF02 FK/CHECK + transacciones | [`db/10_verificacion_rnf.sql`](db/10_verificacion_rnf.sql), `op_tx()` |
| RNF03 SHA-256 con sal + JWT HS256 | [`app/auth.php`](app/auth.php) |
| RNF04 disponibilidad / health | [`app/health.php`](app/health.php) |
| RNF05 índices B-Tree | [`db/01_schema.sql`](db/01_schema.sql), [`db/10_verificacion_rnf.sql`](db/10_verificacion_rnf.sql) |
| RNF06 usabilidad y errores | [`app/errores.php`](app/errores.php), [`app/assets/tema.js`](app/assets/tema.js) |

Nota: el informe menciona `PRAGMA foreign_keys=ON`, que es sintaxis de SQLite. En
SQL Server las claves foráneas son nativas y se comprueban en
[`db/10_verificacion_rnf.sql`](db/10_verificacion_rnf.sql). Está aclarado en
[`docs/rnf.md`](docs/rnf.md) y en [`docs/defensa.md`](docs/defensa.md) §5b.

---

## 4. Formato de informe APA — checklist

| Requisito APA de la pauta | Estado / dónde |
|---|---|
| Portada: universidad, facultad, asignatura, título, integrantes, docente, fecha, número de página | Aplicado en el PDF entregado `SALCI 30%.pdf` (pág. 1) |
| Times New Roman 12, interlineado doble, márgenes 2,54 cm, sangría, justificado, numeración arriba derecha | Aplicado en el PDF entregado; usar como checklist para el PDF final |
| 1. Resumen (150–250 palabras) | PDF pág. 2 |
| 2. Introducción | PDF pág. 3 |
| 3. Análisis de la problemática | PDF pág. 4 |
| 4. Actores y requerimientos | PDF pág. 5 (RF/RNF), completar con este archivo §3 |
| 5. Diagramas de caso de uso | PDF pág. 9 · [`docs/figuras/figura1-casos-de-uso.mmd`](docs/figuras/figura1-casos-de-uso.mmd) |
| 6. Diagrama BPMN | PDF págs. 11–12 · [`docs/figuras/figura2-bpmn.mmd`](docs/figuras/figura2-bpmn.mmd) |
| 7. MER normalizado | PDF págs. 13–14 · [`docs/figuras/figura3-mer.mmd`](docs/figuras/figura3-mer.mmd), [`db/diagrama.dbml`](db/diagrama.dbml), [`docs/auditoria-3FN.md`](docs/auditoria-3FN.md) |
| 8. Implementación de base de datos | Tomar de [`db/01_schema.sql`](db/01_schema.sql), [`db/03_queries.sql`](db/03_queries.sql), [`README.md`](README.md) §5–6 |
| 9. Prototipo con interfaz gráfica | Tomar de [`app/`](app/) y [`docs/capturas/`](docs/capturas/) |
| 10. Pruebas en entorno local y respaldo | Tomar de [`docs/como-ejecutar.md`](docs/como-ejecutar.md), [`db/02_seed.sql`](db/02_seed.sql), [`db/04_backup.sql`](db/04_backup.sql), este archivo §0 y §6 |
| 11. Conclusiones | PDF pág. 14 |
| 12. Referencias APA | PDF pág. 16 |

> El PDF adjunto corresponde al avance del 30 %. Las secciones 8, 9 y 10 se
> entregan desde el repositorio con este índice; basta copiar ese contenido al
> documento final de Word/PDF antes de la entrega.

---

## 5. Rúbrica — Presentación Oral del Sistema

### 5.1 Criterios evaluados

| Criterio de la pauta | Evidencia para decir en voz alta | Estado |
|---|---|---|
| Presentación clara, estructurada y profesional | [`docs/defensa.md`](docs/defensa.md) §1–3: intro 30 s, modelo 1 min, recorrido 5–6 min | **OK** |
| Argumenta decisiones de diseño y programación con fundamentos | [`docs/defensa.md`](docs/defensa.md) §2, §4, §5, §5b; [`docs/auditoria-3FN.md`](docs/auditoria-3FN.md) | **OK** |
| Cumple plazos y evidencia responsabilidad grupal | [`docs/defensa.md`](docs/defensa.md) §7 y [`README.md`](README.md) §3 | **OK** |
| Demuestra comportamiento ético y respeto al equipo | [`docs/defensa.md`](docs/defensa.md) §7: datos ficticios, secretos no versionados, copia local antes de probar | **OK** |
| Respeta el margen de tiempo | Guion de **8–10 min** + **5 min** de preguntas en [`docs/defensa.md`](docs/defensa.md) | **OK** |
| Explica factibilidad en infraestructura local | [`docs/defensa.md`](docs/defensa.md) §7; [`docs/como-ejecutar.md`](docs/como-ejecutar.md) | **OK** |

### 5.2 Pauta de presentación oral (aspectos)

| Aspecto | Sección de [`docs/defensa.md`](docs/defensa.md) |
|---|---|
| Introducción (objetivo y contexto) | §1 |
| Análisis y requerimientos | §5 y §5b |
| Diseño (casos de uso, especificaciones, BPMN) | §2 |
| Modelo de datos y normalización | §2 y §5 |
| Implementación (scripts, consultas, prototipo) | §3 y §4 |
| Factibilidad y respaldo | §7 |
| Comunicación y manejo de tiempo | Encabezado y §3 |
| Trabajo grupal y ética | §7 |

---

## 6. Resultado de las verificaciones

| Verificación | Comando | Resultado |
|---|---|---|
| Funcionalidad RF01–RF15 | Batería local de HTTP + SQL sobre la semilla | `PASS=67 FAIL=0` |
| Requerimientos no funcionales | `powershell -ExecutionPolicy Bypass -File verificar_rnf.ps1` | `7 correctos, 0 a revisar` |
| Consultas de la pauta | `sqlcmd -S .\SQLEXPRESS -E -C -b -f 65001 -I -i db\03_queries.sql` | `EXIT=0` |
| Esquema desde cero | Ejecutar `db/01_schema.sql` en una BD temporal | 12 tablas, 22 FK, 12 triggers |
| Sintaxis PHP | `php -l` sobre `app/` | 42 archivos sin errores |
| Respaldo | `sqlcmd -S .\SQLEXPRESS -E -C -b -f 65001 -i db\04_backup.sql` | `BACKUP DATABASE` + `RESTORE VERIFYONLY` OK |
| Estado de la BD demo | Consulta de conteos | 3 sucursales, 5 usuarios, 8 productos, 3 proveedores, 4 compradores, 13 lotes, 7 ventas, 17 detalles, 33 movimientos, 13 inventario, 2 alertas, 3 despachos |
| Interfaces web | `login.php`, panel admin, `health.php` | HTTP 200; sin credenciales demo ni etiquetas de prueba en el HTML |

---

## 7. Checklist antes de entregar

- [ ] Abrir `http://localhost/salci/login.php` y entrar con el usuario del docente.
- [ ] Confirmar que la UI no muestra usuarios demo, claves ni etiquetas `RF/RNF/PAUTA`.
- [ ] Ejecutar `db\02_seed.sql` para dejar solo los datos de la pauta.
- [ ] Ejecutar `verificar_rnf.ps1` → `7 correctos, 0 a revisar`.
- [ ] Ejecutar `db\03_queries.sql` → sin errores.
- [ ] Mostrar `docs/defensa.md` y este archivo durante la defensa.
- [ ] Verificar que `app/config.local.php` y los `.bak` **no** estén en Git.
