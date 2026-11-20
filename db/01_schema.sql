/* =====================================================================
   PROYECTO SALCI - Base de Datos
   Script 01: Esquema fisico (SQL Server 2025 Express / SQLEXPRESS)
   Version 3 - Correccion RF15/RF08 + normalizacion 3FN (productos / inventario)

   PROVEEDORES, COMPRADORES y VENTAS pasan a ser tablas independientes.
   Se elimina la duplicacion de datos de contraparte en las transacciones.

   Cambios respecto del modelo de la Evaluacion 3:
     + proveedores        (nueva)  RF08
     + compradores        (nueva)  RF08 / RF09
     + ventas             (nueva)  RF15
     + venta_detalle      (nueva)  RF15  -> relacion N:N ventas-productos
     ~ lotes              sku -> producto_id ; + proveedor_id
     ~ transacciones      -proveedor_rut, -proveedor_nombre
                          -comprador_rut, -comprador_nombre, -destino, -lote
                          +producto_id, +lote_id, +venta_detalle_id, +grupo_id
     ~ despachos_pendientes  -comprador_rut, -comprador_nombre
                          +comprador_id, +venta_id, +producto_id
     ~ registro_alertas   sku_producto -> producto_id ; + stock_al_momento
     ~ usuarios           + salt (RNF03: SHA-256 con salting)
     ~ productos          -sucursal_id, -stock, -movimientos -> catalogo global
     + inventario         (nueva)  -> stock por sucursal (2FN / 3FN)

   Ejecutar con:  sqlcmd -S .\SQLEXPRESS -E -C -i db\01_schema.sql
   ===================================================================== */

/* Requeridos por el indice filtrado y la columna calculada persistida */
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_PADDING ON;
SET ANSI_WARNINGS ON;
SET CONCAT_NULL_YIELDS_NULL ON;
SET ARITHABORT ON;
SET NUMERIC_ROUNDABORT OFF;
GO

IF DB_ID('SALCI') IS NULL
BEGIN
    CREATE DATABASE SALCI;
END;
GO

USE SALCI;
GO

/* ---------------------------------------------------------------------
   1. SUCURSALES
   --------------------------------------------------------------------- */
CREATE TABLE sucursales (
    id          INT IDENTITY(1,1) NOT NULL,
    nombre      NVARCHAR(120)     NOT NULL,
    direccion   NVARCHAR(200)     NULL,
    activa      BIT               NOT NULL CONSTRAINT df_sucursales_activa  DEFAULT (1),
    created_at  DATETIME2(0)      NOT NULL CONSTRAINT df_sucursales_creat   DEFAULT (SYSDATETIME()),
    created_by  NVARCHAR(50)      NULL,
    updated_at  DATETIME2(0)      NULL,
    updated_by  NVARCHAR(50)      NULL,
    CONSTRAINT pk_sucursales       PRIMARY KEY (id),
    CONSTRAINT uq_sucursales_nombre UNIQUE (nombre)
);
GO

/* ---------------------------------------------------------------------
   2. USUARIOS  (RF05, RF06, RNF03)
   rol: ADMIN (global) / OPERADOR (siempre con sucursal)
   --------------------------------------------------------------------- */
CREATE TABLE usuarios (
    id            INT IDENTITY(1,1) NOT NULL,
    username      NVARCHAR(50)      NOT NULL,
    password_hash CHAR(64)          NOT NULL,   -- SHA-256 en hexadecimal
    salt          CHAR(32)          NOT NULL,   -- 16 bytes en hexadecimal
    rol           VARCHAR(15)       NOT NULL,
    sucursal_id   INT               NULL,       -- NULL solo para ADMIN global
    activo        BIT               NOT NULL CONSTRAINT df_usuarios_activo DEFAULT (1),
    created_at    DATETIME2(0)      NOT NULL CONSTRAINT df_usuarios_creat  DEFAULT (SYSDATETIME()),
    created_by    NVARCHAR(50)      NULL,
    updated_at    DATETIME2(0)      NULL,
    updated_by    NVARCHAR(50)      NULL,
    CONSTRAINT pk_usuarios             PRIMARY KEY (id),
    CONSTRAINT uq_usuarios_username    UNIQUE (username),
    CONSTRAINT fk_usuarios_sucursal    FOREIGN KEY (sucursal_id) REFERENCES sucursales(id),
    CONSTRAINT ck_usuarios_rol         CHECK (rol IN ('ADMIN','OPERADOR')),
    CONSTRAINT ck_usuarios_sucursal    CHECK (rol = 'ADMIN' OR sucursal_id IS NOT NULL)
);
GO

