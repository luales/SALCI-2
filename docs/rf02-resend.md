# RF02 — Alertas con Resend + Webhook

RF02 pide **dos canales**: correo electrónico y webhook cuando
`stock <= stock_minimo`. La implementación hace ambos después del `COMMIT`,
por lo que un fallo de red **no revierte** el movimiento de stock.

---

## 1. Qué key usar en Resend

| Key | Permiso | ¿Para RF02? |
|---|---|---|
| `Onboarding` (`sending_access`) | Solo enviar correos | ✅ La correcta. |
| `ADS_Proyecto_Logistica` (`full_access`) | Administrar cuenta, dominios, keys, etc. | ❌ No usar en la app. |

> **Seguridad:** si una key se pegó en un chat, correo o documento, se debe
> **rotar (revocar y crear otra)**. Crea una key nueva llamada `SALCI_RF02`
> con permiso `sending_access` y elimina la de `full_access` si ya no se usa.

Nunca pongas la key en `config.php`, en JavaScript ni en GitHub.

---

## 2. Configuración local

Copiar `app/config.local.php.example` como `app/config.local.php` (ya está en
`.gitignore`) y completar:

```php
<?php
define('RF02_RESEND_API_KEY', 're_XXXXXXXX');          // key rotada, sending_access
define('RF02_RESEND_FROM',    'SALCI <onboarding@resend.dev>');
define('RF02_EMAIL_DESTINO',  'gersonzja2@gmail.com');
define('RF02_WEBHOOK_URL',    'https://webhook.site/XXXXXXXX');
```

### Remitente `onboarding@resend.dev`

Mientras no se verifique un dominio propio:

- `from` debe ser `SALCI <onboarding@resend.dev>`.
- `to` debe ser el correo asociado a la cuenta de Resend.

Para enviar a cualquier correo hay que verificar un dominio en Resend y cambiar
`RF02_RESEND_FROM` a algo como `SALCI <alertas@tudominio.cl>`.

---

## 3. Webhook (segundo canal)

El webhook de Resend **no envía** la alerta: solo avisa de eventos
(`delivered`, `bounced`, etc.). Para RF02 el webhook es un `POST` JSON que hace
esta app a `RF02_WEBHOOK_URL`.

Para la demo se puede usar <https://webhook.site>: copiar la URL única y
pegársela a `RF02_WEBHOOK_URL`. El payload incluye producto, sucursal,
mensaje, stock, mínimo y usuario.

---

## 4. Cómo probarlo

1. Configurar `config.local.php` con la key rotada y el webhook.
2. En `productos.php`, editar un producto y subir su `stock_minimo` por encima
   del stock actual de Chillán.
3. Hacer una venta en el POS o un ingreso/transferencia que deje stock crítico.
4. Verificar:
   - `registro_alertas` tiene una fila nueva.
   - Resend muestra el correo como *sent/delivered*.
   - webhook.site muestra el `POST` JSON.
5. Repetir con `RF02_WEBHOOK_URL` inválido: la venta igual se confirma.

---

## 5. Detalle técnico

`op_notificar()`:

```text
alerta en BD → COMMIT → Resend API (POST /emails) + webhook (POST JSON)
```

- Si `RF02_RESEND_API_KEY` está vacía, se usa `mail()` como fallback.
- Los errores se escriben en `error_log`, nunca se lanzan al usuario.
- La key se envía solo en el header `Authorization` del servidor PHP.