/* ---------------------------------------------------------------------
   3. PRODUCTOS  (catalogo global - RF10, RF11)
   El SKU identifica al producto de forma unica en toda la empresa.
   El stock NO vive aqui: vive en INVENTARIO, por sucursal.
   --------------------------------------------------------------------- */
CREATE TABLE productos (
    id           INT IDENTITY(1,1) NOT NULL,
    sku          NVARCHAR(30)      NOT NULL,
    nombre       NVARCHAR(150)     NOT NULL,
    precio       DECIMAL(12,2)     NOT NULL CONSTRAINT df_productos_precio DEFAULT (0),
    stock_minimo INT               NOT NULL CONSTRAINT df_productos_min DEFAULT (0),
    activo       BIT               NOT NULL CONSTRAINT df_productos_activo DEFAULT (1),
    created_at   DATETIME2(0)      NOT NULL CONSTRAINT df_productos_creat DEFAULT (SYSDATETIME()),
    created_by   NVARCHAR(50)      NULL,
    updated_at   DATETIME2(0)      NULL,
    updated_by   NVARCHAR(50)      NULL,
    CONSTRAINT pk_productos        PRIMARY KEY (id),
    CONSTRAINT uq_productos_sku    UNIQUE (sku),
    CONSTRAINT ck_productos_minimo CHECK (stock_minimo >= 0),
    CONSTRAINT ck_productos_precio CHECK (precio >= 0)
);
GO

/* ---------------------------------------------------------------------
   4. INVENTARIO  (NUEVA - stock fisico por sucursal)
   Elimina la dependencia parcial sku -> nombre del modelo anterior:
   el stock depende de (producto_id, sucursal_id), no del catalogo.
   --------------------------------------------------------------------- */
CREATE TABLE inventario (
    id          INT IDENTITY(1,1) NOT NULL,
    producto_id INT               NOT NULL,
    sucursal_id INT               NOT NULL,
    stock       INT               NOT NULL CONSTRAINT df_inventario_stock DEFAULT (0),
    created_at  DATETIME2(0)      NOT NULL CONSTRAINT df_inventario_creat DEFAULT (SYSDATETIME()),
    created_by  NVARCHAR(50)      NULL,
    updated_at  DATETIME2(0)      NOT NULL CONSTRAINT df_inventario_upd   DEFAULT (SYSDATETIME()),
    updated_by  NVARCHAR(50)      NULL,
    CONSTRAINT pk_inventario            PRIMARY KEY (id),
    CONSTRAINT uq_inventario_prod_suc   UNIQUE (producto_id, sucursal_id),
    CONSTRAINT fk_inventario_producto   FOREIGN KEY (producto_id) REFERENCES productos(id),
    CONSTRAINT fk_inventario_sucursal   FOREIGN KEY (sucursal_id) REFERENCES sucursales(id),
    CONSTRAINT ck_inventario_stock      CHECK (stock >= 0)
);
GO

/* ---------------------------------------------------------------------
   5. PROVEEDORES  (NUEVA - RF08)
   Reemplaza transacciones.proveedor_rut / proveedor_nombre.
   --------------------------------------------------------------------- */
CREATE TABLE proveedores (
    id         INT IDENTITY(1,1) NOT NULL,
    rut        NVARCHAR(12)      NULL,
    nombre     NVARCHAR(150)     NOT NULL,
    telefono   NVARCHAR(30)      NULL,
    email      NVARCHAR(120)     NULL,
    activo     BIT               NOT NULL CONSTRAINT df_proveedores_activo DEFAULT (1),
    created_at DATETIME2(0)      NOT NULL CONSTRAINT df_proveedores_creat  DEFAULT (SYSDATETIME()),
    created_by NVARCHAR(50)      NULL,
    updated_at DATETIME2(0)      NULL,
    updated_by NVARCHAR(50)      NULL,
    CONSTRAINT pk_proveedores PRIMARY KEY (id)
);
GO

-- El RUT es opcional (RF08), pero si existe no se puede repetir.
CREATE UNIQUE INDEX ux_proveedores_rut ON proveedores(rut) WHERE rut IS NOT NULL;
GO

/* ---------------------------------------------------------------------
   6. COMPRADORES  (NUEVA - RF08, RF09)
   Reemplaza transacciones.comprador_rut / comprador_nombre y
   despachos_pendientes.comprador_rut / comprador_nombre.
   --------------------------------------------------------------------- */
CREATE TABLE compradores (
    id         INT IDENTITY(1,1) NOT NULL,
    rut        NVARCHAR(12)      NULL,
    nombre     NVARCHAR(150)     NOT NULL,
    telefono   NVARCHAR(30)      NULL,
    email      NVARCHAR(120)     NULL,
    direccion  NVARCHAR(200)     NULL,
    activo     BIT               NOT NULL CONSTRAINT df_compradores_activo DEFAULT (1),
    created_at DATETIME2(0)      NOT NULL CONSTRAINT df_compradores_creat  DEFAULT (SYSDATETIME()),
    created_by NVARCHAR(50)      NULL,
    updated_at DATETIME2(0)      NULL,
    updated_by NVARCHAR(50)      NULL,
    CONSTRAINT pk_compradores PRIMARY KEY (id)
);
GO

CREATE UNIQUE INDEX ux_compradores_rut ON compradores(rut) WHERE rut IS NOT NULL;
GO

/* ---------------------------------------------------------------------
   7. LOTES  (RF04)
   Cada ingreso de mercaderia puede venir de un proveedor y traer lote.
   --------------------------------------------------------------------- */
CREATE TABLE lotes (
    id            INT IDENTITY(1,1) NOT NULL,
    producto_id   INT               NOT NULL,
    proveedor_id  INT               NULL,
    numero_lote   NVARCHAR(50)      NULL,
    cantidad      INT               NOT NULL,
    sucursal_id   INT               NOT NULL,
    fecha_ingreso DATETIME2(0)      NOT NULL CONSTRAINT df_lotes_fecha DEFAULT (SYSDATETIME()),
    anulado       BIT               NOT NULL CONSTRAINT df_lotes_anulado DEFAULT (0),
    created_at    DATETIME2(0)      NOT NULL CONSTRAINT df_lotes_creat DEFAULT (SYSDATETIME()),
    created_by    NVARCHAR(50)      NULL,
    updated_at    DATETIME2(0)      NULL,
    updated_by    NVARCHAR(50)      NULL,
    CONSTRAINT pk_lotes            PRIMARY KEY (id),
    CONSTRAINT fk_lotes_producto   FOREIGN KEY (producto_id)  REFERENCES productos(id),
    CONSTRAINT fk_lotes_proveedor  FOREIGN KEY (proveedor_id) REFERENCES proveedores(id),
    CONSTRAINT fk_lotes_sucursal   FOREIGN KEY (sucursal_id)  REFERENCES sucursales(id),
    CONSTRAINT ck_lotes_cantidad   CHECK (cantidad > 0)
);
GO

/* ---------------------------------------------------------------------
   8. VENTAS  (NUEVA - RF15)
   Cabecera de la venta POS. Independiente de transacciones.
   --------------------------------------------------------------------- */
CREATE TABLE ventas (
    id           INT IDENTITY(1,1) NOT NULL,
    sucursal_id  INT               NOT NULL,
    comprador_id INT               NULL,      -- NULL = venta de mostrador
    usuario_id   INT               NOT NULL,  -- cajero que registra
    fecha        DATETIME2(0)      NOT NULL CONSTRAINT df_ventas_fecha DEFAULT (SYSDATETIME()),
    subtotal     DECIMAL(12,2)     NOT NULL CONSTRAINT df_ventas_sub   DEFAULT (0),
    iva          DECIMAL(12,2)     NOT NULL CONSTRAINT df_ventas_iva   DEFAULT (0),
    total        DECIMAL(12,2)     NOT NULL CONSTRAINT df_ventas_total DEFAULT (0),
    estado       VARCHAR(12)       NOT NULL CONSTRAINT df_ventas_estado DEFAULT ('COMPLETADA'),
    created_at   DATETIME2(0)      NOT NULL CONSTRAINT df_ventas_creat DEFAULT (SYSDATETIME()),
    created_by   NVARCHAR(50)      NULL,
    updated_at   DATETIME2(0)      NULL,
    updated_by   NVARCHAR(50)      NULL,
    CONSTRAINT pk_ventas            PRIMARY KEY (id),
    CONSTRAINT fk_ventas_sucursal   FOREIGN KEY (sucursal_id)  REFERENCES sucursales(id),
    CONSTRAINT fk_ventas_comprador  FOREIGN KEY (comprador_id) REFERENCES compradores(id),
    CONSTRAINT fk_ventas_usuario    FOREIGN KEY (usuario_id)   REFERENCES usuarios(id),
    CONSTRAINT ck_ventas_estado     CHECK (estado IN ('COMPLETADA','ANULADA')),
    CONSTRAINT ck_ventas_montos     CHECK (subtotal >= 0 AND iva >= 0 AND total >= 0)
);
GO

/* ---------------------------------------------------------------------
   9. VENTA_DETALLE  (NUEVA - RF15)
   Lineas de la venta. Genera la relacion N:N ventas <-> productos
   que exige la pauta ("INFORME DE TRANSACCIONES (N:N)").
   --------------------------------------------------------------------- */
CREATE TABLE venta_detalle (
    id              INT IDENTITY(1,1) NOT NULL,
    venta_id        INT               NOT NULL,
    producto_id     INT               NOT NULL,
    cantidad        INT               NOT NULL,
    precio_unitario DECIMAL(12,2)     NOT NULL,
    subtotal        AS CAST(cantidad * precio_unitario AS DECIMAL(12,2)) PERSISTED,
    created_at      DATETIME2(0)      NOT NULL CONSTRAINT df_vd_creat DEFAULT (SYSDATETIME()),
    created_by      NVARCHAR(50)      NULL,
    updated_at      DATETIME2(0)      NULL,
    updated_by      NVARCHAR(50)      NULL,
    CONSTRAINT pk_venta_detalle          PRIMARY KEY (id),
    CONSTRAINT uq_venta_detalle_linea    UNIQUE (venta_id, producto_id),
    CONSTRAINT fk_venta_detalle_venta    FOREIGN KEY (venta_id)    REFERENCES ventas(id),
    CONSTRAINT fk_venta_detalle_producto FOREIGN KEY (producto_id) REFERENCES productos(id),
    CONSTRAINT ck_venta_detalle_cant     CHECK (cantidad > 0),
    CONSTRAINT ck_venta_detalle_precio   CHECK (precio_unitario >= 0)
);
GO

/* ---------------------------------------------------------------------
   10. TRANSACCIONES  (kardex inmutable - RF01, RF02, RF12, RF13)
   Un registro por movimiento. Ya NO almacena datos de contraparte.

   tipo:
     INGRESO               entrada de mercaderia (lote opcional, RF04)
     SALIDA                salida por venta POS (venta_detalle_id exigido)
     TRANSFERENCIA_SALIDA  egreso en la sucursal de origen
     TRANSFERENCIA_INGRESO ingreso en la sucursal de destino
     AJUSTE                correccion de inventario / merma
   Las transferencias se registran como doble asiento: dos filas con el
   mismo grupo_id (RF12).
   --------------------------------------------------------------------- */
CREATE TABLE transacciones (
    id               BIGINT IDENTITY(1,1) NOT NULL,
    grupo_id         UNIQUEIDENTIFIER   NULL,
    tipo             VARCHAR(25)        NOT NULL,
    producto_id      INT                NOT NULL,
    sucursal_id      INT                NOT NULL,
    cantidad         INT                NOT NULL,
    stock_resultante INT                NOT NULL,
    lote_id          INT                NULL,
    venta_detalle_id INT                NULL,
    usuario_id       INT                NOT NULL,
    fecha            DATETIME2(0)       NOT NULL CONSTRAINT df_transacciones_fecha DEFAULT (SYSDATETIME()),
    created_at       DATETIME2(0)       NOT NULL CONSTRAINT df_tx_creat DEFAULT (SYSDATETIME()),
    created_by       NVARCHAR(50)       NULL,
    updated_at       DATETIME2(0)       NULL,
    updated_by       NVARCHAR(50)       NULL,
    CONSTRAINT pk_transacciones              PRIMARY KEY (id),
    CONSTRAINT fk_transacciones_producto     FOREIGN KEY (producto_id)      REFERENCES productos(id),
    CONSTRAINT fk_transacciones_sucursal     FOREIGN KEY (sucursal_id)      REFERENCES sucursales(id),
    CONSTRAINT fk_transacciones_lote         FOREIGN KEY (lote_id)          REFERENCES lotes(id),
    CONSTRAINT fk_transacciones_venta        FOREIGN KEY (venta_detalle_id) REFERENCES venta_detalle(id),
    CONSTRAINT fk_transacciones_usuario      FOREIGN KEY (usuario_id)       REFERENCES usuarios(id),
    CONSTRAINT ck_transacciones_tipo         CHECK (tipo IN ('INGRESO','SALIDA',
                                                             'TRANSFERENCIA_SALIDA',
                                                             'TRANSFERENCIA_INGRESO','AJUSTE')),
    CONSTRAINT ck_transacciones_cantidad     CHECK (cantidad > 0),
    CONSTRAINT ck_transacciones_stock        CHECK (stock_resultante >= 0),
    -- Coherencia entre el tipo de movimiento y su documento de origen.
    -- AJUSTE puede tender la reversa de un lote (RF04) o de una venta (RF15).
    CONSTRAINT ck_transacciones_origen       CHECK (
           (tipo = 'INGRESO' AND venta_detalle_id IS NULL)
        OR (tipo = 'SALIDA'  AND venta_detalle_id IS NOT NULL)
        OR (tipo IN ('TRANSFERENCIA_SALIDA','TRANSFERENCIA_INGRESO')
            AND lote_id IS NULL AND venta_detalle_id IS NULL)
        OR (tipo = 'AJUSTE' AND (lote_id IS NULL OR venta_detalle_id IS NULL))),
    -- Solo las transferencias viajan en pareja
    CONSTRAINT ck_transacciones_grupo        CHECK (
           (tipo LIKE 'TRANSFERENCIA%' AND grupo_id IS NOT NULL)
        OR (tipo NOT LIKE 'TRANSFERENCIA%' AND grupo_id IS NULL))
);
GO

/* ---------------------------------------------------------------------
   11. DESPACHOS_PENDIENTES  (RF09)
   --------------------------------------------------------------------- */
CREATE TABLE despachos_pendientes (
    id             INT IDENTITY(1,1) NOT NULL,
    producto_id    INT               NOT NULL,
    sucursal_id    INT               NOT NULL,
    comprador_id   INT               NOT NULL,
    venta_id       INT               NULL,
    destino        NVARCHAR(200)     NOT NULL,
    cantidad       INT               NOT NULL,
    estado         VARCHAR(12)       NOT NULL CONSTRAINT df_despachos_estado DEFAULT ('PENDIENTE'),
    fecha_creacion DATETIME2(0)      NOT NULL CONSTRAINT df_despachos_fecha DEFAULT (SYSDATETIME()),
    fecha_entrega  DATETIME2(0)      NULL,
    created_at     DATETIME2(0)      NOT NULL CONSTRAINT df_despachos_creat DEFAULT (SYSDATETIME()),
    created_by     NVARCHAR(50)      NULL,
    updated_at     DATETIME2(0)      NULL,
    updated_by     NVARCHAR(50)      NULL,
    CONSTRAINT pk_despachos             PRIMARY KEY (id),
    CONSTRAINT fk_despachos_producto    FOREIGN KEY (producto_id)  REFERENCES productos(id),
    CONSTRAINT fk_despachos_sucursal    FOREIGN KEY (sucursal_id)  REFERENCES sucursales(id),
    CONSTRAINT fk_despachos_comprador   FOREIGN KEY (comprador_id) REFERENCES compradores(id),
    CONSTRAINT fk_despachos_venta       FOREIGN KEY (venta_id)     REFERENCES ventas(id),
    CONSTRAINT ck_despachos_cantidad    CHECK (cantidad > 0),
    CONSTRAINT ck_despachos_estado      CHECK (estado IN ('PENDIENTE','EN_RUTA','COMPLETADO','CANCELADO'))
);
GO

/* ---------------------------------------------------------------------
   12. REGISTRO_ALERTAS  (RF02, RF03)
   --------------------------------------------------------------------- */
CREATE TABLE registro_alertas (
    id              INT IDENTITY(1,1) NOT NULL,
    producto_id     INT               NOT NULL,
    sucursal_id     INT               NOT NULL,
    fecha           DATETIME2(0)      NOT NULL CONSTRAINT df_alertas_fecha DEFAULT (SYSDATETIME()),
    mensaje         NVARCHAR(300)     NOT NULL,
    stock_al_momento INT              NOT NULL,
    leida           BIT               NOT NULL CONSTRAINT df_alertas_leida DEFAULT (0),
    created_by      NVARCHAR(50)      NULL,   -- 'SISTEMA' para las automaticas
    CONSTRAINT pk_registro_alertas        PRIMARY KEY (id),
    CONSTRAINT fk_alertas_producto        FOREIGN KEY (producto_id) REFERENCES productos(id),
    CONSTRAINT fk_alertas_sucursal        FOREIGN KEY (sucursal_id) REFERENCES sucursales(id),
    CONSTRAINT ck_alertas_stock           CHECK (stock_al_momento >= 0)
);
GO

/* ---------------------------------------------------------------------
   13. INDICES  (RNF05: B-Tree sobre columnas de alta selectividad)
   --------------------------------------------------------------------- */
CREATE INDEX ix_usuarios_sucursal       ON usuarios(sucursal_id);
CREATE INDEX ix_inventario_sucursal     ON inventario(sucursal_id) INCLUDE (producto_id, stock);
CREATE INDEX ix_lotes_producto_fecha    ON lotes(producto_id, fecha_ingreso);
CREATE INDEX ix_lotes_proveedor         ON lotes(proveedor_id);
CREATE INDEX ix_ventas_sucursal_fecha   ON ventas(sucursal_id, fecha);
CREATE INDEX ix_ventas_comprador        ON ventas(comprador_id);
CREATE INDEX ix_venta_detalle_producto  ON venta_detalle(producto_id);
CREATE INDEX ix_transacciones_prod_fec  ON transacciones(producto_id, fecha);
CREATE INDEX ix_transacciones_suc_fec   ON transacciones(sucursal_id, fecha);
CREATE INDEX ix_transacciones_venta     ON transacciones(venta_detalle_id);
CREATE INDEX ix_despachos_suc_estado    ON despachos_pendientes(sucursal_id, estado);
CREATE INDEX ix_alertas_pendientes      ON registro_alertas(sucursal_id, leida)
    INCLUDE (producto_id, fecha);
GO

/* ---------------------------------------------------------------------
   14. VISTA DE APOYO  (RF10: stock en vivo con semaforo)
   --------------------------------------------------------------------- */
CREATE VIEW vw_stock_en_vivo
AS
SELECT  p.id                                          AS producto_id,
        p.sku,
        p.nombre,
        s.id                                          AS sucursal_id,
        s.nombre                                      AS sucursal,
        i.stock,
        p.stock_minimo,
        CASE WHEN i.stock <= p.stock_minimo THEN 1 ELSE 0 END AS es_critico
FROM inventario i
INNER JOIN productos  p ON p.id = i.producto_id
INNER JOIN sucursales s ON s.id = i.sucursal_id;
GO

PRINT 'Esquema SALCI v3 creado correctamente. (11 tablas + inventario, 3FN)';
GO
