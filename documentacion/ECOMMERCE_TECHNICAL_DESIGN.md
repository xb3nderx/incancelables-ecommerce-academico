# ECOMMERCE_TECHNICAL_DESIGN.md

> Documento de arquitectura técnica del e-commerce de Incancelables.
>
> Traduce las decisiones conceptuales de `ECOMMERCE_DESIGN.md` y la planificación de `ECOMMERCE_ROADMAP.md` a decisiones técnicas.
>
> **Estado:** Etapa 2 — Diseño técnico del MVP (en progreso) e implementación incremental (7 bloques implementados)
> **Última actualización:** 3 de octubre de 2026
>
> **Estado verificado (30/09/2026):** entorno DEV operativo; base de datos materializada con 15 tablas (sección 9); capa de aplicación **parcialmente implementada**: bloques `GET /api/productos`, `POST /api/pedidos`, verificación de email (mecanismo + endpoints `/validar`, `/reenvio` y la consulta solo lectura `/pedidos`, esta última desde el 05/10/2026), **bloque de vencimiento/cancelación/inicio de pago** (`POST /api/pedidos/{id}/cancelar`, `POST /api/pedidos/{id}/pago` con `iniciar_pago`/`aceptar_correccion`, vencimiento del primer período y rutas con `{id}`) y **bloque de pagos con `DummyPaymentProvider`** (`PaymentProvider`/`DummyPaymentProvider`, `PaymentValidationService`, `PagoRepository`, creación de `Pago` + `PaymentValidationResult`, transición a `PROCESANDO_PAGO` y aplicación de `APROBADO`/`RECHAZADO`, con `EN_PROCESO` **excluido del simulador del MVP** desde el 05/10/2026 — ver decisión en sección 21) implementados y verificados (secciones 4, 10.3, 12, 13.2, 15, 15.4, 17.1, 17.2, 19 y 21).
>
> Este documento **no implica que todas las funcionalidades descriptas estén implementadas**. Refleja las decisiones de negocio cerradas hasta el 28/09/2026 y el estado real de implementación verificado el 30/09/2026 (sección 21). El detalle HTTP (códigos, parámetros, formatos) está definido para `GET /api/productos`, `POST /api/pedidos`, los dos endpoints de verificación de email, `POST /api/pedidos/{id}/cancelar` y — **desde el 30/09/2026** — para `POST /api/pedidos/{id}/pago` con inicio real de pago (`proveedor_id`, `items[]`, `simulacion`, creación de `Pago`, `PROCESANDO_PAGO` y resultados del proveedor; ver secciones 17.2 y 21). Del bloque de verificación de email, el canal de email `log` de DEV (decisión del 02/10/2026, secciones 5, 6 y 15) quedó **implementado y verificado el 03/10/2026**; siguen pendientes el canal real de envío del email y la URL/asunto/plantilla definitivos del checkout; del flujo de pago quedan pendientes el webhook/`MercadoPagoProvider` (POST-MVP) y la resolución administrativa de casos de revisión, junto con `DTOs`, migraciones versionadas y la estructura interna de los `DTOs`. La confirmación de resultados `EN_PROCESO` **no es un pendiente del MVP**: queda explícitamente fuera de alcance por decisión del 05/10/2026 (el simulador del MVP no admite `simulacion=EN_PROCESO`; ver sección 21) y se reserva para la evolución futura (webhook, pagos asincrónicos y conciliación, todos POST-MVP).

---

## 1. Objetivo

Definir la arquitectura técnica necesaria para implementar el MVP definido en `ECOMMERCE_ROADMAP.md`, traduciendo las decisiones conceptuales de `ECOMMERCE_DESIGN.md`.

El objetivo es diseñar únicamente lo necesario para el MVP, manteniendo la posibilidad de incorporar posteriormente las funcionalidades previstas en el diseño conceptual sin tener que rediseñar la base conceptual.

---

## 2. Alcance del MVP

El MVP deberá permitir una compra completa de **productos generales** del e-commerce (merchandising, música y otros productos de tienda), partiendo del stock físico disponible. El producto es genérico: no existe `tipo_producto`.

Flujo principal:

```text
Store
  ↓
Producto
  ↓
Carrito (frontend, sessionStorage)
  ↓
Checkout (validación comercial → se crea el Pedido)
  ↓
Verificación de email
  ↓
Pago (proveedor simulado: DummyPaymentProvider)
  ↓
Confirmación + email post-pago
```

Quedan **fuera del MVP** y pertenecen a evoluciones futuras:

- venta de entradas/tickets (productos asociados a shows);
- venta sin stock / bajo demanda (on-demand);
- fulfillment avanzado;
- envíos complejos;
- retiros y puntos de entrega;
- cuentas de usuario;
- carrito persistente entre dispositivos;
- múltiples carritos recuperables;
- recuperación sofisticada de carritos abandonados;
- promociones complejas;
- cancelaciones, devoluciones y reintegros;
- historial de precios;
- proveedores/sourcing y abastecimiento;
- reportes y administración avanzados;
- múltiples proveedores de pago;
- comisiones/fees de Mercado Pago, impuestos/cargos del proveedor, neto liquidado y liquidación/conciliación;
- integración de pagos real con Mercado Pago (`MercadoPagoProvider` + webhook `POST /api/webhooks/mercadopago`): en el MVP el proveedor se implementa con `DummyPaymentProvider` (POST-MVP / extensión opcional);
- historial de movimientos de stock;
- newsletter como funcionalidad del e-commerce;
- autenticación/verificación específica de las notificaciones webhook de Mercado Pago (definición funcional fuera del MVP; detalle de implementación/provider-specific para una etapa posterior);
- resolución administrativa de los casos de revisión `PAGADO_STOCK_NO_AFECTADO` y de los `PAGO_PROCESANDO_EXCEDIDO` que nunca reciben un resultado definitivo.

---

## 3. Arquitectura general

El ecosistema Incancelables se organiza en tres proyectos con responsabilidades distintas:

```text
D:\Incancelables Web        → FRONTEND (HTML/CSS/JS) del sitio principal
D:\Incancelables Backend    → backend histórico en Google Apps Script (newsletter → Google Sheets)
D:\Incancelables E-Commerce → BACKEND (PHP + MariaDB) del e-commerce: API, lógica de negocio y base de datos
```

El e-commerce, en sí mismo, se separa en frontend y backend: `D:\Incancelables Web` consume la API y `D:\Incancelables E-Commerce` la expone. El frontend no contiene reglas de negocio: las reglas de negocio viven en el backend.

* `D:\Incancelables Backend` (Apps Script) **no forma parte del e-commerce**: es el backend histórico del sitio principal (newsletter → Google Sheets) y se lo utiliza únicamente como **referencia** de lógica ya existente (ver sección 15). No se migran sus datos ni su modelo.
* **Convivencia en el MVP:** el newsletter continúa en Apps Script → Google Sheets; el e-commerce opera en PHP → MariaDB; **no existe integración entre ambos ecosistemas**. La migración del newsletter a PHP/MariaDB es **POST-MVP**.

Comunicación (e-commerce):

```text
Frontend (D:\Incancelables Web)
      ↓ HTTP / JSON
API REST
      ↓
Backend E-Commerce (D:\Incancelables E-Commerce)
      ↓
MariaDB
```

```text
Backend
  ↓
Frontera con el proveedor de pago (ver sección 12)
  ↓
DummyPaymentProvider (MVP, proveedor simulado)
```

Los detalles de comunicación (formato de respuestas, CORS, URLs por entorno, autenticación) están pendientes de definición (ver sección 20).

---

## 4. Estructura del proyecto

Estructura actual del backend (verificada el 29/09/2026):

```text
D:\Incancelables E-Commerce
├── documentacion
├── public
│   ├── .htaccess          → reescritura hacia index.php (RewriteEngine On)
│   └── index.php          → front controller / router implementado
├── routes
│   └── api.php            → GET /api/productos, POST /api/pedidos, POST /api/pedidos/{id}/cancelar, POST /api/pedidos/{id}/pago, POST /api/email-verificaciones/validar, POST /api/email-verificaciones/reenvio, POST /api/email-verificaciones/pedidos
├── src
│   ├── config
│   │   ├── database.php   → conexión PDO actual
│   │   ├── email.php      → configuración del canal de email (channel = log)
│   │   └── frontend.php   → URL destino de la verificación de checkout (url_verificacion_checkout)
│   ├── controllers
│   │   ├── ProductoController.php
│   │   ├── PedidoController.php
│   │   └── EmailVerificationController.php
│   ├── database           → vacío
│   ├── mail
│   │   ├── EmailSender.php     → contrato genérico de transporte de email
│   │   └── LogEmailSender.php  → canal log (DEV/MVP)
│   ├── models             → vacío
│   ├── repositories
│   │   ├── ProductoRepository.php
│   │   ├── PedidoRepository.php
│   │   └── EmailVerificationRepository.php
│   └── services
│       ├── ProductoService.php
│       ├── PedidoService.php
│       └── EmailVerificationService.php
└── storage
    └── logs               → vacío
```

- `public` es el punto de entrada público de la aplicación.
- `public/index.php` es el **front controller / router** previsto en la sección 19: autoload manual (`spl_autoload_register`), dispatch de `routes/api.php` **con soporte de rutas parametrizadas `{id}`** (segmentos `{...}` capturan `[^/]+` y se pasan como parámetro al action del Controller; el `id` no numérico se rechaza con `400` en el Controller), instanciación de Controllers/Services/Repositories (incluido `EmailVerificationService` como dependencia de `PedidoService`) y del **composition root del canal de email** (carga `src/config/email.php`, instancia el canal configurado y lo inyecta como `EmailSender` en `PedidoService`) y también del **composition root de la URL de verificación del checkout** (carga `src/config/frontend.php`, valida la clave `url_verificacion_checkout` y la inyecta como `string` ya resuelto en `PedidoService`; una configuración ausente o vacía lanza `RuntimeException`: fallo explícito, sin degradación silenciosa), respuestas JSON `404`, `405` (con header `Allow`, calculado sobre las rutas cuyo patrón coincide con la ruta pedida) y `500`, y registro de errores técnicos en `error_log`.
- `public/.htaccess` reescribe las URLs hacia `index.php`.
- `src/config/database.php` contiene la conexión PDO actual con la configuración **hardcodeada** (sin variables de entorno; ver secciones 5 y 20).
- `src/config/email.php` devuelve la configuración específica de email (hoy un único valor: `channel`), cargada desde `public/index.php` como composition root (secciones 5, 6 y 15); la elección del canal ocurre ahí, sin tocar la lógica de negocio.
- `src/config/frontend.php` devuelve la configuración del destino del frontend para el enlace de verificación del checkout (hoy un único valor: `url_verificacion_checkout`), cargada y validada desde `public/index.php` como composition root e inyectada como `string` en `PedidoService` (sección 21). **Para el MVP la configuración es directa: un único valor, sin selector DEV/PROD** ni otras variables de entorno.
- `routes/api.php` define las rutas implementadas: `GET /api/productos`, `POST /api/pedidos`, los dos endpoints del checkout con `{id}` (`POST /api/pedidos/{id}/cancelar`, `POST /api/pedidos/{id}/pago`) y los tres endpoints de verificación de email (`POST /api/email-verificaciones/validar`, `POST /api/email-verificaciones/reenvio` y `POST /api/email-verificaciones/pedidos`, de solo lectura, 05/10/2026). **No** existe `POST /api/email-verificaciones` (la solicitud está integrada en `POST /api/pedidos`) ni `GET /api/pedidos/{id}` (la consulta de continuación de compra se autoriza con el token, no con un id enumerable).
- Controllers: `ProductoController`, `PedidoController` y `EmailVerificationController` (HTTP/JSON, validación estructura básica del request; sin lógica de negocio ni SQL).
- Services: `ProductoService` (sin cálculo de disponibilidad), `PedidoService` (coordina disponibilidad, validación comercial, transacción de creación del `Pedido`, el vencimiento del primer período, la cancelación voluntaria y el flujo completo de pago — inicio real con fase A/B y aplicación de `APROBADO`/`RECHAZADO` (`EN_PROCESO` excluido del simulador desde el 05/10/2026, ver sección 21) — y, como consumidor, el inicio/confirmación/reenvío de la verificación de email), `EmailVerificationService` (mecanismo genérico, agnóstico de contexto, sin envío de emails ni estados del `Pedido`) y `PaymentValidationService` (única frontera con el proveedor; ver sección 19.1).
- Transporte de email (desde el 03/10/2026): `src/mail/EmailSender.php` (contrato `enviar(para, asunto, cuerpo): bool`) y `src/mail/LogEmailSender.php` (canal `log`, DEV/MVP). No es un Service ni una capa de negocio: frontera genérica de infraestructura que no conoce `Pedido`, estados ni tokens; el canal se elige por configuración (sección 5).
- Repositories: `ProductoRepository`, `PedidoRepository` y `EmailVerificationRepository` — **aquí vive todo el SQL**, incluido el `SELECT ... FOR UPDATE` con el que se bloquean las filas de `productos` durante la validación de creación del `Pedido`.
- Sigue **vacío**: `src/database/`, `src/models/`, `storage/logs/`.
- `src/repositories/` ya existe (sección 19.2).
- No existen `composer.json`/autoloader (autoload manual), archivos `.env`, migraciones `.sql` ni tests en el proyecto.

---

## 5. Entornos

El proyecto utilizará dos entornos:

```text
DEV
PROD
```

- Actualmente existe el entorno DEV local.
- El entorno PROD queda pendiente de definición e implementación.
- Las diferencias entre entornos deben resolverse mediante **configuración** y no mediante modificaciones manuales de la lógica de negocio.
- **Canales de email por entorno (decisión cerrada el 02/10/2026):** en **DEV** la entrega del email de verificación se realiza con el **canal `log`** (ver secciones 6 y 15); en el **MVP/producción** rige el **canal real de envío**, requisito pendiente antes de dar por terminado el MVP. La elección entre canales se resuelve **mediante configuración**, sin modificar la lógica de negocio de verificación. **Implementado el 03/10/2026 para el canal `log`:** `src/config/email.php` define `channel` y `public/index.php` (composition root) instancia el canal configurado y lo inyecta en `PedidoService`; un canal desconocido lanza `RuntimeException` (fallo explícito, sin degradación silenciosa). La conmutación al canal real usará el mismo mecanismo (sección 20).

---

## 6. Entorno DEV

El desarrollo local se realiza con XAMPP:

```text
XAMPP
 ├── Apache
 ├── PHP
 └── MariaDB
         ↓
  E-commerce DEV
```

- Virtual Host local: `http://incancelables-ecommerce.local/` → `D:\Incancelables E-Commerce\public`.
- El dominio se resuelve mediante el archivo `hosts` de Windows (entrada presente y verificada).
- Componentes verificados en la auditoría: Apache operativo, **PHP 8.2.12**, extensión `pdo_mysql` disponible, **MariaDB 10.4.32**.
- Respuesta HTTP **200** verificada contra el Virtual Host.
- La conexión PHP → MariaDB fue verificada correctamente (lectura de la base `incancelables_ecommerce`).
- La integración de pagos con `DummyPaymentProvider` **ya está implementada y verificada desde el 30/09/2026** (bloque de pagos; ver sección 21). La integración **real** con Mercado Pago (`MercadoPagoProvider` + webhook) es POST-MVP.
- **Transporte de correo en DEV (estado verificado el 03/10/2026):** DEV entrega los emails de verificación por el **canal `log`** (secciones 15 y 21): `PedidoService` compone el email y lo transporta mediante el contrato `EmailSender`; `LogEmailSender` registra destinatario, asunto y cuerpo — incluido el enlace de verificación — en el `error_log` de Apache (`C:\xampp\apache\logs\error.log`) y devuelve `true` (entrega exitosa según el canal configurado), por lo que **`last_sent_at` se registra** y `POST /api/email-verificaciones/reenvio` opera con su cooldown de 15 minutos (`200 reenviado` / `429 cooldown_activo`). DEV **no** tiene MTA/SMTP, APIs ni credenciales, y `mail()` ya no se invoca; el canal **real** de envío sigue pendiente (secciones 20 y 21).
- **Canal de email `log` en DEV (decisión cerrada el 02/10/2026):** durante el desarrollo la entrega del email de verificación se realiza por un **canal de email por log**, exclusivamente infraestructura de desarrollo: genera el token real, persiste únicamente su hash (como hasta ahora), construye el enlace real de verificación y lo registra en log para poder recuperarlo durante las pruebas; el canal `log` **considera la entrega exitosa sin ejecutar un envío SMTP real** y, por lo tanto, **registra `last_sent_at`**. Debe permitir probar realmente: validación del token, transición `PEND_VERIF → PEND_PAGO`, reenvío, rotación del token, cooldown, `200 reenviado` y `429 cooldown_activo`. **Implementado y verificado el 03/10/2026** (`src/mail/EmailSender.php`, `src/mail/LogEmailSender.php`, `src/config/email.php` y selección/inyección en `public/index.php`; pruebas: creación → log con enlace → `429 cooldown_activo` → `200 reenviado` con rotación → `200 confirmado` → `PEND_PAGO`; ver secciones 15 y 21); no reemplaza el envío real requerido para el MVP final.

---

## 7. Entorno PROD

Pendiente de definición.

Requerirá un servidor o servicio de hosting capaz de ejecutar:

```text
Servidor web
PHP
MySQL o MariaDB
```

Se definirá en una etapa posterior de configuración del proyecto.

---

## 8. Comunicación frontend ↔ backend

El backend expone una API REST que devuelve JSON.

El frontend la consume mediante `fetch` desde `D:\Incancelables Web`.

Los endpoints conceptuales del MVP están definidos en la sección 19.4.

Pendientes (ver sección 20):

- formato exacto de respuestas y códigos de error;
- CORS;
- URLs DEV/PROD que utiliza el frontend;
- protección de endpoints.

---

## 9. Modelo de datos

La base de datos `incancelables_ecommerce` existe y contiene **15 tablas** (estado verificado en la auditoría):

```text
casos_revision
casos_revision_estados
casos_revision_tipos
emails_verificaciones
pagos
pedidos
pedidos_estados
pedidos_items
productos
productos_imagenes
proveedores
shows
shows_estados
validaciones_pagos
validaciones_pagos_estados
```

> La tabla `pagos_estados` **ya no existe**: el catálogo fue renombrado a `validaciones_pagos_estados` (ver "Cambios de modelo: estado de materialización").

> La tabla `proveedores` contiene las filas `MERCADOPAGO` y `DUMMY` (identificación por `codigo`; no se asumen IDs numéricos fijos). No se requieren cambios SQL para este catálogo.

> Existen **6 productos cargados** en `productos` (semilla inicial: remeras y un CD, con precio, stock y `activo` definidos). Se registra como dato existente, no como una decisión de diseño.

Relaciones existentes mediante claves foráneas (verificadas):

```text
casos_revision.pago_id          → pagos.id
casos_revision.tipo_id          → casos_revision_tipos.id
casos_revision.estado_id        → casos_revision_estados.id

validaciones_pagos.pago_id      → pagos.id          (UNIQUE(pago_id): relación Pago 1:1)
validaciones_pagos.estado_id    → validaciones_pagos_estados.id
validaciones_pagos.proveedor_id → proveedores.id

pagos.pedido_id                 → pedidos.id

pedidos.email_verificacion_id   → emails_verificaciones.id
pedidos.estado_id               → pedidos_estados.id

pedidos_items.pedido_id         → pedidos.id
pedidos_items.producto_id       → productos.id

productos.show_id               → shows.id
productos_imagenes.producto_id  → productos.id

shows.estado_id                 → shows_estados.id
```

> **`pagos` ya no tiene `estado_id`**: quedó con `id`, `pedido_id`, `importe`, `created_at` (sección 12.1). El estado del intento vive en `validaciones_pagos`.

Estados de pago existentes en `validaciones_pagos_estados`:

```text
PENDIENTE
EN_PROCESO
APROBADO
RECHAZADO
CANCELADO
```

> Son exactamente los estados de `PaymentValidationResult` (secciones 12.1.3 y 12.4). No se creó un segundo conjunto paralelo de estados.

Estados de pedido existentes en `pedidos_estados`:

```text
PEND_VERIF
PEND_PAGO
PROCESANDO_PAGO
PAGADO
NO_PAGADO
PAGADO_STOCK_NO_AFECTADO
```

> La tabla **ya coincide** con los estados definidos del `Pedido` (sección 11): el ajuste antes pendiente (`PENDIENTE`, `CONFIRMADO`, `CANCELADO`) **ya fue aplicado**.

> La tabla `shows_estados` existe pero no tiene estados cargados. Las tablas `shows`, `shows_estados` y la columna `productos.show_id` quedan **sin uso funcional en el MVP**: la asociación de productos con shows (entradas) corresponde a una evolución futura.

### Decisiones de columnas (verificadas en la base existente)

Aplican a tablas ya existentes; fueron verificadas en la auditoría:

- `pedidos_items` contiene conceptualmente: `pedido_id`, `producto_id`, `cantidad`, `precio_unitario`.
- Relación **Pedido 1:N PedidoItem**: `pedidos_items.pedido_id` → `pedidos.id`. `Pedido` no almacena físicamente una lista de items.
- `precio_unitario` es el precio histórico del producto para esa operación. Subtotal del item = `cantidad × precio_unitario`.
- `pedidos` **no** tiene `importe_total`. El total del pedido se calcula dinámicamente: `Σ(cantidad × precio_unitario)` sobre sus `pedidos_items`. No se duplica el total.
- Relación **Pedido 1:N Pago**: `pagos.pedido_id` → `pedidos.id`. Un pedido puede tener cero, uno o varios intentos.
- `pagos` contiene conceptualmente: `id`, `pedido_id`, `importe`, `created_at`. **No** contiene estado, proveedor, comisión ni neto: esa información vive en `PaymentValidationResult` (ver sección 12.1.3).
- `pagos` conserva su propio `importe`, registrado independientemente del total calculado del `Pedido` (auditoría y detección de diferencias). No se agrega `pedidos.importe_total` para esto.
- `pagos` **no** tiene campo persistente `numero_pago` / `numero_intento`: el orden del intento se determina dinámicamente a partir de los pagos del pedido y su orden cronológico.

### Cambios de modelo: estado de materialización

Son decisiones de arquitectura ya acordadas. La auditoría confirma que las siguientes **ya están materializadas en MariaDB**:

- **`emails_verificaciones`** con la estructura cerrada (columnas, PK, índice no único sobre `email`, sin `estado_id`, `email` NO UNIQUE, FK unidireccional `pedidos.email_verificacion_id → emails_verificaciones.id`) y **sin** campo de contexto (`contexto`, `tipo`, `uso`, `origen`, `pedido_id` ni equivalente); el mecanismo sigue siendo genérico y agnóstico de contexto (consumidor del MVP: la compra; consumidores futuros POST-MVP: newsletter, registro de cuentas, recuperación de contraseña). **Ampliación aplicada el 29/09/2026:** columna `last_sent_at DATETIME NULL` (única modificación de esquema de este bloque, para el cooldown de reenvío). **Implementado en código** (clases, endpoints y transición verificados; ver secciones 15 y 15.4); **pendiente** el envío real del email (ver sección 20).
- **`productos_imagenes`** (Producto 1:N), con `producto_id`, `ruta`, `alt` y `orden`. En el MVP puede simplificarse a gestionar una sola imagen, sin impedir múltiples imágenes futuras.
- **Ajuste de `pedidos_estados`** a los estados definidos: `PEND_VERIF`, `PEND_PAGO`, `PROCESANDO_PAGO`, `PAGADO`, `NO_PAGADO`, `PAGADO_STOCK_NO_AFECTADO` (la tabla ya **no** contiene `PENDIENTE`, `CONFIRMADO`, `CANCELADO`).
- **Nueva entidad `validaciones_pagos`** (entidad conceptual `PaymentValidationResult`, relación **Pago 1:1 PaymentValidationResult**): `pago_id NOT NULL` con **`UNIQUE(pago_id)`**, FK `validaciones_pagos.pago_id → pagos.id` (la FK vive en el resultado, **no** en `pagos`, para evitar FK circular); columnas `id`, `pago_id`, `estado_id`, `proveedor_id`, `referencia_proveedor`, `importe`, `created_at`. (Nombre de tabla cerrado: `validaciones_pagos`; se conserva el nombre conceptual `PaymentValidationResult`.)
- **Los estados** `PENDIENTE`, `EN_PROCESO`, `APROBADO`, `RECHAZADO`, `CANCELADO` se referencian desde `validaciones_pagos.estado_id`; el catálogo `pagos_estados` **ya pasó a denominarse** `validaciones_pagos_estados` (renombramiento aplicado; **sin** duplicar el conjunto de estados ni crear un segundo catálogo paralelo).
- **Ajuste de `pagos`**: se **quitó** `pagos.estado_id → pagos_estados.id`, porque el estado del intento no vive en `pagos` sino en su `PaymentValidationResult` (`validaciones_pagos`).
- **Catálogo `proveedores`** (`id`, `codigo`) **existente** con las filas `MERCADOPAGO` y `DUMMY` (identificación por `codigo`, sin IDs numéricos fijos); `validaciones_pagos.proveedor_id → proveedores.id` (preparado para proveedores futuros sin duplicar entidades de pago). En el MVP, las validaciones generadas por `DummyPaymentProvider` referencian **`DUMMY`**; `MERCADOPAGO` queda reservado para `MercadoPagoProvider` (integración real futura).
- **Nueva entidad `casos_revision`** (`CasoRevision`): se relaciona con el **`Pago`** (no con el `Pedido`); columnas `id`, `pago_id`, `tipo_id`, `estado_id`, `created_at`, `updated_at`; **sin** campo `descripcion` y **sin** `UNIQUE(pago_id, tipo_id)` (la prevención de duplicados para el mismo episodio se resuelve a nivel de aplicación); catálogos `casos_revision_tipos` y `casos_revision_estados`, ambos con los campos `orden` y `activo`, ya creados y cargados; tipos exactos `PAGADO_STOCK_NO_AFECTADO` y `PAGO_PROCESANDO_EXCEDIDO`; estados exactos `PENDIENTE` y `RESUELTO`; se crea siempre como `PENDIENTE`; sin tipos, entidades ni estados adicionales (ver sección 12.8).
- `pedidos.email_verificacion_id` **ya existe** y es la trazabilidad de la verificación; **NO** se agrega `pedidos.email_verificado` (boolean) ni ningún equivalente.

Todavía **no materializadas** (previstas, sin tablas en la base):

- historial de precios: registra cada cambio de precio, con observación/motivo opcional.
- historial de interacciones con el proveedor de pago: traza mínima para auditoría e idempotencia.

**Tablas que NO existen en el MVP (verificado):**

- **NO** existe tabla `reservas` ni `reservas_estados`.
- **NO** existe tabla de "reservas", "compromisos" o equivalente.
- **NO** existe columna `stock_comprometido` en `productos`.
- **NO** existe tabla de carritos: el carrito es exclusivamente frontend (`sessionStorage`) y el backend no lo persiste (ver sección 16).

Invariantes de negocio que el esquema debe sostener:

- un pedido puede tener **como máximo un `PaymentValidationResult` `APROBADO`**;
- no pueden existir casos de revisión duplicados para el mismo episodio/evento (p. ej. un único `PAGO_PROCESANDO_EXCEDIDO` por episodio de procesamiento);
- la disponibilidad de un producto nunca debe resultar negativa (`stock físico − cantidades de PedidoItem de Pedidos activos ≥ 0`);
- el stock físico solo se reduce al confirmar un pago aprobado (operación atómica e idempotente).

---

## 10. Disponibilidad y stock

`productos.stock` representa exclusivamente el **stock físico real**.

La disponibilidad **NO es un dato persistente**: no existe columna ni tabla de disponibilidad; se calcula puntualmente bajo demanda.

El cálculo lo coordina `PedidoService`, con la información obtenida mediante `ProductoRepository` (stock físico, precio, activo) y `PedidoRepository` (cantidades de `PedidoItem` de Pedidos activos). `Producto` no conoce `Pedido` y `ProductoService` no calcula disponibilidad.

La disponibilidad se calcula **en caliente**:

```text
disponible = stock físico
             − cantidades de PedidoItem pertenecientes a Pedidos activos
```

Los estados de `Pedido` que afectan disponibilidad:

```text
PEND_VERIF
PEND_PAGO
PROCESANDO_PAGO
```

Los estados que NO afectan disponibilidad:

```text
PAGADO
NO_PAGADO
```

`PAGADO_STOCK_NO_AFECTADO` es excepcional; su resolución queda fuera del MVP.

- **NO** existe entidad `Reserva`, **NO** existe `stock_comprometido`, **NO** existe tabla de compromisos. El cálculo se hace a partir de los `PedidoItem` de los Pedidos activos.
- El **carrito no modifica la disponibilidad global**: agregar un producto al carrito no bloquea unidades. El carrito es privado del comprador.
- Al entrar al e-commerce y durante el carrito, el backend calcula la disponibilidad y la devuelve al frontend; el frontend la muestra (informativo) y la utiliza para limitar cuánto puede agregarse al carrito. Puede quedar desactualizado: **el frontend nunca es autoridad sobre el stock**.
- Cuando un `Pedido` pasa a `PAGADO`: su cantidad deja de computarse como compromiso y el stock físico ya fue reducido.
- Cuando un `Pedido` pasa a `NO_PAGADO`: sus cantidades dejan de computarse como compromiso y vuelven a quedar disponibles.
- No se modifican los `PedidoItem` para liberar stock.

### 10.1. Momentos de validación y comprobación de disponibilidad

Existen exactamente tres momentos conceptuales de comprobación (ver `ECOMMERCE_DESIGN.md`, sección 8.6). En el segundo y el tercero, el backend realiza la **validación comercial completa** (existencia del producto, producto activo/disponible, cantidades válidas, disponibilidad, precio actual y demás reglas comerciales); la disponibilidad es una parte de esa validación. El detalle de la validación y de la corrección está en la sección 17.

**Primera — mostrar productos:** únicamente informativa, en el frontend.

**Segunda — iniciar checkout:** se realiza **antes de crear el `Pedido`**.

- Si la validación es válida: se crea el `Pedido` en `PEND_VERIF` y sus `PedidoItem`; desde ese momento afectan la disponibilidad global.
- Si encuentra diferencias: no se crea un `Pedido` válido para la operación corregida; el backend devuelve el carrito corregido, la disponibilidad actualizada y el motivo de cada corrección.

**Tercera — antes de iniciar pago:** se realiza antes de iniciar el pago.

- Si la validación es válida: `PEND_PAGO → PROCESANDO_PAGO`.
- Si encuentra diferencias: no se inicia el pago y el backend devuelve una propuesta de corrección (carrito corregido propuesto, precios y disponibilidad actualizados del **catálogo completo activo** y motivo de cada corrección). El `Pedido` actual **NO** pasa inmediatamente a `NO_PAGADO`: conserva su estado (`PEND_VERIF` o `PEND_PAGO`) y sigue afectando la disponibilidad mientras el usuario decide, y sus `PedidoItem` NO se modifican. Si el usuario **acepta**, se repite la validación comercial completa y, si resulta válida, **en la misma operación/transacción** el `Pedido` anterior pasa a `NO_PAGADO` y se crea un **nuevo** `Pedido` con nuevos `PedidoItem` que hereda el estado previo, reutilizando la verificación de email; si vuelve a haber diferencias se devuelve una nueva corrección **sin sustituir** el `Pedido`. Si el usuario **cancela** (cancelación voluntaria, `POST /api/pedidos/{id}/cancelar`, ver secciones 17.2 y 19.4) el `Pedido` pasa a `NO_PAGADO`. No se modifican los `PedidoItem` anteriores y no existe relación especial entre ambos Pedidos. Un reemplazante que herede `PEND_PAGO` igualmente debe volver a pasar por esta tercera validación cuando se intente iniciar el pago.

**Implementado el 30/09/2026:** la tercera validación, la corrección con `Pedido` existente y la aceptación de corrección están implementadas en `POST /api/pedidos/{id}/pago` (ver secciones 17.2 y 19.4), **y el mismo bloque de pagos completó la transición `PEND_PAGO → PROCESANDO_PAGO` y la creación del `Pago`** (ver sección 21): `iniciar_pago` recibe `proveedor_id` + `items[]`, valida el carrito recibido contra el persistido (diferencias → `correccion` con motivo `carrito_desactualizado`), ejecuta la tercera validación comercial y sólo entonces registra el intento y transiciona. El carrito que valida la tercera comprobación es el **recibido en el request**, que debe coincidir con el **persistido en el `Pedido`** (`pedidos_items`, inmutable); toda edición del carrito pasa por `aceptar_correccion`, que sí recibe `items[]` del frontend.

En una corrección, el nuevo `Pedido` **nace con el mismo estado que tenía el original antes de la corrección** (`PEND_VERIF` o `PEND_PAGO`): la corrección no fuerza repetir la verificación de email ya cumplida. El único caso en que un `Pedido` nuevo nace en `PEND_VERIF` por ser un checkout nuevo es cuando no había corrección (ver sección 17.1 y la tabla de transiciones de la sección 11).

No se realiza una nueva comprobación de stock por la verificación del email (`PEND_VERIF → PEND_PAGO`). La única validación que no se repite es la de email ya verificada; la validación comercial siempre se repite.

### 10.2. Concurrencia

La creación del `Pedido` y sus `PedidoItem` solamente debe concretarse si la cantidad solicitada sigue disponible.

Si dos usuarios compran simultáneamente y la primera operación consume la disponibilidad, la segunda debe volver a verificar: si no hay cantidad suficiente, NO se crea el segundo `Pedido` y se informa la disponibilidad actual.

**El mecanismo técnico concreto para garantizar esto en MariaDB está definido e implementado para la creación del `Pedido`: transacción de MariaDB + `SELECT ... FOR UPDATE` de las filas de `productos` en orden ascendente de `producto_id`, con `ROLLBACK` ante cualquier discrepancia** (request, respuestas y reglas de concurrencia en la sección 17.1; reglas generales de transacciones e idempotencia en la sección 19.6). Verificado: dos checkouts simultáneos por el stock completo generan un solo `201` y una corrección, sin deadlocks. El mecanismo equivalente para la **afectación de stock con pago aprobado** sigue pendiente de implementación (ver sección 20).

### 10.3. Descuento del stock físico

El stock físico **solo se descuenta cuando el pago queda definitivamente aprobado** (su `PaymentValidationResult` queda `APROBADO`).

En el MVP, la afectación es directa sobre el stock del producto:

```text
Producto.stock = Producto.stock − PedidoItem.cantidad
```

**No existe historial de movimientos de stock en el MVP.**

La operación de aprobación del pago y afectación del stock debe tratarse como una **operación atómica a nivel de persistencia**: no puede quedar parcialmente aplicada, y debe ser **idempotente** (procesar dos veces la misma aprobación no descuenta stock dos veces).

Conceptualmente, la operación crítica contempla dentro de una **transacción de MariaDB**:

1. verificar que el pago/operación todavía puede producir efectos;
2. verificar las condiciones necesarias;
3. verificar que no exista ya un efecto aplicado;
4. afectar el stock (`Producto.stock = Producto.stock − PedidoItem.cantidad`);
5. registrar/actualizar el `PaymentValidationResult` (`APROBADO`);
6. resolver el caso de revisión pendiente asociado al episodio (`PAGO_PROCESANDO_EXCEDIDO` → `RESUELTO`) cuando corresponda;
7. establecer el `Pedido` en `PAGADO`.

Si una parte crítica falla: **rollback**; no se deja el sistema en un estado parcialmente aplicado.

Si la afectación de stock no puede completarse pese a la aprobación: `PROCESANDO_PAGO → PAGADO_STOCK_NO_AFECTADO` y se genera el caso de revisión `PAGADO_STOCK_NO_AFECTADO` (ver `ECOMMERCE_DESIGN.md`, sección 8.10, y sección 12.8 de este documento). Ese caso es excepcional (auditoría/recovery), no un flujo comercial normal; su resolución queda fuera del MVP.

**Implementado el 30/09/2026** dentro del flujo de `POST /api/pedidos/{id}/pago`: la aprobación corre en la segunda transacción del flujo (ver secciones 17.2 y 19.6), con bloqueo `SELECT ... FOR UPDATE` de las filas de `productos` involucrados, verificación de stock de **todas** las cantidades **antes** de descontar (nunca queda un descuento parcial), descuento condicional (`UPDATE ... SET stock = stock - ? WHERE id = ? AND stock >= ?`, verificado por `rowCount`), resolución del caso `PAGO_PROCESANDO_EXCEDIDO` pendiente y transición a `PAGADO`, todo en la misma transacción; ante cualquier fallo: `ROLLBACK`. El importe informado por el proveedor distinto del total calculado impide el descuento y el paso a `PAGADO` (queda `PROCESANDO_PAGO` + caso `PAGADO_STOCK_NO_AFECTADO`; ver sección 12.7). **Idempotencia verificada:** un resultado definitivo no se sobrescribe y una aprobación repetida no descuenta stock dos veces (ver secciones 12.6, 14 y 19.6).

---

## 11. Estados del Pedido

Estados normales, definidos exactamente:

```text
PEND_VERIF
PEND_PAGO
PROCESANDO_PAGO
PAGADO
NO_PAGADO
```

Estado excepcional, **no terminal normal**:

```text
PAGADO_STOCK_NO_AFECTADO
```

No agregar otros estados de `Pedido`.

Flujo normal:

```text
PEND_VERIF → PEND_PAGO → PROCESANDO_PAGO → PAGADO
```

Transiciones definidas:

| Desde | Hasta | Condición |
|---|---|---|
| (nuevo `Pedido`, checkout nuevo) | `PEND_VERIF` | validación comercial válida al iniciar checkout (2.ª comprobación) |
| (nuevo `Pedido`, corrección de carrito) | mismo estado que tenía el original antes de la corrección (`PEND_VERIF` o `PEND_PAGO`) | validación comercial válida tras aceptar el carrito corregido (sección 17.2); se reutiliza la verificación de email |
| `PEND_VERIF` | `PEND_PAGO` | verificación de email exitosa (sin nueva comprobación de stock) |
| `PEND_PAGO` | `PROCESANDO_PAGO` | validación comercial válida al iniciar pago (3.ª comprobación) y pago iniciado |
| `PEND_PAGO` | `NO_PAGADO` | vencido el primer período; o cancelación/descarte voluntario del usuario en checkout (cancelación voluntaria o rechazo de la corrección, ver secciones 17.2 y 19.4); o sustitución por aceptación de corrección (en la misma operación/transacción, ver sección 17.2); o descarte en la recuperación de pedidos pendientes (sección 18) |
| `PEND_VERIF` | `NO_PAGADO` | vencido el primer período; o cancelación/descarte voluntario del usuario en checkout (cancelación voluntaria o rechazo de la corrección, ver secciones 17.2 y 19.4); o sustitución por aceptación de corrección (en la misma operación/transacción, ver sección 17.2); o descarte en la recuperación de pedidos pendientes (sección 18) |
| `PROCESANDO_PAGO` | `PAGADO` | pago aprobado y stock afectado (incluye un `APROBADO` posterior al vencimiento de la segunda ventana; en su caso se resuelve el caso de revisión pendiente, sección 12.8) |
| `PROCESANDO_PAGO` | `PAGADO_STOCK_NO_AFECTADO` | pago aprobado y stock no afectado (excepcional; se genera el caso de revisión `PAGADO_STOCK_NO_AFECTADO`; resolución fuera del MVP) |
| `PROCESANDO_PAGO` | `PEND_PAGO` | pago rechazado (incluido un `RECHAZADO` posterior al vencimiento de la segunda ventana), quedan intentos y primer período vigente; antes se resuelve el caso de revisión pendiente si existía |
| `PROCESANDO_PAGO` | `NO_PAGADO` | alcanzado `PAGO_MAX_INTENTOS` |
| `PROCESANDO_PAGO` | `PROCESANDO_PAGO` | segunda ventana vencida sin resolución: permanece en el estado y se genera el caso de revisión `PAGO_PROCESANDO_EXCEDIDO` (`PENDIENTE`, sin duplicados para el mismo episodio) (NO crea `PAGO_EN_CONCILIACION`) |
| `PROCESANDO_PAGO` | `PROCESANDO_PAGO` | `APROBADO` con inconsistencias (importe diferente, `Pedido` incompatible o segundo `Pago` aprobado del mismo `Pedido`): se registra el resultado, no se afecta stock, no se pasa a `PAGADO` y se genera el caso de revisión correspondiente (sección 12.8) |

En la **recuperación de pedidos pendientes** (sección 18; sólo puede ofrecerse desde un Pedido actual verificado en `PEND_PAGO`) se aplican: `PEND_VERIF → PEND_PAGO` (el Pedido anterior recupera heredando la verificación de email del Pedido actual) y los descartes `→ NO_PAGADO` de la fila anterior (el Pedido no elegido). `PROCESANDO_PAGO` queda **fuera** de la regla de un único Pedido recuperable: no participa del mecanismo y continúa independientemente.

Estados terminales normales: `PAGADO`, `NO_PAGADO`.

`PAGADO_STOCK_NO_AFECTADO` es excepcional y no terminal; genera un caso de revisión `PAGADO_STOCK_NO_AFECTADO` (`PENDIENTE`) y una condición de alerta/conciliación, y la resolución de ese caso queda fuera del MVP.

Los casos de revisión (`CasoRevision`) están definidos en `ECOMMERCE_DESIGN.md`, sección 13.8, y su detalle técnico en la sección 12.8 de este documento.

La duración del primer período (**valor inicial de diseño: 10 minutos**; **valor operativo actual: 40 minutos** desde el 03/10/2026, ajuste operativo no definitivo — ver sección 13.1), la duración del segundo período, `PAGO_MAX_INTENTOS` y `PAGO_MAX_ESPERA_CONCILIACION` están definidos como **parámetros parametrizables**: el primer período ya tiene valor inicial cerrado y los demás no fijan valores concretos en esta etapa (ver sección 13.1). No son pendientes de diseño.

`pedidos_estados` **ya contiene los 6 estados definidos** (`PEND_VERIF`, `PEND_PAGO`, `PROCESANDO_PAGO`, `PAGADO`, `NO_PAGADO`, `PAGADO_STOCK_NO_AFECTADO`); el ajuste antes pendiente fue aplicado y verificado (sección 9).

---

## 12. Pago y proveedor

### 12.1. Entidad Pago

`Pago` es una entidad persistente del sistema que representa un **intento de cobro** desde la perspectiva del e-commerce. **Es independiente del proveedor.**

- Un `Pedido` puede tener múltiples `Pago`, cada uno de los cuales representa un **intento de pago**.
- Los intentos anteriores no se sobrescriben.
- `Pago` tiene los campos conceptuales: `id`, `pedido_id`, `importe`, `created_at`.
- **`Pago` NO tiene estado propio**: no contiene estado del pago, proveedor, comisión, neto recibido ni referencia del proveedor. Esa información vive en su `PaymentValidationResult` (sección 12.1.3).
- Un pedido puede tener **como máximo un `PaymentValidationResult` `APROBADO`** (invariante protegida mediante transacciones/bloqueos e idempotencia).

### 12.1.1. Relación Pedido y Pago

La relación es **Pedido 1:N Pago**:

- un `Pedido` puede tener cero, uno o varios intentos de pago;
- cada `Pago` es un intento independiente y conserva su propio `importe`;
- no existe un campo persistente `numero_pago` / `numero_intento`. El orden/número del intento se determina dinámicamente a partir de los `Pago` asociados al `Pedido` y su orden cronológico;
- `PAGO_MAX_INTENTOS` representa el máximo total de intentos por pedido, incluyendo el primero (ver sección 14).

### 12.1.2. Importe del Pago

Cada `Pago` conserva su propio `importe`, registrado independientemente del total calculado del `Pedido`.

El `Pedido` **no** tiene `importe_total` (ver sección 9); su total se calcula dinámicamente como `Σ(cantidad × precio_unitario)` de sus `pedidos_items`.

Registrar el `importe` en cada `Pago` permite auditoría y detección de diferencias. Para ello no se duplica el total en `Pedido`.

Importes, con significados separados:

| Importe | Significado | Momento |
|---|---|---|
| total comercial del `Pedido` (`Σ(cantidad × precio_unitario)`) | lo que el cliente debe pagar | dinámico, a partir de los items |
| `Pago.importe` | lo que **nuestro sistema solicita cobrar** (en el MVP: = total comercial; sin descuentos) | al crear el `Pago` |
| `PaymentValidationResult.importe` | lo que **el proveedor informa/validó** (auditoría y detección de diferencias) | al validar contra el proveedor |

Comisiones/fees del proveedor, impuestos/cargos y neto liquidado: **fuera del MVP** (futura liquidación/conciliación). No se agregan a `Pago` ni a `PaymentValidationResult` y no modifican lo que paga el cliente.

### 12.1.3. PaymentValidationResult (resultado de la validación)

`PaymentValidationResult` es la entidad persistente que guarda el resultado de nuestra validación contra el proveedor para cada intento. Su nombre de tabla es **`validaciones_pagos`** (se conserva el nombre conceptual `PaymentValidationResult`).

- Relación **Pago 1:1 PaymentValidationResult**.
- `pago_id NOT NULL` y **`UNIQUE(pago_id)`**: cada `Pago` tiene exactamente un resultado.
- **No** se crean múltiples `PaymentValidationResult` para el mismo `Pago`: si el proveedor informa nuevos resultados sobre el mismo `Pago`, se **actualiza** el registro existente (respetando la lógica ya definida). Cada intento diferente de pago (`Pago`) tiene su propio resultado de validación.
- La FK vive en el resultado: `validaciones_pagos.pago_id → pagos.id` (sin FK circular desde `pagos`).
- Campos conceptuales: `id`, `pago_id`, `estado_id`, `proveedor_id`, `referencia_proveedor`, `importe`, `created_at`.
- `estado_id` referencia el catálogo de estados `validaciones_pagos_estados` (renombramiento de `pagos_estados`): `PENDIENTE`, `EN_PROCESO`, `APROBADO`, `RECHAZADO`, `CANCELADO` (conjunto exacto, sin estados paralelos).
- `proveedor_id` referencia el catálogo `proveedores` (sección 12.2).
- `referencia_proveedor` es la referencia persistente/auditable del recurso en el proveedor; los datos temporales de checkout (p. ej. `checkout_url`) **no** son campos persistentes de este resultado (sección 12.5).
- `importe` = importe informado/validado por el proveedor.

#### Creación del intento

Al iniciar el proceso de validación:

1. se crea el `Pago`;
2. se crea su único `PaymentValidationResult` (inicialmente en un estado no definitivo, p. ej. `EN_PROCESO`);
3. recién entonces se interactúa con el proveedor.

Esto permite registrar el intento aunque el proveedor posteriormente no responda o exista un problema técnico. El frontend **NO** determina que un pago está aprobado: la confirmación definitiva la realiza el backend validando contra el proveedor.

### 12.2. Frontera con el proveedor (validación de pagos)

Decisión arquitectónica fundamental: **el resto del sistema no conoce directamente al proveedor de pago.**

```text
DummyPaymentProvider (MVP)
      ↕
PaymentProvider (contrato)
      ↕
PaymentValidationService
      ↕
resto del sistema
```

- La cadena completa del MVP es `PaymentValidationService → PaymentProvider → DummyPaymentProvider` (resultado simulado). La evolución prevista, sobre el mismo contrato, es `PaymentValidationService → PaymentProvider → MercadoPagoProvider → Mercado Pago` (**POST-MVP**).
- `PaymentValidationService` es la **única capa de negocio autorizada** a interactuar con el proveedor mediante el adaptador correspondiente.
- No deben existir llamadas directas al proveedor desde ningún Service del dominio (por ejemplo, `PedidoService`): sólo `PaymentValidationService` interactúa con el proveedor, vía `PaymentProvider`.
- `PaymentProvider` define el contrato del adaptador; `DummyPaymentProvider` lo implementa en el MVP con una **simulación** que ejercita los resultados `APROBADO` y `RECHAZADO` (los dos únicos valores que admite el parámetro `simulacion` del simulador del MVP desde el 05/10/2026; `EN_PROCESO` queda excluido del simulador y se reserva para proveedores futuros con resolución asincrónica — ver sección 21), y `MercadoPagoProvider` lo implementará para Mercado Pago real (POST-MVP), encapsulando los detalles concretos (endpoints, credenciales, formato de respuestas), de modo que el dominio interno no dependa del proveedor.
- Intercambiar `DummyPaymentProvider` por `MercadoPagoProvider` **no modifica** la lógica de negocio, los estados, la normalización ni la persistencia.
- Las credenciales del proveedor permanecen **sólo en backend** (nunca en el frontend); `DummyPaymentProvider` no usa credenciales reales.
- Flujo conceptual (MVP): `Frontend → Backend → PaymentValidationService → PaymentProvider → DummyPaymentProvider`.
- Flujo completo (MVP):

```text
Frontend
    ↓
Backend
    ↓
validación del carrito
    ↓
crear Pago
    ↓
crear PaymentValidationResult
    ↓
PaymentProvider → DummyPaymentProvider
    ↓
resultado simulado (APROBADO / RECHAZADO)
    ↓
PaymentValidationService (consulta/valida contra el proveedor)
    ↓
PaymentValidationResult normalizado
    ↓
PedidoService
```

Con la integración real (POST-MVP), los pasos `PaymentProvider → DummyPaymentProvider` se reemplazan por `PaymentProvider → MercadoPagoProvider → Mercado Pago`, y la confirmación se dispara desde la notificación webhook (`POST /api/webhooks/mercadopago`); la normalización, las reglas de negocio y la persistencia no cambian.

- Flujo interno: `Controller → PaymentValidationService → PaymentProvider (DummyPaymentProvider en el MVP) → PaymentValidationResult normalizado → reglas de negocio → Repository / persistencia`. No se acopla el dominio interno a los estados propios de Mercado Pago ni a los escenarios del simulador.
- En el MVP el usuario atraviesa un **paso de pago simulado**; con la integración real (POST-MVP) realizará la operación en el **checkout de Mercado Pago**.
- Con la integración real, Mercado Pago podrá devolver al usuario a nuestro sitio con información sobre el resultado, pero **el retorno del navegador NO es la fuente definitiva de verdad** para actualizar el `Pedido` (tampoco lo es con el proveedor simulado). La confirmación definitiva se realiza mediante el mecanismo de validación del backend (ver sección 12.6).
- Mercado Pago (a través de `MercadoPagoProvider`, identidad `MERCADOPAGO`) es la primera implementación/proveedor real prevista; en el MVP el proveedor registrado es **`DUMMY`** (adaptador `DummyPaymentProvider`).
- El proveedor se recibe de forma explícita (`proveedor_id` al iniciar el pago; ver sección 19.4) y se resuelve contra el catálogo `proveedores`.
- Para el MVP, Mercado Pago sigue siendo el **único** medio de pago concebido (no se admite comprar con múltiples medios/proveedores reales), pero las validaciones simuladas **no** se registran como producidas por Mercado Pago: se registran con el proveedor `DUMMY`.
- La integración con Mercado Pago es **POST-MVP / extensión opcional**: el MVP se implementa con `DummyPaymentProvider`. En ambos casos se mantiene la frontera: el resto del sistema consume el resultado normalizado y no conoce al proveedor.

#### Catálogo de proveedores

- Catálogo `proveedores` (`id`, `codigo`), **ya existente físicamente** con las filas `MERCADOPAGO` y `DUMMY`; la identificación es por `codigo` (no se asumen IDs numéricos fijos). El catálogo distingue la implementación real que produjo una validación de pago.
- Mapeo cerrado: **MVP** → `DummyPaymentProvider → DUMMY` (las filas de `validaciones_pagos` generadas por el simulador referencian `DUMMY`); **integración real futura (POST-MVP)** → `MercadoPagoProvider → MERCADOPAGO`.
- `PaymentValidationResult.proveedor_id` lo referencia.
- Motivo: no registrar una validación simulada como si hubiera sido producida realmente por Mercado Pago; se conserva la trazabilidad entre pagos simulados del MVP y pagos reales de Mercado Pago.
- Preparado para proveedores futuros: un nuevo proveedor agrega un valor y su adaptador `PaymentProvider` correspondiente (p. ej. `MercadoPagoProvider`), sin duplicar entidades de pago.

#### Modalidad de checkout (detalle de implementación, POST-MVP)

La modalidad concreta de checkout de Mercado Pago (Checkout Pro vs Checkout API/Orders) es un **detalle de implementación** encapsulado en `MercadoPagoProvider` (**POST-MVP**), y **NO** constituye una decisión de diseño ni un pendiente de diseño. La arquitectura no se acopla a una modalidad: la frontera de validación (`PaymentValidationService → PaymentProvider → [DummyPaymentProvider | MercadoPagoProvider]`) no cambia con esa elección.

**Implementado el 30/09/2026 (MVP):** la frontera existe en código — `src/payments/PaymentProvider.php` (contrato: `codigo()` y `procesarIntento(pagoId, importe, resultadoSimulado)` → `['estado','importe','referencia_proveedor']`), `src/payments/DummyPaymentProvider.php` (identidad `DUMMY`; por defecto devuelve `APROBADO` y también acepta `RECHAZADO` vía el parámetro opcional `simulacion` del request — `EN_PROCESO` **no** es un valor admitido: el endpoint lo rechaza con `400 {"error":"simulacion inválida"}` desde el 05/10/2026 (ver secciones 17.2 y 21); `referencia_proveedor = DUMMY-{pagoId}`; un resultado fuera de los valores admitidos lanza `PaymentProviderException`), `src/payments/PaymentProviderException.php` y `src/services/PaymentValidationService.php`. `PaymentValidationService` es el **único** componente que instancia/invoca al adaptador (lo recibe por inyección desde `public/index.php`); `PedidoService` nunca llama directamente al proveedor: sólo consume el resultado normalizado. La simulación concreta de `DummyPaymentProvider` (campo `simulacion` del request de `iniciar_pago`) es un **detalle de implementación del MVP** y no agrega estados ni reglas nuevas (ver sección 17.2).

### 12.3. Estados del proveedor → estados del resultado

Los estados específicos del proveedor no forman parte del dominio de negocio. Ejemplo conceptual (proveedor real, POST-MVP):

```text
Mercado Pago: approved / pending / in_process / cancelled / ...
```

El `DummyPaymentProvider` (MVP) produce escenarios simulados equivalentes (`APROBADO` y `RECHAZADO` — los dos únicos valores que admite el simulador del MVP desde el 05/10/2026; `EN_PROCESO` queda reservado para proveedores con resolución asincrónica, POST-MVP) que atraviesan el mismo camino de normalización. La integración debe **traducir los estados del proveedor a estados de `PaymentValidationResult`**. No se dispersan condiciones específicas de Mercado Pago ni del simulador por el sistema, ni se agrega un segundo conjunto de estados.

### 12.4. Resultado normalizado del pago

`PaymentValidationResult` es a la vez el contrato interno mediante el cual el servicio de validación informa al resto del sistema el resultado normalizado y la entidad donde ese resultado se persiste (sección 12.1.3).

```text
Proveedor: approved
      ↓
PaymentValidationService
      ↓
PaymentValidationResult: APROBADO (persistido)
```

- El estado normalizado es el que se persiste en `PaymentValidationResult.estado_id`; **no** se persiste en `pagos`.
- Los estados exactos son: `PENDIENTE`, `EN_PROCESO`, `APROBADO`, `RECHAZADO`, `CANCELADO` (ver sección 12.1.3). No se crea otro conjunto paralelo.
- `APROBADO` requiere **validación efectiva contra el proveedor**: no basta con que el cliente diga haber pagado ni con el retorno del navegador.
- `RECHAZADO` es el rechazo definitivo informado/validado por el proveedor.
- Un resultado técnico de error **no** se convierte automáticamente en `RECHAZADO`.
- Si el proveedor no puede ser consultado o el estado no puede determinarse: `estado del resultado = DESCONOCIDO / no determinado` (estado no definitivo), y el sistema conserva la operación abierta para conciliación.
- Los detalles específicos del proveedor permanecen encapsulados en el adaptador (`DummyPaymentProvider` en el MVP; `MercadoPagoProvider` en la integración real, POST-MVP).

### 12.5. Información del proveedor: persistente vs. temporal

La entidad `Pago` no debe acumular detalles técnicos específicos del proveedor, y `PaymentValidationResult` sólo conserva lo persistente/auditable. Se separan conceptualmente:

- **Persistente/auditable**: `referencia_proveedor` (referencia estable del recurso en el proveedor) y el resto de columnas de `PaymentValidationResult` (`estado_id`, `proveedor_id`, `importe`, `created_at`).
- **Temporal de checkout**: datos útiles sólo para iniciar/redirigir el pago (p. ej. `checkout_url`), que se devuelven al frontend desde el adaptador pero **no** se convierten automáticamente en columnas persistentes.

`PaymentValidationResult` es el resultado normalizado que el adaptador (`PaymentProvider` → `DummyPaymentProvider` en el MVP) devuelve a `PaymentValidationService`: incluye `referencia_proveedor` (persistente) y `datos_checkout` (temporales); los detalles concretos del proveedor quedan encapsulados en el adaptador.

Además debe existir una separación conceptual entre `Pago`/`PaymentValidationResult` y las **interacciones con el proveedor**.

El historial de interacciones debe permitir conservar, cuando corresponda:

- proveedor;
- tipo de interacción;
- referencia externa;
- estado externo;
- resultado normalizado;
- fecha/hora;
- identificador de webhook/evento;
- información relevante de la respuesta.

Se implementa en el MVP en la medida **mínima necesaria** para garantizar trazabilidad e idempotencia. La conciliación masiva/contable queda para el futuro.

### 12.6. Mecanismo de confirmación y notificación del proveedor

La confirmación definitiva de un resultado siempre la realiza el backend: `PaymentValidationService` consulta/valida contra el proveedor a través de `PaymentProvider` y normaliza el resultado. El mecanismo es genérico e independiente del proveedor.

```text
Evento de confirmación (MVP)
   ↓
PaymentValidationService
   ↓
consulta/validación contra el proveedor (PaymentProvider → DummyPaymentProvider)
   ↓
estado actual validado
   ↓
PaymentValidationResult
```

Con la integración real (POST-MVP) el mismo flujo se dispara desde la notificación webhook de Mercado Pago (`POST /api/webhooks/mercadopago`), sin modificar la normalización ni la lógica de negocio.

- El backend **no confía ciegamente** en ningún dato externo (retorno del navegador, notificación o respuesta del simulador): siempre se consulta el estado actual al proveedor, se valida y se normaliza el resultado antes de actualizar el sistema.
- La notificación/confirmación **no decide directamente** el estado comercial del `Pedido`: informa el cambio y normaliza el resultado; la lógica comercial continúa centralizada en `PedidoService`.
- El endpoint `POST /api/webhooks/mercadopago` y su notificación pertenecen a la **integración real con Mercado Pago (POST-MVP)**. La **autenticación/verificación específica de las notificaciones webhook de Mercado Pago queda FUERA DEL MVP como definición funcional**: sólo se documenta como detalle de implementación/provider-specific para una etapa posterior. **No** se definen ahora requisitos concretos de firmas, headers, secretos, algoritmos ni mecanismos de autenticación.
- La actualización del sistema debe ser **idempotente**: una notificación/confirmación repetida (o un webhook duplicado cuando exista) o una repetición de la misma operación **NO** debe descontar stock nuevamente, **NO** debe crear otro `Pago` para la misma operación, **NO** debe generar otro `APROBADO`, **NO** debe volver a aplicar una transición ya aplicada ni producir efectos comerciales duplicados. Regla: **máximo un `PaymentValidationResult` `APROBADO` por `Pedido`**; el procesamiento reconoce operaciones ya procesadas mediante las relaciones internas y `referencia_proveedor`. Debe existir una estrategia técnica de identificación/validación de la operación y protección contra duplicados; su implementación concreta queda para la etapa de desarrollo. La regla aplica igual con `DummyPaymentProvider` que con un proveedor real.
- No se implementa polling permanente.
- Debe existir diseño para idempotencia y trazabilidad de las interacciones con el proveedor (incluido el historial de interacciones de la sección 12.5); el detalle técnico de esa implementación queda para la etapa correspondiente.
- Ante un pago que supera el tiempo máximo parametrizado sin resultado definitivo se genera el caso de revisión `PAGO_PROCESANDO_EXCEDIDO` (`PENDIENTE`, sin duplicados para el mismo episodio; ver secciones 12.8 y 13). Si luego llega un resultado definitivo, el caso se resuelve y continúa la lógica normal del `Pedido`; la **resolución administrativa** de los casos que nunca reciben un resultado definitivo queda fuera del alcance del MVP.

### 12.7. Resultados del pago: transiciones y comunicación

`PaymentValidationService` normaliza los resultados del proveedor y el resto del sistema consume esa normalización (ver sección 12.4).

**APROBADO:**

- el `PaymentValidationResult` queda `APROBADO`;
- si existía un caso de revisión pendiente asociado al episodio de procesamiento (`PAGO_PROCESANDO_EXCEDIDO`), se marca `RESUELTO` (sección 12.8);
- si el stock fue afectado correctamente: `PROCESANDO_PAGO → PAGADO`; si no: `PROCESANDO_PAGO → PAGADO_STOCK_NO_AFECTADO` (excepcional, ver sección 10.3) y se genera el caso de revisión `PAGADO_STOCK_NO_AFECTADO`;
- si el `APROBADO` presenta inconsistencias (importe diferente, `Pedido` incompatible o un segundo `Pago` aprobado del mismo `Pedido`): se registra el resultado, **no** se afecta stock, **no** se pasa el `Pedido` a `PAGADO`, **no** se modifica indebidamente su estado comercial y se genera el caso de revisión correspondiente (sección 12.8);
- una notificación duplicada del mismo pago aprobado **no** vuelve a afectar stock ni vuelve a ejecutar la transición comercial;
- se informa al usuario que el pago fue correcto;
- se envía un email con los datos de verificación de la transacción (email post-pago; contenido y plantilla a definir en la etapa de diseño técnico/UX).

**RECHAZADO:**

- el `PaymentValidationResult` queda `RECHAZADO`;
- si existía un caso de revisión pendiente asociado al episodio de procesamiento, se marca `RESUELTO` antes de continuar (sección 12.8);
- no se crea automáticamente otro intento: el nuevo `Pago` sólo se crea cuando el usuario inicia efectivamente un nuevo intento (sección 14);
- se informa al usuario que el pago fue rechazado;
- si quedan intentos y el primer período vigente continúa: `PROCESANDO_PAGO → PEND_PAGO` y se ofrece un nuevo intento;
- si se alcanzó `PAGO_MAX_INTENTOS`: `PROCESANDO_PAGO → NO_PAGADO`;
- un `RECHAZADO` **no** revierte un `Pedido` que ya está `PAGADO`.

**Resultados no definitivos** (`PENDIENTE`, `EN_PROCESO`, `DESCONOCIDO` o imposibilidad técnica de determinar el resultado):

- **NO** se convierten automáticamente en `RECHAZADO`;
- el `Pedido` permanece en `PROCESANDO_PAGO`;
- no se libera disponibilidad mientras el resultado no sea definitivo;
- si se supera el tiempo máximo parametrizado se genera el caso de revisión `PAGO_PROCESANDO_EXCEDIDO` (`PENDIENTE`, sin duplicados; ver secciones 12.8 y 13);
- la **resolución administrativa** de los casos que nunca reciben un resultado definitivo queda fuera del MVP (ver sección 13);
- **alcance en el MVP (05/10/2026):** estos resultados no son alcanzables mediante `simulacion` (el simulador sólo admite `APROBADO`/`RECHAZADO` y resuelve siempre de forma definitiva); sólo un error técnico del proveedor podría dejar un resultado no definitivo, comportamiento documentado y reservado para la evolución futura con webhook/conciliación (POST-MVP — ver sección 21).

**Implementado el 30/09/2026:** las transiciones de este bloque se aplican en `PedidoService` (`aplicarAprobado()`, `aplicarRechazado()`, `aplicarResultadoPago()`) a partir del `PaymentValidationResult` normalizado devuelto por `PaymentValidationService`. Un error técnico del proveedor se captura como `PaymentProviderException`, se registra en el log y se responde `200 {"resultado":"en_proceso"}` **sin** convertirlo en `RECHAZADO` (el `PaymentValidationResult` conserva su estado no definitivo). Un resultado desconocido del proveedor se normaliza como `EN_PROCESO` (nunca como `RECHAZADO`). El caso `PAGO_PROCESANDO_EXCEDIDO` (segundo período excedido) se genera fuera de este flujo y no está implementado en el MVP (sin segundo período automatizado ni endpoint de confirmación de `EN_PROCESO`), pero su **resolución** al llegar un resultado definitivo sí: `aplicarAprobado()`/`aplicarRechazado()` lo pasan a `RESUELTO`.

### 12.8. Casos de revisión (`CasoRevision`)

Las reglas funcionales completas están en `ECOMMERCE_DESIGN.md`, sección 13.8. Definición técnica:

- **Relación:** el caso se relaciona con el **`Pago`** (`casos_revision.pago_id → pagos.id`), no con el `Pedido`.
- **Columnas:** `id`, `pago_id`, `tipo_id`, `estado_id`, `created_at`, `updated_at`.
- **Sin** campo `descripcion`.
- **Sin** `UNIQUE(pago_id, tipo_id)`: la prevención de duplicados para el mismo episodio/evento se resuelve **a nivel de aplicación** (invariante de la sección 9).
- **Catálogos:** `casos_revision_tipos` y `casos_revision_estados`, ambos con los campos `orden` y `activo` además del identificador y la descripción.
- **Tipos (cerrados):** `PAGADO_STOCK_NO_AFECTADO` y `PAGO_PROCESANDO_EXCEDIDO`. No se agregan tipos nuevos.
- **Estados (cerrados):** `PENDIENTE` y `RESUELTO`. Los casos se crean siempre como `PENDIENTE`.
- **No** se crean entidades ni estados de `Pedido` adicionales para resolver estos escenarios; no existe una lógica paralela de revisión.

**Generación:**

- `PAGO_PROCESANDO_EXCEDIDO` → cuando un `Pedido` en `PROCESANDO_PAGO` supera el tiempo máximo parametrizado sin resultado definitivo; el `Pedido` **permanece en `PROCESANDO_PAGO`** y **no** se crean casos duplicados para el mismo episodio/evento (invariante de la sección 9).
- `PAGADO_STOCK_NO_AFECTADO` → cuando el pago queda aprobado pero los efectos comerciales no pueden completarse (stock no afectado, ver sección 10.3, o un `APROBADO` con inconsistencias).

**Resolución (el caso no congela el `Pedido`):**

1. se registra el resultado definitivo en el `PaymentValidationResult` correspondiente;
2. el caso pendiente pasa a `RESUELTO` cuando corresponde;
3. continúa la lógica normal del `Pedido` según ese resultado (sección 12.7 y tabla de transiciones de la sección 11).

**Fuera del MVP:** la resolución administrativa de los casos `PAGADO_STOCK_NO_AFECTADO` y de los `PAGO_PROCESANDO_EXCEDIDO` que nunca reciben un resultado definitivo.

---

## 13. Tiempos, reintentos y conciliación

### 13.1. Configuraciones previstas

Parámetros cuyo comportamiento ya está **cerrado como parametrizable** (no son pendientes de diseño; el primer período tiene valor inicial de diseño cerrado de **10 minutos** y **valor operativo actual de 40 minutos** desde el 03/10/2026 — ajuste operativo no definitivo, de fácil ajuste):

```text
duración del primer período del Pedido   (valor inicial de diseño: 10 minutos; valor operativo actual: 40 minutos — no definitivo)
duración del segundo período del Pedido
PAGO_MAX_INTENTOS
PAGO_MAX_ESPERA_CONCILIACION
```

Los valores se cargan en configuración durante la implementación.

> Los nombres de configuración anteriores `RESERVA_CHECKOUT_MINUTOS` y `RESERVA_PAGO_MINUTOS` **quedan eliminados**: no existe `Reserva` en el MVP. El nombre definitivo de las dos ventanas se definirá en el diseño técnico.

### 13.2. Primer período del Pedido

Comienza **exactamente cuando se crea el `Pedido`** al iniciar checkout.

- NO comienza al agregar productos al carrito.
- NO comienza al enviar el email.

Cubre el tiempo en `PEND_VERIF` y `PEND_PAGO`.

Cálculo y exposición de la ventana:

- se calcula como `expires_at = pedidos.created_at + duración_parametrizada`; **NO** se agrega una columna `expires_at` nueva (el valor se deriva del `created_at` ya existente);
- el backend y MariaDB (`NOW()`) son la **autoridad** del vencimiento: el frontend nunca decide;
- el frontend puede recibir `expires_at` como **cuenta regresiva informativa**; al vencer, el backend es quien impide continuar con ese `Pedido`;
- la ventana sigue corriendo mientras el usuario decide una corrección del carrito (sección 17.2).

**Implementado el 30/09/2026 (vencimiento general, sin proceso programado):** el vencimiento se aplica de forma **perezosa**, siempre comparando `created_at` contra `NOW()` de MariaDB (duración parametrizada `PedidoService::PRIMER_PERIODO_MINUTOS = 40`: valor operativo actual desde el 03/10/2026 — no definitivo —; era `10` a la implementación del 30/09/2026):

- `PedidoRepository::vencerPrimerPeriodoVencidos()` (`UPDATE` masivo sobre `PEND_VERIF`/`PEND_PAGO` con `created_at <= NOW() - INTERVAL 40 MINUTE`, según el parámetro del primer período) se ejecuta al calcular la disponibilidad (`PedidoService::disponibilidadDeCatalogo`, usado por `GET /api/productos` y por el `catalogo` de las correcciones), al crear un `Pedido` y dentro de las operaciones de `POST /api/pedidos/{id}/pago`;
- además, cada operación sobre un `Pedido` concreto (`cancelar`, `pago` y `reenvio` de verificación) lee el `Pedido` con `SELECT ... FOR UPDATE` y, si está activo y vencido, lo pasa a `NO_PAGADO` y responde `409 {"error":"pedido_vencido"}`: **el `Pedido` vencido no puede continuar**;
- confirmar la verificación de email también aplica el mismo criterio por `Pedido` (sección 15.2).

Si vence sin que se haya iniciado el pago:

```text
PEND_VERIF / PEND_PAGO → NO_PAGADO
```

- El `Pedido` deja de participar en la disponibilidad.
- Para volver a comprar se inicia un nuevo checkout y se crea un nuevo `Pedido`.
- Cerrar la pestaña o perder actividad no equivale a cancelar (simplemente puede vencer la ventana).
- El carrito se pierde con la pestaña (es frontend, `sessionStorage`; ver sección 16). **NO** existe `checkout_id`.
- La continuidad de una compra pendiente no depende del carrito sino de la **recuperación de pedidos pendientes** tras una nueva verificación exitosa de email (ver sección 18).

### 13.3. Segundo período del Pedido

Comienza cuando el cliente **inicia realmente el pago** (`PEND_PAGO → PROCESANDO_PAGO`). No comienza simplemente porque se verificó el email.

Una vez iniciado el pago:

- este período **no cambia automáticamente** el estado del `Pedido`;
- si el pago se aprueba y el stock se afecta → `PAGADO`;
- si el pago se rechaza → vuelve a `PEND_PAGO` (si quedan intentos y el primer período no venció);
- si se alcanza `PAGO_MAX_INTENTOS` → `NO_PAGADO`;
- si sigue indefinido → permanece en `PROCESANDO_PAGO` y se genera el caso de revisión `PAGO_PROCESANDO_EXCEDIDO` (`PENDIENTE`, sin duplicados).

Mientras el resultado siga indefinido, el `Pedido` permanece en `PROCESANDO_PAGO` y sus cantidades siguen afectando la disponibilidad.

**Si el segundo período vence sin resolución:** NO pasa a `NO_PAGADO`, NO se asume rechazo, permanece en `PROCESANDO_PAGO` y se genera el caso de revisión `PAGO_PROCESANDO_EXCEDIDO` (`PENDIENTE`, sin duplicados para el mismo episodio). **NO** se crea un estado `PAGO_EN_CONCILIACION` ni una entidad ni un estado nuevos. Si posteriormente llega un resultado definitivo, el caso se resuelve y continúa la lógica normal del `Pedido` (sección 12.8); la resolución administrativa de los casos sin resultado definitivo queda fuera del alcance del MVP.

Este período es un **timeout técnico de conciliación**, distinto del primer período: **NO** invalida el `Pedido` y **NO** se expone al cliente como cuenta regresiva — el frontend únicamente informa que se está confirmando el estado del pago, sin timer de vencimiento visible.

**Alcance en el MVP (05/10/2026):** con el simulador del MVP sólo son posibles resultados definitivos (`APROBADO`/`RECHAZADO`), por lo que el segundo período no se activa desde el flujo ejecutable del MVP: queda reservado para proveedores con resolución asincrónica (`MercadoPagoProvider` + webhook, POST-MVP) y para el caso — ya no reproduccible vía `simulacion` — de un error técnico del proveedor (ver decisión en sección 21). La confirmación de resultados `EN_PROCESO` **no** es un pendiente del MVP.

### 13.4. Conciliación

Configuración: `PAGO_MAX_ESPERA_CONCILIACION` (parámetro parametrizable; valor concreto no fijado).

- Si un pago permanece indefinido más allá del tiempo máximo parametrizado, se genera el caso de revisión `PAGO_PROCESANDO_EXCEDIDO` (sección 12.8).
- Si se obtiene `APROBADO` → se procesa la aprobación, se afecta el stock, `PROCESANDO_PAGO → PAGADO` y el caso pendiente pasa a `RESUELTO`.
- Si se obtiene `RECHAZADO` → se resuelve el caso pendiente y `PROCESANDO_PAGO → PEND_PAGO` si quedan intentos y el primer período no venció, o `PROCESANDO_PAGO → NO_PAGADO` si se alcanzó `PAGO_MAX_INTENTOS`.
- Si sigue indefinido: **no asumir `RECHAZADO`**, no pasar a `NO_PAGADO`, no liberar automáticamente stock, permanecer en `PROCESANDO_PAGO`.
- Si se alcanza `PAGO_MAX_ESPERA_CONCILIACION` sin definición definitiva:
  - el caso de revisión permanece en situación de **conciliación excepcional**;
  - no se convierte arbitrariamente en `RECHAZADO`;
  - no se libera stock simplemente porque no hubo respuesta;
  - la **resolución administrativa** queda **fuera del alcance del MVP**.

NO existe estado `PAGO_EN_CONCILIACION`.

La estrategia concreta de conciliación pertenece al `PaymentValidationService` y depende del proveedor. La conciliación masiva/contable y los mecanismos avanzados quedan para el futuro.

---

## 14. Reintentos de pago

`PAGO_MAX_INTENTOS` representa el máximo **total** de intentos, incluyendo el primero.

Para crear un nuevo intento deben cumplirse **todas** estas condiciones:

1. `cantidad_de_intentos < PAGO_MAX_INTENTOS`;
2. el pedido no tiene ningún `PaymentValidationResult` `APROBADO`;
3. el primer período del `Pedido` todavía no venció (el `Pedido` está en `PEND_PAGO`).

La comprobación y la creación del nuevo intento deben ser **seguras frente a concurrencia** (mecanismo a definir en el diseño técnico).

- Un pago `RECHAZADO` no crea automáticamente otro intento.
- El nuevo `Pago` se crea solamente cuando el usuario inicia efectivamente un nuevo intento.
- El `Pago` rechazado queda permanentemente registrado: conserva importe y resultado, no se modifica ni se reutiliza.
- Un `RECHAZADO` **no** revierte un `Pedido` que ya está `PAGADO`.
- Si se alcanza `PAGO_MAX_INTENTOS` y todos los intentos fueron rechazados:

```text
PROCESANDO_PAGO → NO_PAGADO
```

No se espera la ventana de conciliación para un rechazo definitivo.

### Diferencia entre RECHAZADO y estados indefinidos

```text
RECHAZADO
```

significa que el proveedor dio una resolución **definitiva**. El `Pedido` vuelve a `PEND_PAGO` si quedan intentos y el primer período no venció.

```text
PENDIENTE / EN_PROCESO / DESCONOCIDO
```

significan que todavía no existe una **definición segura**:

- `RECHAZADO` → no necesita esperar conciliación para saber que fue rechazado.
- `PENDIENTE` / `EN_PROCESO` / `DESCONOCIDO` → el `Pedido` permanece en `PROCESANDO_PAGO`, no se libera stock, no pasa a `NO_PAGADO`; si se supera el tiempo máximo parametrizado se genera el caso de revisión `PAGO_PROCESANDO_EXCEDIDO` (sección 12.8).
---

## 15. Verificación de email

En el MVP, **todo pedido requiere verificación de email**:

```text
Pedido
   ↓
verificación de email
   ↓
pago
```

La verificación es una **funcionalidad reutilizable**, desacoplada de los pedidos, mediante un **mecanismo genérico agnóstico de contexto**:

- **NO** existe campo de contexto (ni `contexto`, ni `tipo`, ni `uso`, ni `origen`, ni `pedido_id`) en la verificación ni en su tabla;
- el mecanismo no contiene lógica de negocio de ningún consumidor;
- consumidor del MVP: el e-commerce (la compra);
- consumidores futuros (POST-MVP): newsletter, registro de cuentas y recuperación de contraseña.

Responsabilidades **cerradas** del servicio genérico de verificación (`EmailVerificationService`; **implementado el 29/09/2026**):

- crea y gestiona los registros de verificación;
- genera los tokens **nuevos y aleatorios** (`bin2hex(random_bytes(32))`, 256 bits) y guarda **únicamente su hash SHA-256** (el esquema prevé `token_hash`; nunca el token en texto plano: el token en claro sólo llega al consumidor que envía el email);
- controla la **expiración** (`expires_at`, referencia: 7 días) y el **cooldown de reenvío** (referencia: 15 minutos, medido sobre `last_sent_at`), parametrizados y calculados en SQL (`NOW()`, `DATE_ADD`, `TIMESTAMPDIFF`) porque la timezone de MariaDB difiere de la de PHP;
- **rota el token en cada reenvío permitido**: cada envío genera un token nuevo y el anterior queda inválido (decisión adoptada en la implementación: sin reutilizar el token vigente; el antiguo `token_hash` se sobrescribe, por lo que un token viejo ya no confirma) — esta lógica es **interna** al servicio: el consumidor hace **una sola solicitud de verificación** y **no elige la operación**;
- valida los tokens;
- registra la finalización exitosa (`verified_at`) pero **no** cambia el estado del `Pedido`;
- devuelve al consumidor el resultado/estado de la verificación.

**Valores de referencia funcionales** (tomados de la lógica auditada del backend histórico; se cargan como parametrización):

```text
expiración del token   → 7 días
cooldown de reenvío    → 15 minutos
```

Reglas de persistencia **cerradas**:

- los registros completados **NO se eliminan** (se conservan como evidencia); el token queda inutilizable tras la finalización;
- `email` **NO es UNIQUE**: cada `Pedido` que requiere verificación crea **su propio registro**, incluso con un email repetido;
- la relación es **unidireccional**: `pedidos.email_verificacion_id → emails_verificaciones.id` (el `Pedido` conoce su verificación; la verificación no conoce al `Pedido`);
- no existe columna de estado: el estado se **deriva de los datos**.

Separación de responsabilidades **cerrada**:

- el mecanismo **NO envía emails** ni conoce su tipo, contenido o plantilla: **cada consumidor envía su propio email** (asunto, plantilla, URL). En el MVP, `PedidoService` invoca el mecanismo y envía el email de verificación del checkout; los consumidores futuros harán lo propio con sus propios emails;
- la entrega puede diferir entre **desarrollo y producción** (en desarrollo el enlace puede entregarse por otro canal; en producción se envía por correo), manteniendo la misma lógica funcional: esa diferencia la maneja el consumidor que envía el email;
- **canales de entrega por entorno (decisión cerrada el 02/10/2026):** en **DEV** la entrega se realiza con el **canal `log`** — token real, sólo su hash persistido, enlace real de verificación registrado en log para recuperarlo en las pruebas, entrega considerada **exitosa sin transporte SMTP** y por lo tanto con registro de `last_sent_at` —; en el **MVP/producción** rige el **canal real de envío** (SMTP, proveedor u otro canal apropiado; mecanismo a definir e implementar al preparar el entorno final). La elección entre canal de desarrollo y canal real se resuelve **mediante configuración por entorno** (sección 5) y **no** modificando la lógica de negocio de verificación ni las responsabilidades del mecanismo (**implementado el 03/10/2026** para el canal `log`: `src/config/email.php` + composición en `public/index.php`; ver sección 21). El canal `log` es **exclusivamente infraestructura de desarrollo** y no reemplaza el envío real;
- **flujo de confirmación:** el endpoint del consumidor recibe el enlace/token → invoca al mecanismo → el mecanismo valida → si es correcto, **el propio mecanismo registra `verified_at`** → devuelve el resultado → el consumidor decide la acción de negocio (p. ej. `PEND_VERIF → PEND_PAGO`; para la verificación de email, `PedidoService` procesa **todos** los `Pedido` asociados, ver sección 15.2). El consumidor **nunca** escribe `verified_at`. **No** se requiere callback ni notificación por eventos;
- los cuatro concerns quedan separados: lógica genérica de verificación | lógica del `Pedido` | envío de emails | modelo histórico del newsletter.

La verificación de email no forma parte de un sistema de autenticación de cuentas.

### Lógica existente de referencia

En el backend histórico (`D:\Incancelables Backend`, Google Apps Script) existe una lógica funcional de verificación de email, desarrollada originalmente para el newsletter, que contempla el proceso completo:

- ingreso del email;
- generación del token;
- envío del email;
- validación;
- expiración;
- token inválido / no validado;
- reenvío;
- situaciones posteriores a la validación.

Esa lógica fue **auditada** y queda como **referencia conceptual**: el e-commerce debe **reutilizar conceptualmente esa misma lógica**, implementándola en PHP/MariaDB en lugar de Apps Script. **No se migra su modelo de datos** (tablas `Pendientes`, `Comunidad`, `TOKEN_BAJA`, datos de campañas, etc.).

**No inventar una segunda lógica de verificación diferente.**

### 15.1. Identidad del comprador

Introducir el email **NO** significa que el comprador esté identificado.

- `PEND_VERIF`: `Pedido` creado, email ingresado, email todavía no verificado → comprador **NO** identificado.
- `PEND_PAGO`: email verificado → comprador **identificado** mediante ese email.

### 15.2. Verificación única por proceso de identidad

- La verificación de email se realiza **una vez por proceso de identidad**. Si ya fue verificada, **NO se repite** al crear un nuevo `Pedido` producto de una corrección ni en ninguna nueva validación.
- Una misma verificación puede estar **asociada a varios `Pedido`** (la FK `pedidos.email_verificacion_id → emails_verificaciones.id` no es única: la asocian la sustitución por corrección y la recuperación). Al confirmarla, el consumidor (`PedidoService`) procesa **todos** los `Pedido` asociados: los que están en `PEND_VERIF` **con primer período vigente** pasan a `PEND_PAGO`; los que están en `PEND_VERIF` **vencidos** pasan a `NO_PAGADO`; los demás estados (`PEND_PAGO`, `PROCESANDO_PAGO`, `PAGADO`, `NO_PAGADO`) **no se modifican**. La vigencia se calcula con `created_at` + primer período (valor operativo actual 40 minutos, ver sección 13.2) usando `NOW()` de MariaDB como autoridad.
- En cambio, la **validación comercial** (existencia, producto activo, cantidades, stock, precio, disponibilidad y reglas comerciales) **SIEMPRE** se realiza nuevamente cuando corresponde validar un carrito o un `Pedido` (ver sección 17); nunca se da por válida solamente porque el carrito fue corregido por el backend.
- El reenvío se rige por un **cooldown parametrizado** sobre `last_sent_at`: cumplido el cooldown puede solicitarse el reenvío, generando un token nuevo (rotación; el token anterior queda inválido).
- El mecanismo es genérico: **no** distingue consumidores dentro de la verificación (sin campo de contexto); la lógica propia de cada consumidor (compra, y futuros: newsletter, registro, recuperación de contraseña) vive fuera del mecanismo.
- En desarrollo y producción pueden existir diferencias de entrega del email, manteniendo la misma lógica funcional (**decisión de canales por entorno del 02/10/2026:** canal `log` en DEV y canal real de envío para el MVP/producción, distinguidos por configuración; ver secciones 5, 6 y más abajo).

El mecanismo genérico y la estructura de `emails_verificaciones` están **cerrados** (secciones 15 y 15.3). La implementación concreta (clases PHP, endpoints con sus códigos/parámetros y detalle HTTP) está realizada y verificada el 29/09/2026 (secciones 15.4, 19 y 21); sin migraciones versionadas (los cambios se aplicaron directamente sobre MariaDB). Queda pendiente el canal real de envío del email y la URL/asunto/plantilla definitivos (sección 20); el canal de email `log` de DEV (decisión del 02/10/2026) está **implementado y verificado el 03/10/2026** (sección 21).

### 15.3. Estructura física de `emails_verificaciones` (cerrada)

```text
id             INT UNSIGNED  NOT NULL AUTO_INCREMENT
email          VARCHAR(254)  NOT NULL
token_hash     VARCHAR(255)  NOT NULL
expires_at     DATETIME      NOT NULL
verified_at    DATETIME      NULL DEFAULT NULL
created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
last_sent_at   DATETIME      NULL DEFAULT NULL
```

- PK: `id`.
- Índice **no único**: `idx_emails_verificaciones_email (email)`.
- **NO** existe `estado_id`: el estado se **deriva de los datos** (`verified_at` NULL/no NULL y `expires_at` respecto al momento actual).
- **NO** existen `pedido_id`, `contexto`, `tipo`, `uso`, `origen` ni ningún campo equivalente.
- `email` **NO es UNIQUE**: cada `Pedido` crea su **propio** registro de verificación aunque se repita el email.
- FK unidireccional: `pedidos.email_verificacion_id → emails_verificaciones.id`.
- Los registros completados **no se eliminan**.
- `last_sent_at` (columna agregada el 29/09/2026, única ampliación de esquema de este bloque): sostiene el cooldown de reenvío; se escribe con `NOW()` **sólo cuando el envío del email tiene éxito** (si el envío falla, permanece como estaba y no se consume cooldown).
- La estructura, el índice y la FK **ya existen en la base de datos actual** (la FK ya figura en el modelo existente, sección 9).

### 15.4. Endpoints y contrato HTTP (implementados y verificados el 29/09/2026; contrato de `validar` actualizado el 30/09/2026: `pedido` → `pedidos[]`)

Rutas registradas en `routes/api.php`, atendidas por `EmailVerificationController` (validación estructural del request; sin lógica de negocio ni SQL) sobre `EmailVerificationService`. Consumidor del flujo: `PedidoService`.

**Solicitud (no es un endpoint):** el inicio de la verificación está integrado en `POST /api/pedidos` y se ejecuta **después** del `COMMIT` que crea el `Pedido` (opción A). Un fallo de envío del email **no** revierte ni modifica el `Pedido`: responde igual `201` y el registro queda en `PEND_VERIF` con `last_sent_at` sin actualizar. **No existe** `POST /api/email-verificaciones`.

**`POST /api/email-verificaciones/validar`** — cuerpo JSON `{"token": "..."}`.

| Código | Cuerpo | Condición |
|---|---|---|
| `200` | `{"resultado":"confirmado","email_verificacion_id":N,"pedidos":[{...},...]}` | hash SHA-256 coincide y `expires_at > NOW()` (o el registro ya estaba verificado: la confirmación es **idempotente**); el servicio registra `verified_at`; el consumidor (`PedidoService`) procesa **todos** los `Pedido` asociados (sección 15.2): `PEND_VERIF` vigentes → `PEND_PAGO`, `PEND_VERIF` vencidos → `NO_PAGADO`, demás estados intactos. El campo `pedidos[]` contiene **sólo los `Pedido` vigentes habilitados** por esta confirmación (con `id` y `estado`); `[]` si no hay ninguno. La verificación puede quedar confirmada aunque `pedidos` esté vacío. **Reemplaza al campo `pedido` singular** (eliminado) |
| `400` | `{"error":"token_invalido"}` | token inexistente o hash no coincide |
| `400` | `{"error":"token_expirado"}` | hash coincide pero `expires_at <= NOW()` |
| `400` | `{"error":"..."}` | `token` faltante o JSON inválido |
| `404` / `405` | — | ruta inexistente / método incorrecto (`405` con header `Allow`) |

**`POST /api/email-verificaciones/reenvio`** — cuerpo JSON `{"pedido_id": N}`; sólo tiene sentido para un `Pedido` en `PEND_VERIF`.

| Código | Cuerpo | Condición |
|---|---|---|
| `200` | `{"resultado":"reenviado"}` | cooldown cumplido: se genera un token **nuevo** (el anterior queda inválido), se envía el email y `last_sent_at = NOW()` sólo si la entrega fue exitosa **según el canal configurado** |
| `429` | `{"error":"cooldown_activo","segundos_restantes":N}` | `TIMESTAMPDIFF(SECOND, last_sent_at, NOW()) < 900` (15 min) |
| `409` | `{"error":"verificacion_ya_verificada"}` | `verified_at` no NULL |
| `409` | `{"error":"pedido_no_pendiente_de_verificacion"}` | el `Pedido` no está en `PEND_VERIF` |
| `409` | `{"error":"pedido_vencido","pedido":{...NO_PAGADO}}` | el `Pedido` estaba activo y venció el primer período (se pasa a `NO_PAGADO`; **30/09/2026**) |
| `404` | `{"error":"pedido_inexistente"}` | `pedido_id` inexistente |
| `502` | `{"error":"error_envio_email"}` | la entrega falló según el canal configurado (el canal en uso devolvió `false`); no se actualiza `last_sent_at`. Con el canal `log` de DEV/MVP (único canal implementado desde el 03/10/2026, que devuelve `true`) este caso no se produce |
| `400` | `{"error":"..."}` | `pedido_id` faltante/no numérico o JSON inválido |
| `404` / `405` | — | ruta inexistente / método incorrecto |

**`POST /api/email-verificaciones/pedidos`** *(implementado y verificado el 05/10/2026)* — consulta **solo lectura** por token: devuelve los `Pedido` asociados a la verificación con sus `PedidoItem`, para que la tienda continúe la compra sin depender de `sessionStorage` (decisión en la sección 21). Cuerpo JSON `{"token": "..."}` (el token va en el **body**, nunca en la URL; no existe `GET /api/pedidos/{id}` para evitar exponer `Pedido` por ids enumerables).

| Código | Cuerpo | Condición |
|---|---|---|
| `200` | `{"resultado":"ok","pedidos":[{"id":N,"estado":"...","items":[{"producto_id":N,"cantidad":N,"precio_unitario":N},...]},...]}` | hash SHA-256 coincide, `expires_at > NOW()` y `verified_at` NO NULL. Devuelve **todos** los `Pedido` asociados a la verificación (no sólo los habilitados por la confirmación), únicamente con `id`, `estado` e `items`; los items se obtienen con `PedidoRepository::buscarItemsPorPedido()` (sin `PedidoItemRepository`) |
| `400` | `{"error":"token_invalido"}` | token inexistente o hash no coincide |
| `400` | `{"error":"token_expirado"}` | hash coincide pero `expires_at <= NOW()` |
| `409` | `{"error":"verificacion_pendiente"}` | hash coincide y no expiró, pero `verified_at` es NULL (la verificación todavía no se realizó) |
| `400` | `{"error":"Falta el campo token"}` | `token` faltante en el body |
| `400` | `{"error":"token inválido"}` | `token` no string, vacío o con más de 255 caracteres |
| `400` | `{"error":"Cuerpo JSON inválido"}` | body no JSON |
| `404` / `405` | — | ruta inexistente / método incorrecto |

Notas de implementación del endpoint de consulta:

- es **estrictamente de solo lectura**: **no** invoca `EmailVerificationService::validar()` (evita su efecto secundario `marcarVerificada`), no escribe `verified_at`, no transiciona estados y no modifica la base de datos; reutiliza `EmailVerificationRepository::buscarPorTokenHash()` para resolver el token, y `PedidoRepository::buscarTodosPorEmailVerificacion()` + `buscarItemsPorPedido()` para armar la respuesta;
- la autorización es el propio token (256 bits aleatorios, sólo hash SHA-256 persistido): el alcance de la consulta lo define el token, no un id enviado por el cliente — la clase de vulnerabilidades IDOR sobre `Pedido` queda fuera de puerta;
- `EmailVerificationController::pedidos()` replica la estructura de `validar()` (validación de forma del token en el controlador, resultado/errores resueltos en `PedidoService::obtenerPedidosPorToken()`); errores `400 token_invalido` / `400 token_expirado` / `409 verificacion_pendiente` con el mismo estilo de envelope global.

Notas de implementación:

- el envío está en `PedidoService::enviarEmailVerificacion` (método privado del consumidor): asunto "Confirmá tu compra en Incancelables", cuerpo de texto plano y URL de destino tomada de la configuración — clave `url_verificacion_checkout` de `src/config/frontend.php`, validada e inyectada como `string` en el constructor de `PedidoService` por el composition root `public/index.php` (**desde el 05/10/2026**): la URL deja de estar hardcodeada en `PedidoService` y el destino puede cambiarse al desplegar sin modificar su lógica. El enlace se arma en el propio método concatenando esa URL con `?flow=checkout&token=` + `urlencode($token)`; el `flow=checkout`, el token, el asunto y el cuerpo siguen en el código del consumidor. **Transporte (implementado el 03/10/2026):** `PedidoService` ya no invoca `mail()`: prepara destinatario/asunto/cuerpo y los delega en el contrato genérico `EmailSender::enviar(para, asunto, cuerpo): bool` (`src/mail/EmailSender.php`); en DEV/MVP la implementación es `LogEmailSender` (canal `log`, seleccionado por `src/config/email.php`), que registra el email compuesto — incluido el enlace con el token — en el `error_log` de Apache (`[incancelables] email log — ...` → `C:\xampp\apache\logs\error.log`) y devuelve `true`, por lo que **`last_sent_at` sí se registra** y `reenvio` opera con su cooldown (`200 reenviado` / `429 cooldown_activo`). No hay transporte de correo (sin MTA, SMTP, APIs ni credenciales) y **el canal real de envío sigue pendiente** como requisito para dar por terminado el MVP (sección 20);
- `last_sent_at` representa una **entrega exitosa según el canal configurado**: sólo se escribe cuando el canal en uso (desde el 03/10/2026: canal `log` en DEV/MVP, que devuelve `true`; en MVP/producción: canal real cuando se implemente) confirma la entrega, y sobre ese valor se mide el cooldown de reenvío;
- las fechas se calculan en SQL (`NOW()`, `DATE_ADD(NOW(), INTERVAL 7 DAY)`, `TIMESTAMPDIFF`) por la diferencia de timezone entre MariaDB y PHP;
- son operaciones puntuales (insert/update de una fila) que no requieren transacción compuesta; la asociación `pedidos.email_verificacion_id` ocurre una vez confirmada la transacción del `Pedido`;
- el token en claro nunca se persiste (sólo `token_hash`); no hay secretos ni HMAC en el mecanismo.

---

## 16. Carrito

El carrito es exclusivamente **frontend** y funciona como una lista de intención de compra.

- Vive en `sessionStorage`: sobrevive a recargas en la misma sesión/pestaña y puede perderse al cerrar la pestaña o el navegador.
- El backend **NO persiste** el carrito: no existe endpoint ni operación de persistencia de carrito, no existe tabla de carritos (ver sección 9), y quedan fuera del MVP el carrito persistente entre dispositivos y los múltiples carritos recuperables.
- No existe `checkout_id`: el checkout es un **único proceso**, aunque durante el mismo puedan producirse correcciones y crearse nuevos `Pedido`.
- No es una transacción y no representa un `Pedido`.
- El usuario puede modificar libremente el carrito.
- El carrito **NO reserva ni bloquea stock**: la disponibilidad se ve afectada recién cuando se crea el `Pedido` (ver sección 10).
- Al iniciar checkout, el frontend envía el carrito al backend para su validación comercial (ver sección 17); el mismo envío ocurre al iniciar el pago, con `proveedor_id` e `items[]`.
- El carrito es **siempre una propuesta no confiable** del frontend: se valida contra el backend en cada momento que corresponde (ver sección 17).
- Una vez creado un `Pedido`, el proceso de compra continúa independientemente del estado posterior del carrito: el carrito puede desaparecer, vaciarse o cambiar y el `Pedido` existente continúa su propio ciclo de vida. **Decisión del 05/10/2026 (sección 21):** una vez creado el `Pedido`, **`Pedido` + `PedidoItem` en el backend son la fuente de verdad para continuar la compra**; `sessionStorage` pertenece exclusivamente a la etapa previa a la creación del `Pedido`. Tras verificar el email, la tienda recupera el `Pedido` y sus items desde `POST /api/email-verificaciones/pedidos` (sección 15.4).
- La recuperación de compras pendientes no se basa en el carrito sino en la verificación de email (ver sección 18).

> No existe `CarritoService` de persistencia ni repositorio de carritos: no hay estado de carrito del lado del servidor.

---

## 17. Validación comercial y corrección del carrito

**Regla general:** todo carrito recibido desde el frontend es **no confiable** y siempre se valida contra el estado actual del backend. Aplica a (1) la creación del `Pedido`, (2) la corrección del carrito y (3) el inicio del pago, reutilizando siempre la misma lógica de validación comercial. Nunca se reutiliza una validación comercial anterior como garantía de validez actual.

### 17.1. Validación comercial al iniciar checkout

1. El frontend envía el carrito al backend.
2. El backend realiza la **validación comercial**, que incluye como mínimo:
   - existencia del producto;
   - producto activo / disponible para venta;
   - cantidades válidas;
   - disponibilidad actual;
   - precio actual;
   - demás reglas comerciales vigentes.
3. Si todo es válido: se crea el `Pedido`, se crean sus `PedidoItem` y el `Pedido` comienza en `PEND_VERIF` (antes de la verificación de email y antes del pago).

**Implementado en `POST /api/pedidos` (29/09/2026).** Este endpoint corresponde al **inicio del checkout** y ejecuta la **primera** validación comercial; en ese momento **todavía no existe un `Pedido` previo** (la corrección, en este primer momento, sólo devuelve la propuesta corregida; no hay `Pedido` que pasar a `NO_PAGADO`).

- **Request (JSON):** `email`, `nombre`, `apellido` (obligatorios) y `items[]`; cada item requiere obligatoriamente `producto_id`, `cantidad` y **`precio_unitario`**. `precio_unitario` **NO** es opcional: si falta → `400` y no se crea ningún `Pedido` ni `PedidoItem` (idem si no es numérico o si `producto_id`/`cantidad` son inválidos, o si falta un campo obligatorio del request o el JSON es inválido).
- **Precios:** `pedidos_items.precio_unitario` se persiste **siempre** con el valor leído de `productos.precio` dentro de la transacción; el `precio_unitario` del request sólo se usa para detectar `precio_cambiado`.
- **Concurrencia (transacción de MariaDB):** `PedidoService` inicia la transacción, `ProductoRepository` bloquea las filas de `productos` del carrito con `SELECT ... FOR UPDATE` (un `producto_id` por consulta, en **orden ascendente** para garantizar un orden de adquisición determinista), y **recién dentro** de la transacción se recalculan las cantidades comprometidas y la disponibilidad (`PedidoRepository.cantidadesComprometidasPorProducto` con `PEND_VERIF`, `PEND_PAGO`, `PROCESANDO_PAGO`) y se ejecutan todas las validaciones. Ante cualquier discrepancia: `ROLLBACK` (sin escrituras) y respuesta de corrección. Si todo es válido: se insertan `Pedido` y `PedidoItem` y se hace `COMMIT`. Ante cualquier excepción: `ROLLBACK` y error 500. Verificado: dos checkouts simultáneos por el stock completo generan un solo `201` y una corrección, sin deadlocks.
- **Respuestas:** `201` `{"resultado":"creado","pedido":{"id":N,"estado":"PEND_VERIF"}}`; `200` `{"resultado":"correccion","carrito_corregido":[...],"disponibilidad":[...],"motivos":[...]}` (motivos: `producto_inexistente`, `producto_no_disponible`, `precio_cambiado`, `disponibilidad_insuficiente`); `400` `{"error":"..."}`; `404`; `405` con `Allow`.

La misma validación comercial completa se realiza también antes de iniciar el pago (tercer momento; ver sección 10.1). **Implementado el 30/09/2026** en `POST /api/pedidos/{id}/pago` con `{"accion":"iniciar_pago"}`: el backend valida **el carrito recibido en el request** (obligatorio en `iniciar_pago`; ver sección 17.2) contra el persistido en el `Pedido` (`pedidos_items`, inmutable — toda edición pasa por `aceptar_correccion`); si difieren (sólo producto y cantidades), se responde `correccion` con motivo `carrito_desactualizado` y el carrito corregido es el persistido. Si coinciden, se ejecuta la **tercera validación comercial completa** sobre esos items con bloqueo `FOR UPDATE` de `productos`, excluyendo del cálculo de disponibilidad el compromiso del propio `Pedido`. Las respuestas y el flujo completo están en la sección 17.2.

### 17.2. Corrección del carrito

Si la validación comercial encuentra diferencias, no se procede con la operación (en la primera comprobación **NO** se crea ningún `Pedido`; en la tercera comprobación **NO** se inicia el pago y el `Pedido` existente no se modifica). El backend devuelve:

- el carrito corregido propuesto;
- la información actualizada de disponibilidad (y, cuando ya existe un `Pedido` creado, los **precios y la disponibilidad actualizados del catálogo completo activo**);
- el motivo de cada corrección (por ejemplo: cambió el precio; quedan menos unidades; producto dejó de estar disponible; producto dejó de existir; u otra regla comercial).

Reglas:

- Si ya existe un `Pedido` creado en el proceso, las diferencias **NO** lo invalidan: conserva su estado (`PEND_VERIF` o `PEND_PAGO`), **sigue afectando la disponibilidad** mientras el usuario decide, y sus `PedidoItem` **NO** se modifican (el `Pedido` es inmutable; ver sección 9). Pasa a `NO_PAGADO` sólo si el usuario **cancela** (cancelación voluntaria, ver abajo) o **dentro de la misma operación/transacción en que acepta** la corrección y se crea el reemplazante.
- El frontend actualiza la información visible de disponibilidad y de precios.
- El usuario puede editar libremente el carrito (reducir cantidades, eliminar productos, agregar productos que no estaban originalmente, modificar cantidades), aceptar la corrección o **cancelar** mediante `POST /api/pedidos/{id}/cancelar` (cancelación voluntaria del checkout, ver sección 19.4), que también sirve para rechazar la corrección.
- No existe lógica que distinga el carrito "original" del "corregido": **cada carrito recibido representa la intención actual del usuario** y se valida de la misma manera.
- Si lo acepta: se realiza nuevamente la **validación comercial completa** y, si resulta válida, **en la misma operación/transacción** el `Pedido` anterior pasa a `NO_PAGADO` y se crea un **nuevo** `Pedido` con nuevos `PedidoItem`; se reutiliza la verificación de email ya realizada. Si vuelve a haber diferencias, se devuelve una nueva corrección **sin sustituir** el `Pedido` (no hay sustitución parcial).
- El nuevo `Pedido` **nace con el mismo estado que tenía el original antes de la corrección**: si el original estaba en `PEND_VERIF`, el nuevo nace en `PEND_VERIF`; si estaba en `PEND_PAGO`, el nuevo nace en `PEND_PAGO` (la corrección no fuerza repetir una verificación de email ya cumplida). Un reemplazante en `PEND_PAGO` igualmente debe volver a pasar por la tercera validación comercial (sección 10.1) antes de iniciar realmente el pago.
- La nueva validación **SIEMPRE** vuelve a comprobar stock, precio, disponibilidad y reglas comerciales. NO se asume que el carrito corregido está validado simplemente porque fue generado por el backend.
- La única validación que NO se repite es la verificación de email si el comprador ya fue verificado (ver sección 15.2).
- El vencimiento del primer período (sección 13.2) sigue corriendo mientras el usuario decide: si vence, el `Pedido` pasa a `NO_PAGADO` por vencimiento y ya no puede continuar.
- El ciclo puede repetirse hasta que la operación sea válida o el usuario la abandone o la cancele:

```text
validar → corregir → usuario modifica/acepta → volver a validar → nuevo Pedido → continuar
                                                            └ o cancela → NO_PAGADO (cancelación voluntaria)
```

**Implementado el 30/09/2026 — contrato HTTP de `POST /api/pedidos/{id}/pago`:**

- **Request (JSON):** `accion` obligatorio, con valores `"iniciar_pago"` o `"aceptar_correccion"`; para ambos es además obligatorio `items[]` (mismas reglas de estructura que `POST /api/pedidos`: `producto_id`, `cantidad`, `precio_unitario` — ver sección 17.1). Para `"iniciar_pago"` son además obligatorios **`proveedor_id`** (id numérico o `codigo`, p. ej. `"DUMMY"`) y es opcional **`simulacion`** (`"APROBADO"` por defecto | `"RECHAZADO"` — desde el 05/10/2026 el conjunto admitido son sólo esos dos valores; `"EN_PROCESO"` ya **no** es aceptado). `400` ante JSON inválido, `accion` faltante/inválida, `items` faltantes o carrito vacío (`{"error":"Falta el campo accion"}`, `{"error":"accion inválida"}`, `{"error":"Falta el campo items"}`, `{"error":"El carrito está vacío"}`), `proveedor_id` faltante/inválido (`400 {"error":"Falta el campo proveedor_id"}` / `400 {"error":"proveedor_id inválido"}`), `simulacion` fuera del conjunto — incluido `"EN_PROCESO"` — (`400 {"error":"simulacion inválida"}`) y `400 {"error":"pedido_id inválido"}` cuando el segmento `{id}` no es numérico.
- **Comunes:** `404 {"error":"pedido_inexistente"}`; `409 {"error":"pedido_vencido","pedido":{"id":N,"estado":"NO_PAGADO"}}` (estaba activo y venció: se pasa a `NO_PAGADO` y no puede continuar); `409 {"error":"pedido_no_activo","pedido":{...}}` (estado fuera de `PEND_VERIF`/`PEND_PAGO`); `404`/`405` según ruta/método.
- **`accion: "iniciar_pago"`** — **flujo en dos fases (dos transacciones de MariaDB):**
  - **Fase A** (transacción de validación): requiere `PEND_PAGO` (`409 {"error":"pedido_no_pendiente_de_pago","pedido":{...}}` si está en `PEND_VERIF`); resuelve el proveedor contra el catálogo (`400 {"error":"proveedor_faltante"}`, `400 {"error":"proveedor_inexistente"}`, `400 {"error":"proveedor_no_soportado"}` si el código no es `DUMMY` en el MVP); verifica que no exista ya un `PaymentValidationResult` `APROBADO` del `Pedido` (`409 {"error":"pago_ya_aprobado","pedido":{...}}`); compara `items[]` con los persistidos (sólo producto y cantidades) → si difieren: `200 correccion` con motivo `carrito_desactualizado` y `carrito_corregido` = items persistidos (**sin** crear `Pago`); si coinciden: ejecuta la **tercera validación comercial** (`200 correccion` ante cualquier motivo, con `carrito_corregido`, `disponibilidad`, `motivos` y `catalogo` activo completo de 6 productos, el `Pedido` intacto y sin `Pago`); y sólo si todo es válido y `cantidad_de_intentos < 3` (`409 {"error":"maximo_intentos_alcanzado","pedido":{...},"intentos":N,"maximo_intentos":3}`), crea el `Pago` (importe = total calculado del `Pedido`) y su único `PaymentValidationResult` en `EN_PROCESO` y pasa el `Pedido` a `PROCESANDO_PAGO` **antes del COMMIT** (la transición a `PROCESANDO_PAGO` ocurre sólo cuando realmente se inicia el intento). Si el proveedor falla entre fases, cualquier `200`/`4xx` de esta fase no deja el `Pedido` en `PROCESANDO_PAGO`.
  - **Fase B** (nueva transacción, `Pedido` bloqueado con `FOR UPDATE`; si su estado ya no es `PROCESANDO_PAGO` → `409 {"error":"pedido_no_procesando"}`): `PaymentValidationService::procesarIntento()` consulta/normaliza contra el proveedor y persiste el resultado sobre el **mismo** `PaymentValidationResult` (1:1; jamás se crea un segundo resultado; un resultado definitivo no se sobrescribe). Luego `PedidoService` aplica:
    - `simulacion=APROBADO` (o ausente) → `200 {"resultado":"aprobado","pedido":{"id":N,"estado":"PAGADO"},"pago":{"id":P,"importe":X},"validacion":{"estado":"APROBADO","referencia_proveedor":"DUMMY-P"}}` con descuento de stock en la misma transacción; si el importe informado no coincide con el total calculado → `200 aprobado` **sin** descuento y **sin** `PAGADO`, con `pedido` en `PROCESANDO_PAGO` y `"caso_revision":{"tipo":"PAGADO_STOCK_NO_AFECTADO","estado":"PENDIENTE"}`; si el stock no puede afectarse → `200 aprobado` con `pedido.estado = "PAGADO_STOCK_NO_AFECTADO"` y el mismo caso;
    - `simulacion=RECHAZADO` → `200 {"resultado":"rechazado","pedido":{"id":N,"estado":"PEND_PAGO"|"NO_PAGADO"},"intentos":N,"maximo_intentos":3,...}` (resuelve antes un caso `PAGO_PROCESANDO_EXCEDIDO` pendiente; `PEND_PAGO` si quedan intentos y el primer período sigue vigente, `NO_PAGADO` si se alcanzó `PAGO_MAX_INTENTOS` o el período venció); no se crea otro intento automáticamente;
    - `simulacion=EN_PROCESO` → **`400 {"error":"simulacion inválida"}`** (desde el 05/10/2026): el valor se rechaza en la validación del endpoint, **antes** de crear `Pago` o de tocar el `Pedido`, que permanece en `PEND_PAGO` sin ningún efecto secundario (ver sección 21). Un error técnico del proveedor responde todavía `200 {"resultado":"en_proceso","pedido":{"id":N,"estado":"PROCESANDO_PAGO"},...}` (log + `PaymentValidationResult` no definitivo), sin convertirse en `RECHAZADO`: es el comportamiento documentado para proveedores con resolución asincrónica (POST-MVP) y es **inalcanzable** con el proveedor simulado `DUMMY` y los valores de `simulacion` admitidos.
  - **Sin** segundo período automatizado, **sin** endpoint de confirmación de `EN_PROCESO` y **sin** `PAGO_PROCESANDO_EXCEDIDO` generado por tiempo en el MVP (ver secciones 13.3 y 20). Además, desde el 05/10/2026 el simulador del MVP **no admite** `simulacion=EN_PROCESO`, de modo que el flujo ejecutable no puede generar un resultado `EN_PROCESO` ni dejar un `Pedido` en `PROCESANDO_PAGO` sin mecanismo de resolución; `PROCESANDO_PAGO` y `EN_PROCESO` quedan reservados para la evolución futura (webhook, pagos asincrónicos y conciliación, todos POST-MVP — ver decisión en sección 21).
- **`accion: "aceptar_correccion"`:** requiere `Pedido` activo (`PEND_VERIF` o `PEND_PAGO`). Vuelve a validar **completamente** el carrito final enviado:
  - con diferencias → `200` con el mismo cuerpo de `correccion` **sin sustituir** (el `Pedido` original queda intacto; internamente la transacción se revierte);
  - válida → `200 {"resultado":"reemplazado","pedido_anterior":{"id":N,"estado":"NO_PAGADO"},"pedido":{"id":M,"estado":"PEND_VERIF"|"PEND_PAGO"}}`: **en una misma transacción** el `Pedido` anterior pasa a `NO_PAGADO` (items intactos) y se crea el nuevo con los `items[]` validados, **heredando el estado anterior** y el `email_verificacion_id`; si hereda `PEND_PAGO` **NO** se inicia ningún pago automáticamente: deberá volver a pasar por `"iniciar_pago"`.
- **Reemplazo autorizado por token (nuevo endpoint, implementado el 06/10/2026): `POST /api/email-verificaciones/pedidos/reemplazo`.** Misma operación de sustitución, pero **sin conocer el id del `Pedido`**: la autorización es el token de verificación. Permite que el frontend, recuperado el proceso por token (sección 15.4), cambie el carrito del `Pedido` activo aunque no tenga un `pedido_id` a la vista (evita IDOR y evita depender del `sessionStorage`).
  - **Request (JSON):** `token` obligatorio (string, mismo formato que el resto de endpoints de verificación) e `items[]` obligatorio con las **mismas reglas** que `POST /api/pedidos` (sección 17.1).
  - **Errores de validación:** `400 {"error":"Falta el campo token"}`, `400 {"error":"token inválido"}` (no string, vacío o >255), `400 {"error":"Falta el campo items"}`, `400 {"error":"El carrito está vacío"}` y los `400` de estructura de ítem (`producto_id`/`cantidad`/`precio_unitario`); `400` ante JSON inválido.
  - **Errores de dominio:** `400 {"error":"token_invalido"}` (token inexistente), `400 {"error":"token_expirado"}`, `409 {"error":"verificacion_pendiente"}` (el email aún no fue verificado: el reemplazo sólo existe para un proceso ya verificado), `409 {"error":"pedido_no_activo"}` (ningún `Pedido` activo de esa verificación: ya reemplazado/vencido/cancelado/pagado) y `409 {"error":"varios_pedidos_activos"}` (más de un `Pedido` activo: se niega el reemplazo para no sustituir el equivocado).
  - **Éxito:** idéntico a `aceptar_correccion` — `200 {"resultado":"reemplazado","pedido_anterior":{"id":N,"estado":"NO_PAGADO"},"pedido":{"id":M,"estado":"PEND_VERIF"|"PEND_PAGO"}}` o `200 correccion` **sin sustituir** ante diferencias de precio/stock/disponibilidad (el `Pedido` intacto, con `carrito_corregido`, `disponibilidad`, `motivos` y `catalogo`).
  - **Implementación:** `EmailVerificationController::reemplazar()` valida el body (helper compartido `ItemsRequestValidator`, usado también por `PedidoController::validarItemsCuerpo()`) y delega en `PedidoService::reemplazarPedidoPorToken()`, que resuelve la verificación por token (`EmailVerificationService::consultarPorToken()`, ya existente), filtra los `Pedido` activos y llama a **la misma** `procesarPago(..., 'aceptar_correccion', ...)` del endpoint de pago: **cero lógica de negocio duplicada**, misma transacción y misma revalidación completa.
  - **Garantías:** no se crea otro registro en `emails_verificaciones` (la verificación y su `verified_at` quedan intactos), **no se envía un email nuevo**, el `email_verificacion_id` se hereda al nuevo `Pedido` y **no se inicia ningún pago**. Un reintento secuencial mientras el reemplazo anterior sigue activo sustituye de nuevo (`200 reemplazado`, `B→C`), sin doble compromiso de disponibilidad; el `409 pedido_no_activo` corresponde al caso sin ningún `Pedido` activo (o a la carrera detectada con `FOR UPDATE`).
- **Cambio de contrato respecto de la versión anterior de este endpoint (30/09/2026):** desaparece la respuesta `{"resultado":"validado"}` (el `Pedido` ya no permanece en `PEND_PAGO` tras iniciar: el inicio real crea el intento y pasa a `PROCESANDO_PAGO`), `iniciar_pago` ahora exige `proveedor_id` e `items[]`, y el endpoint queda ligado a `PaymentValidationService`/`PagoRepository` (secciones 12 y 19).

---

## 18. Recuperación de pedidos pendientes

### 18.1. Disparador

La recuperación **solamente puede ofrecerse** cuando existe un Pedido actual cuyo email haya sido verificado exitosamente y que se encuentre en `PEND_PAGO`.

- El email ingresado inicialmente **NO** es suficiente para acceder a información de operaciones anteriores.
- La recuperación se inicia siempre desde el Pedido actual ya verificado: sin verificación exitosa no hay recuperación (ver sección 15.2).

### 18.2. Regla de unicidad

Debe existir como máximo **UNA** operación anterior recuperable, entre:

- `PEND_VERIF`
- `PEND_PAGO`

`PROCESANDO_PAGO` queda expresamente **fuera** de esta restricción: puede encontrarse en proceso de conciliación, su resultado puede estar fuera del control del comprador y esa operación debe continuar independientemente. No cuenta como operación recuperable pendiente y **no participa de este mecanismo**.

No deben existir dos operaciones simultáneamente ofrecidas como recuperables para el mismo email.

### 18.3. Casos

**Caso A — Pedido anterior en `PEND_VERIF`:**

- anterior: `PEND_VERIF → PEND_PAGO`;
- hereda la verificación de email realizada por el Pedido actual;
- **NO** se vuelve a enviar una verificación;
- actual: → `NO_PAGADO`;
- no se modifican los `PedidoItem` del Pedido recuperado.

**Caso B — Pedido anterior en `PEND_PAGO`:**

- anterior: permanece en `PEND_PAGO`;
- actual: → `NO_PAGADO`.

**Caso C — continuar con el Pedido actual:**

- actual: permanece en `PEND_PAGO`;
- anterior recuperable: → `NO_PAGADO`.

La operación elegida es la que continúa.

### 18.4. Cuentas futuras

Con un futuro usuario registrado la lógica será conceptualmente la misma; cambia únicamente el mecanismo de identificación (guest: email verificado; usuario registrado: sesión/cuenta autenticada). No se implementan cuentas de usuario en el MVP (ver sección 2).

---

## 19. Arquitectura de capas y componentes backend (conceptual)

Arquitectura de capas del backend, **ya operativa** para los siete bloques implementados (`GET /api/productos`, `POST /api/pedidos`, los dos endpoints de verificación de email, `cancelar`/`pago` con vencimiento, el bloque de pagos con `DummyPaymentProvider` y, desde el 03/10/2026, el canal de email `log` de `src/mail/`; ver sección 21) y **cerrada a nivel conceptual** para el resto (ver `ECOMMERCE_DESIGN.md`, sección 1.1):

```text
Frontend
   ↓ HTTP/JSON
Routes / API
   ↓
Controller
   ↓
Service
   ↓
Repository / acceso a datos
   ↓
MariaDB
```

Además existen **Config** (configuración de aplicación, DB y variables de entorno) y **Model/entidades** (representación conceptual/de datos del dominio únicamente cuando sea útil; no se crea un Model artificial sólo porque exista una tabla y no se usa un ORM como eje de la arquitectura).

Responsabilidades:

- **Routes**: determinan qué Controller atiende cada endpoint; no contienen lógica de negocio.
- **Controller**: recibe HTTP; valida la estructura básica del request; invoca Services; convierte resultados y errores de aplicación en respuestas HTTP/JSON; no contiene lógica de negocio y no ejecuta SQL directamente.
- **Service**: contiene y coordina la lógica de negocio; decide reglas y transiciones; no debe acceder directamente a SQL.
- **Repository**: acceso a persistencia (consulta, inserción y actualización); no decide reglas de negocio.
- **Database**: persistencia e integridad referencial.

**Regla general:** una capa no debe saltarse arbitrariamente las capas inferiores. Flujo normal: `Frontend → Route → Controller → Service → Repository → DB`.

Estructura de directorios prevista:

```text
public/index.php          → front controller / router
routes/                   → definición de rutas
src/controllers/          → manejo de HTTP/JSON
src/services/             → lógica de negocio
src/repositories/         → acceso a datos (PDO preparado)
src/models/               → entidades
src/config/               → configuración por entorno
storage/logs/             → logging
```

### 19.1. Services del MVP

Services acordados:

1. **`PedidoService`** — orquesta el proceso de compra: valida la disponibilidad dentro del proceso de compra; coordina las validaciones comerciales; crea `Pedido` y `PedidoItem` mediante `PedidoRepository`; gestiona la corrección de carrito; gestiona la recuperación de pedidos pendientes; coordina el inicio del pago; coordina los efectos de un pago aprobado; centraliza la lógica comercial de los resultados de pago (la notificación/confirmación del proveedor no decide el estado del `Pedido`) y coordina la generación y resolución de los casos de revisión. Como **consumidor** del mecanismo genérico de verificación, inicia la verificación al crear el `Pedido` (envío del email), confirma tokens y gestiona los reenvíos, y ejecuta la transición `PEND_VERIF → PEND_PAGO`. **Estado:** implementado y verificado para la creación del `Pedido` (inicio del checkout, con disponibilidad, validación comercial y transacción; ver sección 17.1) y para la verificación de email (inicio, confirmación y reenvío; ver sección 15.4); el resto (corrección de carrito, recuperación, pagos y casos de revisión) **pendiente**.
2. **`ProductoService`** — opera sobre datos y reglas propios de `Producto`; no conoce Pedidos; no calcula disponibilidad; no debe depender de `Pedido`. **Estado:** implementado y verificado (consulta del catálogo sin disponibilidad, que calcula `PedidoService`).
3. **`EmailVerificationService`** — servicio genérico de verificación (sección 15): crea los registros, genera los tokens **nuevos** (aleatorios, 256 bits) guardando sólo su hash SHA-256, controla expiración (7 días) y cooldown (15 minutos, sobre `last_sent_at`) parametrizados, **rota el token en cada reenvío permitido** (el anterior queda inválido; lógica interna; el consumidor no elige la operación), valida los tokens, registra `verified_at` (sin cambiar estados del `Pedido`) y devuelve el resultado al consumidor; **no envía emails** (cada consumidor envía el suyo) y no duplica la lógica de `Pedido`. **Estado:** implementado y verificado (29/09/2026), consumido por `PedidoService`; ver secciones 15 y 15.4.
4. **`PaymentValidationService`** — frontera del backend con el proveedor de pagos (en el MVP: `DummyPaymentProvider`, vía `PaymentProvider`); normaliza los resultados del proveedor; no puede ser sustituido por el retorno del navegador; participa en la confirmación de resultados (incluido el procesamiento del webhook cuando exista, POST-MVP); debe contemplar idempotencia. Es el único componente autorizado a interactuar con el proveedor de pagos. Expone `registrarIntento(pedidoId, importe, proveedorId)` (crea el `Pago` y su `PaymentValidationResult` `EN_PROCESO` **antes** de tocar al proveedor) y `procesarIntento(pagoId, importe, simulacion)` (consulta al proveedor, normaliza, persiste sobre el mismo resultado y devuelve el `PaymentValidationResult`; jamás sobrescribe un resultado definitivo). **Estado:** implementado y verificado (30/09/2026; ver secciones 12.2, 12.4, 17.2 y 21).

No se crean Services adicionales sólo por existir una entidad. **No existen para el MVP:** `PagoService`, `StockService`, `AvailabilityService`, `ShowService`.

> **NO** existe `ReservaService`: no hay entidad `Reserva` en el MVP. No existe `CarritoService`: no hay estado de carrito del lado del servidor.

### 19.2. Repositories del MVP

Repositories acordados:

- **`ProductoRepository`** — sólo datos propios de `Producto`: producto, activo/inactivo, precio, stock físico y demás datos propios; `Producto` no conoce `Pedido`; no calcula disponibilidad; además `bloquearPorIds()` (`SELECT ... FOR UPDATE`) y **`descontarStock(productoId, cantidad)`** (descuento condicional `UPDATE ... SET stock = stock - ? WHERE id = ? AND stock >= ?`, con error si `rowCount` ≠ 1, usado por el pago aprobado). **Estado:** implementado y verificado (consulta del catálogo y bloqueo de filas con `SELECT ... FOR UPDATE` para la creación del `Pedido`; descuento de stock con pago aprobado desde el 30/09/2026).
- **`PedidoRepository`** — persistencia de `Pedido`; persistencia de `PedidoItem`; crear/consultar/actualizar `Pedido`; crear/consultar `PedidoItem`; consultar las cantidades de `PedidoItem` de Pedidos activos necesarias para calcular la disponibilidad; buscar Pedidos recuperables según las reglas del negocio; además, para la verificación de email: `buscarPorId`, `buscarTodosPorEmailVerificacion` (todos los `Pedido` asociados a una verificación, con `id`, `estado`, `created_at` y `NOW()` de MariaDB para evaluar el primer período; **30/09/2026**: reemplaza a `buscarPorEmailVerificacion`, que devolvía un solo `Pedido` de forma arbitraria), `asociarEmailVerificacion` (escritura de `pedidos.email_verificacion_id`) y `actualizarEstado` (para `PEND_VERIF → PEND_PAGO` y `PEND_VERIF → NO_PAGADO`). **Estado:** implementado y verificado para cantidades comprometidas, inserción de `Pedido`/`PedidoItem`, control de la transacción (begin/commit/rollback) y las operaciones de verificación (incluida la confirmación N:1, 30/09/2026); el resto (consultas de recuperación) **pendiente**.
- **`PagoRepository`** — persistencia de `Pago`; consultas y actualizaciones necesarias para estados, trazabilidad e idempotencia. Métodos: `insertarPago`, `insertarValidacionPago`, `buscarValidacionPorPago` (join con `validaciones_pagos_estados`), `actualizarValidacionPago`, `contarIntentos`, `pedidoTieneValidacionAprobada`, `buscarProveedor` (por `id` o `codigo`, sin asumir IDs fijos), `idDeEstadoValidacion`, `insertarCasoRevision` (deduplicación por `(pago_id, tipo)` a nivel de aplicación), `resolverCasosRevision` (→ `RESUELTO`) y `buscarCasosRevision`. **Estado:** implementado y verificado (30/09/2026; ver secciones 12 y 21).
- **`EmailVerificationRepository`** — persistencia de la información de verificación: `email`, hash del token, `expires_at`, `verified_at`, `created_at` y `last_sent_at` (estructura cerrada, sección 15.3); consulta/creación/actualización de registros, incluida la escritura de `verified_at` que realiza el servicio y la de `last_sent_at` tras un envío exitoso; registros completados conservados, sin borrado (mecanismo genérico, sin datos de contexto del consumidor). **Estado:** implementado y verificado (29/09/2026).

**No se crea `ShowRepository` para el MVP.** Las tablas `shows` y `shows_estados` pueden existir estructuralmente porque `productos.show_id` las referencia, pero no hay funcionalidad de Shows/Tickets en el MVP: no existen `ShowController`, `ShowService` ni `ShowRepository`.

### 19.3. Cálculo de la disponibilidad

El cálculo de la disponibilidad lo coordina **`PedidoService`**, con la información obtenida mediante **`ProductoRepository`** (stock físico) y **`PedidoRepository`** (cantidades de `PedidoItem` de Pedidos activos). No existe `AvailabilityService` ni `StockService`, `Producto` no conoce `Pedido` y la disponibilidad no se persiste (ver sección 10). **Estado:** implementado y verificado en `GET /api/productos` y `POST /api/pedidos` (cálculo en caliente con `max(0, stock − comprometido)`); sigue sin ser persistida.

### 19.4. Endpoints conceptuales del MVP

Endpoints conceptuales acordados (el detalle HTTP —códigos, parámetros, formatos— está definido e implementado para todos los endpoints del MVP; ver secciones 15.4, 17.1, 17.2 y 19.4):

```text
GET  /api/productos      → implementado y verificado (29/09/2026)
POST /api/email-verificaciones
POST /api/email-verificaciones/validar  → implementado y verificado (29/09/2026)
POST /api/email-verificaciones/reenvio  → implementado y verificado (29/09/2026)
POST /api/email-verificaciones/pedidos  → implementado y verificado (05/10/2026; consulta solo lectura de Pedido + PedidoItem por token, para continuar la compra)
POST /api/email-verificaciones/pedidos/reemplazo  → implementado y verificado (06/10/2026; sustituye el Pedido activo por otro con los items nuevos, autorizado por token, sin nueva verificación ni nuevo email)
POST /api/pedidos        → implementado y verificado (29/09/2026)
POST /api/pedidos/{id}/pago             → implementado y verificado (30/09/2026; tercera validación + inicio real de pago con DummyPaymentProvider)
POST /api/pedidos/{id}/cancelar   (cancelación voluntaria del checkout) → implementado y verificado (30/09/2026)
```

- `POST /api/email-verificaciones` (solicitud) **no se implementó como endpoint**: esa operación está integrada en `POST /api/pedidos` (opción A, después del `COMMIT`), por lo que no hay ruta dedicada de solicitud.
- `POST /api/webhooks/mercadopago` queda **POST-MVP** (junto con `MercadoPagoProvider` y la integración real); en el MVP la confirmación se realiza validando contra el proveedor simulado (ver sección 12.6), y el detalle de su disparador HTTP es pendiente de diseño.

- `POST /api/pedidos` **NO** escribe directamente en la base de datos desde el endpoint. El flujo es: `Route → Controller → PedidoService → PedidoRepository → Database`. **Implementado y verificado:** inicio del checkout y primera ejecución de la validación comercial, con `Pedido` y `PedidoItem` insertados dentro de una transacción de MariaDB y bloqueo `SELECT ... FOR UPDATE` de las filas de `productos` (request, respuestas y reglas de concurrencia en la sección 17.1); después del `COMMIT` inicia la verificación de email sin condicionar la respuesta del endpoint.
- `POST /api/pedidos/{id}/pago` **(implementado el 30/09/2026, con inicio real de pago):** recibe `{"accion":"iniciar_pago","proveedor_id":...,"items[]":[...],"simulacion":"..."}` o `{"accion":"aceptar_correccion","items[]":...}`; ejecuta la validación comercial (existencia, activo, cantidades, disponibilidad, precio) con bloqueo `FOR UPDATE` de `productos`, crea el `Pago` y su `PaymentValidationResult` (`EN_PROCESO`), pasa el `Pedido` a `PROCESANDO_PAGO` y procesa el resultado del proveedor en una segunda transacción (fase A/B); contrato completo en la sección 17.2. `PaymentValidationService`, `PagoRepository` y `DummyPaymentProvider` existen desde el 30/09/2026 (secciones 12.2 y 19.1/19.2).
- `POST /api/pedidos/{id}/cancelar` **(implementado el 30/09/2026):** cancela/descarta voluntariamente un `Pedido` activo del checkout (`PEND_VERIF` o `PEND_PAGO`): pasa a `NO_PAGADO`, libera la disponibilidad comprometida y deja los `PedidoItem` intactos; sirve también para rechazar una corrección del carrito (sección 17.2). Contrato: sin body; `200 {"resultado":"cancelado","pedido":{"id":N,"estado":"NO_PAGADO"}}`; `404 {"error":"pedido_inexistente"}`; `409 {"error":"pedido_vencido","pedido":{...}}` (vencido el primer período: se pasa a `NO_PAGADO` y no puede continuar); `409 {"error":"pedido_no_activo","pedido":{...}}` (ya no está activo, p. ej. segunda cancelación); `400 {"error":"pedido_id inválido"}` si `{id}` no es numérico; `405` con `Allow` ante otro método. Transacción: lectura del `Pedido` con `SELECT ... FOR UPDATE` + `UPDATE estado` + `COMMIT`. No confundir con cancelaciones post-venta (fuera del MVP).
- Los endpoints `/api/email-verificaciones/validar`, `/api/email-verificaciones/reenvio` y `/api/email-verificaciones/pedidos` son **del consumidor** (la compra): los dos primeros invocan al mecanismo genérico de verificación y **el consumidor es quien envía el email** con su asunto, plantilla y URL (el mecanismo no envía emails); el tercero es una consulta solo lectura por token que devuelve `Pedido`/`PedidoItem` para continuar la compra (sección 15.4, decisión en la sección 21). **Implementados y verificados:** contrato completo en la sección 15.4.
- `POST /api/email-verificaciones/pedidos/reemplazo` **(implementado el 06/10/2026):** reemplazo del `Pedido` activo autorizado por el **token de verificación** (sin id numérico, sin IDOR). Recibe `{"token": "...", "items[]": [...]}` (mismas reglas de `items[]` que `POST /api/pedidos`); `PedidoService::reemplazarPedidoPorToken()` consulta la verificación por token (`400 token_invalido` inexistente, `400 token_expirado`, `409 verificacion_pendiente` si aún no está verificada), exige **exactamente un** `Pedido` activo (`PEND_VERIF`/`PEND_PAGO`) de esa verificación (`409 pedido_no_activo` con ninguno, `409 varios_pedidos_activos` con más de uno) y **reutiliza el mecanismo existente** `PedidoService::procesarPago(..., 'aceptar_correccion', ...)`: misma validación comercial completa y misma transacción en la que el anterior pasa a `NO_PAGADO` y se crea el nuevo heredando estado y `email_verificacion_id` (`200 reemplazado` o `200 correccion` sin sustituir). **NO** crea una nueva `emails_verificaciones`, **NO** envía un email nuevo y **NO** inicia ningún pago: el `Pedido` resultante se paga después con `POST /api/pedidos/{id}/pago` (contrato en la sección 17.2). `400` ante JSON inválido, `token` faltante/no string (`400 "Falta el campo token"` / `400 "token inválido"`) o `items` ausente (`400 "Falta el campo items"`), vacío (`400 "El carrito está vacío"`) o con ítems inválidos (mismos `400` de estructura/cantidad que `POST /api/pedidos`). Sin cambios de esquema: `pedidos.email_verificacion_id` **no** tiene `UNIQUE`, por lo que varios `Pedido` pueden compartir la misma verificación.
- **NO** se crean endpoints para: carrito persistente, stock, disponibilidad, reservas ni `checkout_id` (ninguno de esos conceptos existe en el backend; ver secciones 9 y 16).

### 19.5. Errores de negocio y errores técnicos

**Errores de negocio** (entre otros):

- producto inexistente;
- producto inactivo;
- disponibilidad insuficiente;
- cambio de precio;
- Pedido no recuperable;
- email no verificado;
- máximo de intentos alcanzado;
- pago definitivamente rechazado.

**Errores técnicos:**

- base de datos no disponible;
- excepción inesperada;
- timeout;
- error de comunicación con el proveedor;
- imposibilidad técnica de consultar al proveedor.

Reglas:

- el **Service** determina el resultado (de negocio o técnico);
- el **Controller** transforma ese resultado en HTTP/JSON;
- un **error técnico del proveedor NO se convierte automáticamente en `RECHAZADO`** (ver secciones 12.4 y 12.7).

### 19.6. Transacciones

Toda operación que pueda dejar datos inconsistentes si falla parcialmente debe ejecutarse dentro de una **transacción de MariaDB**. En particular:

- creación de `Pedido` + `PedidoItem` cuando corresponda;
- operaciones críticas de pago;
- afectación de stock + persistencia del resultado (`PaymentValidationResult`) + transición del `Pedido`.

El **Repository** realiza la persistencia. El **Service** coordina la operación de negocio y determina cuándo corresponde una operación transaccional. **No se colocan reglas comerciales dentro del Repository.** La idempotencia está garantizada por el backend/DB (ver sección 12.6): una notificación/confirmación repetida (o un webhook duplicado cuando exista) o una repetición de la misma operación no descuenta stock nuevamente, no crea otro `Pago` para la misma operación, no genera otro `APROBADO`, no vuelve a aplicar una transición ya aplicada ni produce efectos comerciales duplicados (máximo un `PaymentValidationResult` `APROBADO` por `Pedido`).

**Estado:** la creación de `Pedido` + `PedidoItem` **ya está implementada** en `POST /api/pedidos` (sección 17.1): `PedidoService` coordina la operación y `PedidoRepository` controla `BEGIN`/`COMMIT`/`ROLLBACK`, con bloqueo `SELECT ... FOR UPDATE` de `productos` durante la validación; las reglas comerciales permanecen en el Service. **Desde el 30/09/2026** también están implementadas, con la misma estructura: la cancelación (`POST /api/pedidos/{id}/cancelar`: lectura del `Pedido` con `FOR UPDATE` + `UPDATE estado` + `COMMIT`), el flujo de `POST /api/pedidos/{id}/pago` (fase A: `FOR UPDATE` del `Pedido`, validación, `INSERT` de `Pago` + `validaciones_pagos`, `UPDATE` a `PROCESANDO_PAGO` y `COMMIT`; fase B en una **segunda** transacción: `FOR UPDATE` del `Pedido`, resultado del proveedor persistido en `validaciones_pagos`, descuento condicional de `productos` y transición del `Pedido`, con `COMMIT` o `ROLLBACK` completos) y la afectación de stock con pago aprobado (sección 10.3). Las operaciones del bloque de verificación de email son operaciones puntuales sobre una fila (insert del registro, update de `verified_at`/`last_sent_at`, update de estado) y **no** requieren transacción compuesta: la asociación `pedidos.email_verificacion_id` se realiza después del `COMMIT` del `Pedido`. **Idempotencia verificada** en el flujo de pago: un `PaymentValidationResult` definitivo no se sobrescribe, un reintento sobre un `Pedido` ya `PAGADO` no crea otro `Pago`, un resultado aprobado repetido no descuenta stock dos veces y los casos de revisión se deduplican a nivel de aplicación.

Los detalles finales de cada componente restante, su ubicación exacta y sus interfaces forman parte de la Etapa 2 (pendiente).

---

## 20. Pendientes técnicos

No está definido todavía (corresponde a la siguiente etapa de diseño técnico; los puntos marcados ya están resueltos para los siete bloques implementados: `GET /api/productos`, `POST /api/pedidos`, los dos endpoints de verificación de email, el bloque de vencimiento/cancelación/inicio de pago, el bloque de pagos con `DummyPaymentProvider` y, desde el 03/10/2026, el canal de email `log`):

- detalle de la API para los endpoints restantes: códigos HTTP, parámetros y formato *(ya definidos e implementados para los siete endpoints operativos: `GET /api/productos`, `POST /api/pedidos`, `POST /api/email-verificaciones/validar`, `POST /api/email-verificaciones/reenvio`, `POST /api/email-verificaciones/pedidos`, `POST /api/pedidos/{id}/cancelar` y `POST /api/pedidos/{id}/pago`, ver secciones 15.4, 17.1, 17.2 y 19.4)*;
- DTOs y formato exacto de respuestas y errores de los endpoints restantes *(ya definidos para los siete endpoints implementados: `201`/`200` de corrección/`400`/`404`/`405` con sus cuerpos JSON en `POST /api/pedidos`, `200`/`400`/`409`/`429`/`502` de verificación, `200`/`400`/`409` de la consulta por token, y `200`/`400`/`404`/`409`/`405` de `cancelar` y `pago`, ver secciones 15.4, 17.1, 17.2 y 19.4)*;
- clases PHP concretas, SQL y migraciones de los componentes restantes y migraciones versionadas *(ya implementados: front controller, routing, `ProductoController`, `PedidoController`, `EmailVerificationController`, `ProductoService`, `PedidoService`, `PaymentValidationService`, `EmailVerificationService`, `ProductoRepository`, `PedidoRepository`, `PagoRepository`, `EmailVerificationRepository`, `PaymentValidationResult`, `PaymentProvider`/`DummyPaymentProvider`/`PaymentProviderException`, `EmailSender`/`LogEmailSender` (`src/mail/`, canal `log` desde el 03/10/2026) y `src/config/email.php`, y su SQL para los seis bloques (el canal `log` de `src/mail/` no tiene SQL, por eso el recuento de bloques con SQL no cambió; persisten sin migraciones versionadas)*;
- estructura interna detallada de los Services y Repositories todavía no implementados (**RESUELTO el 30/09/2026**: `PaymentValidationService` y `PagoRepository` implementados; ver secciones 19.1, 19.2 y 21);
- flujo técnico del resto del checkout (las reglas de negocio de validación comercial y corrección ya están cerradas, ver secciones 17 y 18; **ya implementado**: creación del `Pedido` con validación comercial en transacción (sección 17.1), tercera validación + corrección con `Pedido` existente + aceptación de corrección con sustitución en una transacción (`POST /api/pedidos/{id}/pago`, secciones 17.2 y 19.4) y cancelación voluntaria (sección 19.4); **pendiente**: recuperación de pedidos pendientes);
- comportamiento de `PedidoRepository::buscarPorEmailVerificacion()`: **RESUELTO el 30/09/2026** — método reemplazado por `buscarTodosPorEmailVerificacion()` (consulta **todos** los `Pedido` asociados a la verificación, con orden determinístico `ORDER BY p.id`, `created_at` y `NOW()`); la confirmación N:1 transiciona todos los `PEND_VERIF` vigentes a `PEND_PAGO`, pasa a `NO_PAGADO` los `PEND_VERIF` vencidos y no toca el resto de estados, devolviendo `pedidos[]` (ver secciones 15.2 y 15.4);
- implementación del mecanismo de verificación de email: **ya implementada y verificada el 29/09/2026** (estructura de `emails_verificaciones` con `last_sent_at`, comportamiento, separación de responsabilidades y flujo de confirmación cerrados — ver secciones 15, 15.3 y 15.4; clases PHP, endpoints y detalle HTTP implementados). **Pendiente de este bloque:** (1) ~~canal de email `log` de DEV~~ **RESUELTO e implementado el 03/10/2026** (ver secciones 5, 6, 15 y 21: `EmailSender`/`LogEmailSender`, `src/config/email.php` con `channel = log`, selección e inyección en `public/index.php`); (2) canal **real** de envío del email, **requisito para dar por terminado el MVP** (hoy DEV/MVP entrega por el canal `log`; sin MTA/SMTP/APIs/credenciales ni proveedor real elegido); (3) URL/asunto/plantilla definitivos del checkout;
- implementación de `DummyPaymentProvider`: **RESUELTA el 30/09/2026** (contrato `PaymentProvider`, normalización y reglas ya estaban cerrados; la simulación se disparó mediante el parámetro opcional `simulacion` del request de `iniciar_pago` — `APROBADO` por defecto o `RECHAZADO`; hasta el 05/10/2026 también se admitía `EN_PROCESO`, **desde esa fecha el conjunto admitido es sólo `APROBADO`/`RECHAZADO`** y `EN_PROCESO` se rechaza con `400 simulacion inválida` — ver secciones 17.2 y 21);
- integración de pagos real con Mercado Pago (`MercadoPagoProvider`, webhook `POST /api/webhooks/mercadopago` y su modalidad de checkout — Checkout Pro vs Checkout API/Orders, detalle de implementación): **POST-MVP** (ver sección 12.2);
- creación de `validaciones_pagos`, `validaciones_pagos_estados` (renombramiento de `pagos_estados`), `casos_revision` y sus catálogos, y ajuste de `pedidos_estados`: **ya aplicados en la base de datos** (ver sección 9); el catálogo `proveedores` **ya existe** con las filas `MERCADOPAGO` y `DUMMY` (sin cambios SQL); su concreción en código **ya está hecha** desde el 30/09/2026 (`PagoRepository`, `PaymentValidationService`, casos de revisión; ver secciones 19.1, 19.2 y 21);
- liquidación/conciliación con comisiones, impuestos/cargos del proveedor y neto liquidado (fuera del MVP; ver sección 12.1.2);
- historial de movimientos de stock (fuera del MVP; ver sección 10.3);
- implementación concreta de idempotencia y trazabilidad de interacciones con el proveedor en el MVP: **RESUELTA el 30/09/2026** (referencia `DUMMY-{pagoId}`, un único `PaymentValidationResult` por `Pago` con `UNIQUE(pago_id)`, resultados definitivos no sobrescritos, sin doble descuento de stock, deduplicación de casos de revisión a nivel de aplicación; ver secciones 12.6, 17.2 y 19.6); el webhook es **POST-MVP** junto con la integración real;
- implementación de la cancelación voluntaria del checkout: **RESUELTA el 30/09/2026** (`POST /api/pedidos/{id}/cancelar`, `PEND_VERIF`/`PEND_PAGO → NO_PAGADO`, liberación de disponibilidad, `PedidoItem` intactos; detalle HTTP en la sección 19.4; ver secciones 17.2 y 19.4);
- códigos HTTP y formatos de error de los endpoints restantes (la clasificación entre errores de negocio y errores técnicos está definida en la sección 19.5; los códigos y cuerpos de `GET /api/productos`, `POST /api/pedidos`, de los endpoints de verificación, de `POST /api/pedidos/{id}/cancelar` y de `POST /api/pedidos/{id}/pago` ya están implementados y verificados);
- seguridad y CORS;
- autenticación de cuentas (fuera del MVP; queda para la evolución futura);
- autenticación/verificación específica de las notificaciones webhook de Mercado Pago (fuera del MVP como definición funcional; detalle de implementación/provider-specific para una etapa posterior; no se definen firmas, headers, secretos ni algoritmos; ver sección 12.6);
- resolución administrativa de los casos de revisión `PAGADO_STOCK_NO_AFECTADO` y de los `PAGO_PROCESANDO_EXCEDIDO` que nunca reciben un resultado definitivo (fuera del MVP; ver sección 12.8);
- frontend detallado y diseño visual;
- administración MVP;
- configuración de producción (incluye el canal real de email; la conmutación desde el canal `log` de DEV ya se resuelve por configuración — `src/config/email.php` + selección en `public/index.php`, desde el 03/10/2026);
- mecanismo técnico de concurrencia, transacciones y locks para la **afectación de stock** con pago aprobado (MariaDB): **ya implementado el 30/09/2026** (bloqueo `FOR UPDATE` de `productos` + descuento condicional en la transacción de la aprobación, ver secciones 10.3 y 19.6); *(también implementado desde el 29/09/2026 para la verificación de disponibilidad en la creación del `Pedido`: transacción de MariaDB + `SELECT ... FOR UPDATE` de `productos` en `POST /api/pedidos`, ver secciones 17.1 y 19.6)*;
- valores concretos de las dos ventanas del `Pedido` (la del primer período tiene valor inicial de diseño **10 minutos** y valor operativo actual **40 minutos** desde el 03/10/2026 — parametrizable, no definitivo —, ver sección 13.2; la del segundo período no fija valor), de `PAGO_MAX_INTENTOS` y de `PAGO_MAX_ESPERA_CONCILIACION` (comportamiento **cerrado como parametrizable**, no es un pendiente de diseño; se cargan en configuración);
- esquema definitivo de las tablas futuras todavía inexistentes (historial de precios, historial de interacciones); `productos_imagenes` **ya existe** (ver sección 9);
- contenido y plantilla del email post-pago de verificación de la transacción.

**Definido y NO pendiente:** el ciclo de vida del `Pedido`, sus estados y transiciones; el mecanismo conceptual de cantidades comprometidas (cálculo en caliente desde `PedidoItem` de Pedidos activos); el carrito (exclusivamente frontend, `sessionStorage`, sin `checkout_id`); la validación comercial, la corrección de carrito y la regla de que todo carrito del frontend es no confiable (sección 17); la recuperación de pedidos pendientes (sólo desde un Pedido actual verificado en `PEND_PAGO`, máximo un `Pedido` anterior recuperable entre `PEND_VERIF` y `PEND_PAGO`, `PROCESANDO_PAGO` fuera de esa regla; ver sección 18); el modelo de pago `Pago 1:1 PaymentValidationResult` con estados y proveedor en el resultado, catálogo `proveedores`, importes separados y máximo un `PaymentValidationResult` `APROBADO` por `Pedido` (sección 12.1); los nombres de tablas del modelo de pago: **`validaciones_pagos`** y catálogo **`validaciones_pagos_estados`** (renombramiento de `pagos_estados`); la frontera con el proveedor y el flujo de creación `Pago → PaymentValidationResult → PaymentProvider (DummyPaymentProvider en el MVP)` (sección 12.2); los endpoints conceptuales (sección 19.4); la clasificación de errores de negocio y técnicos (sección 19.5); las reglas de transacciones e idempotencia (sección 19.6); los casos de revisión (`CasoRevision` ligado al `Pago`, con columnas `id`, `pago_id`, `tipo_id`, `estado_id`, `created_at`, `updated_at`, sin `descripcion`, sin `UNIQUE(pago_id, tipo_id)` —deduplicación a nivel de aplicación—, catálogos `casos_revision_tipos`/`casos_revision_estados` con `orden` y `activo`, tipos `PAGADO_STOCK_NO_AFECTADO` y `PAGO_PROCESANDO_EXCEDIDO`, estados `PENDIENTE`/`RESUELTO`, con resolución cuando llega un resultado definitivo — ver sección 12.8); el mecanismo genérico de verificación de email (agnóstico de contexto, sin campo `contexto`, consumidor del MVP: la compra; futuros POST-MVP: newsletter, registro de cuentas y recuperación de contraseña — ver sección 15); la estructura física de `emails_verificaciones` (`id`, `email`, `token_hash`, `expires_at`, `verified_at`, `created_at`; PK `id`; índice no único `idx_emails_verificaciones_email (email)`; sin `estado_id`; sin `pedido_id`/`contexto`/`tipo`/`uso`/`origen`; `email` NO UNIQUE; registros completados conservados; FK unidireccional `pedidos.email_verificacion_id → emails_verificaciones.id` — ver sección 15.3); las responsabilidades del servicio de verificación (hash, expiración 7 días, cooldown 15 minutos, reutilización/regeneración internas, validación, registro de `verified_at` y devolución del resultado) y la separación entre verificación genérica, lógica del `Pedido`, envío de emails (cada consumidor envía el suyo) y modelo histórico del newsletter; la separación entre la lógica del proveedor (encapsulada en `MercadoPagoProvider`, incluida la modalidad de checkout como detalle de implementación) y la lógica comercial (centralizada en `PedidoService`). No existe `Reserva` ni `stock_comprometido`.

---

## 21. Relación con la implementación

Este documento describe la arquitectura acordada (consolidación documental del 26/09/2026 y actualización documental del 28/09/2026), más el estado real verificado por auditoría del 28/09/2026, por la implementación del 29/09/2026 y por la del 30/09/2026 (secciones 4, 15.4, 17.1, 17.2, 19 y más abajo). **No implica que todas las funcionalidades descriptas estén implementadas**.

Durante la consolidación documental (26/09/2026):

- no se modificó código;
- no se modificó la base de datos (declarado para esa sesión);
- no se crearon endpoints ni rutas;
- no se modificó configuración;
- no se modificaron dependencias;
- no se avanzó con el diseño técnico detallado (endpoints, DTOs, SQL, migraciones, clases concretas y estructura interna): quedan para la siguiente etapa.

Durante la actualización documental (28/09/2026):

- se documentó la separación de ecosistemas (tres proyectos: `D:\Incancelables Web`, `D:\Incancelables Backend` y `D:\Incancelables E-Commerce`) y la convivencia sin integración durante el MVP;
- se cerró la arquitectura del mecanismo **genérico** de verificación de email (agnóstico de contexto: se elimina `emails_verificaciones.contexto`);
- se cerró la **estructura física de `emails_verificaciones`** (columnas, PK, índice no único, sin `estado_id`, `email` NO UNIQUE, registros completados conservados, FK unidireccional), las responsabilidades del servicio (hash, expiración 7 días, cooldown 15 minutos, reutilización/regeneración internas, registro de `verified_at`) y la separación entre el mecanismo, la lógica del `Pedido` y el envío de emails;
- se renombraron las tablas del modelo de pago a `validaciones_pagos` y `validaciones_pagos_estados`;
- se cerró la definición de columnas de `casos_revision` y sus catálogos;
- se cerró la implementación de pagos del MVP: arquitectura `PaymentValidationService → PaymentProvider → DummyPaymentProvider` (proveedor simulado que ejercita `APROBADO` y `RECHAZADO` — y `EN_PROCESO` hasta el 05/10/2026, fecha en que quedó excluido del simulador, ver decisión más abajo); la integración real con Mercado Pago (`MercadoPagoProvider`, webhook `POST /api/webhooks/mercadopago` y su autenticación) queda **POST-MVP**, sobre el mismo contrato `PaymentProvider`;
- no se modificó código, endpoints, configuración ni dependencias.

### Auditoría de estado real (28/09/2026)

Se realizó una auditoría de **solo lectura** del proyecto (archivos, entorno y base de datos), sin modificar código, base de datos, configuración ni dependencias. Resultados verificados:

- **Entorno DEV operativo:** XAMPP, Apache, **PHP 8.2.12** (con `pdo_mysql`), **MariaDB 10.4.32**, VirtualHost `incancelables-ecommerce.local` → `public/`, entrada presente en `hosts`, respuesta **HTTP 200** y conexión PHP → MariaDB verificada.
- **Código (a 28/09/2026):** sólo existían `public/index.php` (prueba mínima con `echo "Incancelables E-Commerce funcionando";`) y `src/config/database.php` (conexión PDO con configuración **hardcodeada**, sin variables de entorno); los directorios `routes/`, `src/controllers/`, `src/database/`, `src/models/`, `src/services/` y `storage/logs/` estaban vacíos y `src/repositories/` todavía no existía en el disco. **No había routing, controllers, services, repositories, models ni endpoints funcionales.** Tampoco existían `composer.json`/autoloader, `.env`, migraciones `.sql` ni tests. *(Este estado es anterior: el 29/09/2026 se implementaron los tres primeros bloques; ver abajo y sección 4.)*
- **Base de datos:** `incancelables_ecommerce` contiene **15 tablas**. Ya están materializadas las decisiones de modelo detalladas en la sección 9 (`validaciones_pagos` con `UNIQUE(pago_id)`, `validaciones_pagos_estados`, eliminación de `pagos.estado_id`, `casos_revision` y sus catálogos cargados, los 6 estados de `pedidos_estados`, `productos_imagenes`, la estructura de `emails_verificaciones` y el catálogo `proveedores` con `MERCADOPAGO` y `DUMMY`). Se verifica también la **ausencia** de `reservas`, de `stock_comprometido`, de tablas de carrito y de `pedidos.importe_total`. Existen **6 productos** cargados en `productos`.
- **Trazabilidad del esquema:** los cambios de modelo se aplicaron directamente sobre MariaDB; el proyecto **no contiene archivos `.sql` ni migraciones versionadas** que los reproduzcan.

> La materialización del modelo en la base de datos ocurrió **fuera de las sesiones documentadas arriba** (26/09 y 28/09). Las secciones anteriores que describían esos cambios como "previstos / no aplicados" fueron corregidas para reflejar el estado real verificado (ver sección 9). **No se tomó ninguna decisión de diseño nueva durante esta auditoría.**

### Implementación de bloques (29/09/2026)

Se implementaron y verificaron los tres primeros bloques del MVP, sin modificar las decisiones de diseño cerradas:

- **`GET /api/productos`** — catálogo con `disponibilidad` calculada en caliente; `ProductoService` no calcula disponibilidad (la coordina `PedidoService` en los endpoints que la necesitan).
- **`POST /api/pedidos`** — inicio del checkout y **primera** ejecución de la validación comercial (sin `Pedido` previo); validación completa **dentro** de la transacción de MariaDB, con bloqueo `SELECT ... FOR UPDATE` de las filas de `productos` en orden ascendente de `producto_id`; `precio_unitario` obligatorio por item y precio persistido siempre desde `productos.precio`; `201` al crear, `200` con corrección (sin escrituras) ante diferencias, `400` ante errores estructurales, `404`/`405` según ruta/método. Detalle en la sección 17.1.
- **Verificación de email** — `EmailVerificationService` (genérico, agnóstico, sin envío de emails ni estados de `Pedido`) + `EmailVerificationRepository` + `EmailVerificationController`; rutas `POST /api/email-verificaciones/validar` y `POST /api/email-verificaciones/reenvio` (sin endpoint de solicitud: el inicio está integrado en `POST /api/pedidos`, después del `COMMIT`, con fallo de envío ≠ rollback); token aleatorio de 256 bits persistido sólo como SHA-256, expiración 7 días y cooldown 15 minutos calculados en SQL, rotación de token en cada reenvío, transición `PEND_VERIF → PEND_PAGO` a cargo de `PedidoService` sin nueva comprobación de stock (desde el 30/09/2026: **N:1** — todos los `Pedido` asociados vigentes habilitan `PEND_PAGO`, los `PEND_VERIF` vencidos pasan a `NO_PAGADO`, resto intacto, respuesta `pedidos[]`) y trazabilidad en `pedidos.email_verificacion_id`. **Único cambio de esquema:** nueva columna `last_sent_at` en `emails_verificaciones`. Contrato en la sección 15.4; detalle en las secciones 15 y 19.

Arquitectura verificada en los tres bloques: `Route → Controller → Service → Repository → MariaDB`, con el SQL únicamente en Repositories y Controllers/Services sin SQL ni control transaccional. Concurrencia verificada con dos checkouts simultáneos (un solo `201` y una corrección, sin deadlocks). Pruebas del bloque de verificación: 20/20 OK (creación, `token_hash == SHA2(token,256)`, expiración, cooldown natural y con fixture, rotación de token, transición `PEND_VERIF → PEND_PAGO`, stock intacto, fallo de envío sin rollback del `Pedido` y regresión de los bloques 1 y 2). Tras las pruebas la base de datos quedó en su estado baseline (`pedidos`, `pedidos_items` y `emails_verificaciones` vacíos; 6 productos; `pedidos.AUTO_INCREMENT` no reseteado).

Pendientes del bloque de verificación de email: ~~canal de email `log` de DEV~~ **implementado y verificado el 03/10/2026** (ver "Implementación del canal de email `log` (03/10/2026)" más abajo); siguen pendientes el canal de envío real (SMTP u otro; **requisito para dar por terminado el MVP**) y la URL/asunto/plantilla definitivos del checkout.

### Implementación del bloque de vencimiento, cancelación e inicio de pago (30/09/2026)

Se implementaron y verificaron sobre el estado existente, sin modificar las decisiones de diseño cerradas:

- **Rutas con `{id}`** — el router de `public/index.php` compara ahora cada ruta definida como patrón (`{param}` → `[^/]+`), pasa los parámetros al action del Controller y calcula el `Allow` del `405` sobre las rutas cuyo patrón coincide con la ruta pedida; `400 pedido_id inválido` si el `{id}` no es numérico.
- **Vencimiento general del primer período (10 minutos)** — `PedidoRepository::vencerPrimerPeriodoVencidos()` con `NOW()` de MariaDB como autoridad, aplicado de forma perezosa al calcular disponibilidad, al crear un `Pedido` y en las operaciones de `pago`; además cada operación sobre un `Pedido` concreto lo bloquea (`FOR UPDATE`), lo vence si corresponde y responde `409 pedido_vencido` (el `Pedido` vencido queda `NO_PAGADO` y no puede continuar). También se aplica en `reenvio` de verificación.
- **`POST /api/pedidos/{id}/cancelar`** — cancelación voluntaria: `200 cancelado`, `404 pedido_inexistente`, `409 pedido_vencido`, `409 pedido_no_activo`, con `PedidoItem` intactos y liberación de disponibilidad.
- **`POST /api/pedidos/{id}/pago`** — `accion: "iniciar_pago"` (tercera validación sobre los items persistidos en el `Pedido`, excluyendo su propio compromiso de disponibilidad; `200 validado` sin transición a `PROCESANDO_PAGO`, o `200 correccion` con `carrito_corregido`, `disponibilidad`, `motivos` y `catalogo` activo completo) y `accion: "aceptar_correccion"` (revalidación completa del `Pedido` + `items[]` enviados; con diferencias → `correccion` sin sustituir; válida → `200 reemplazado`: anterior `NO_PAGADO` + nuevo `Pedido` con el mismo estado y `email_verificacion_id` en **una sola transacción**). En este bloque todavía no se creó `Pago` ni existían `PaymentValidationService`/`DummyPaymentProvider`; **ese comportamiento fue reemplazado el mismo 30/09/2026 por el bloque de pagos descripto más abajo** (la respuesta `validado` desapareció).
- **Refactor sin duplicar reglas comerciales** — la validación comercial quedó centralizada en `PedidoService::validarCarrito()` (reutilizada por creación, `iniciar_pago` y `aceptar_correccion`), `cantidadesComprometidasPorProducto()` acepta un `Pedido` a excluir, `buscarPorId()` admite bloqueo `FOR UPDATE` y devuelve `created_at`/`NOW()`, `insertarPedido()` admite `email_verificacion_id` heredado y existe `buscarItemsPorPedido()`.

Pruebas del bloque (30/09/2026): **44/44 OK** a nivel de servicio (vencimiento al calcular disponibilidad, cancelación, `iniciar_pago` con casos válidos/precio/disponibilidad/vencidos/no-activos, `aceptar_correccion` con sustitución, sin sustitución, herencia de `PEND_VERIF`/`PEND_PAGO`/`email_verificacion_id` y reintentos), **23/23 OK** vía HTTP (router `{id}`, `405`/`404`/`400`, flujo completo creación → `iniciar_pago` → `aceptar_correccion` → `cancelar`, vencidos y regresión de verificación N:1) y **18/18 OK** de regresión del bloque N:1 de verificación. Tras las pruebas la base de datos quedó en su estado baseline (`pedidos`, `pedidos_items` y `emails_verificaciones` vacíos; 6 productos con sus precios originales).

El resto de la capa de aplicación del MVP (recuperación de pedidos pendientes, administración) **sigue sin implementarse**; la corrección de carrito quedó implementada como aceptación de corrección con sustitución y no como edición de un `Pedido` existente (el `Pedido` es inmutable).

### Implementación del bloque de pagos con DummyPaymentProvider (30/09/2026)

Se implementó y verificó sobre el estado existente, sin modificar las decisiones de diseño cerradas (las reglas se tomaron de las secciones 10.3, 12, 13 y 14 de este documento y de `ECOMMERCE_DESIGN.md`, secciones 8 y 13):

- **Archivos nuevos:** `src/payments/PaymentProvider.php` (contrato del adaptador), `src/payments/DummyPaymentProvider.php` (identidad `DUMMY`; devuelve `APROBADO` por defecto o el resultado `simulado` del request — `RECHAZADO`, y `EN_PROCESO` hasta el 05/10/2026, fecha en que quedó excluido del simulador: ver decisión más abajo —; lanza `PaymentProviderException` ante un resultado fuera del conjunto), `src/payments/PaymentProviderException.php`, `src/services/PaymentValidationService.php`, `src/models/PaymentValidationResult.php` y `src/repositories/PagoRepository.php`.
- **Modificados:** `public/index.php` (prefijos de autoloader `Incancelables\Payments\` e `Incancelables\Models\`, construcción e inyección de `PagoRepository` + `PaymentValidationService` + `DummyPaymentProvider`), `src/services/PedidoService.php` (2 dependencias nuevas, constante `PAGO_MAX_INTENTOS = 3`, `iniciarPago()` reestructurado en fases A/B, `procesarResultadoPago()`, `aplicarResultadoPago()`, `aplicarAprobado()`, `aplicarRechazado()`, `correccionPorCarritoDesactualizado()`, `diferenciasDeCarrito()` y `totalDelPedido()`; `carrito_vacio` también aplica a `iniciar_pago`), `src/controllers/PedidoController.php` (validación de `proveedor_id`/`items[]`/`simulacion` y mapa de errores ampliado) y `src/repositories/ProductoRepository.php` (`descontarStock()`).
- **Flujo:** `iniciar_pago` exige `proveedor_id` + `items[]` (opcional `simulacion`); fase A (transacción existente): vencimiento/estado → proveedor (`DUMMY` por id o código) → guarda `pago_ya_aprobado` → comparación `items[]` vs persistidos (`carrito_desactualizado`) → tercera validación comercial → guarda `maximo_intentos_alcanzado` (< 3) → `INSERT` de `Pago` (importe = total del `Pedido`) + `PaymentValidationResult` `EN_PROCESO` → `PEND_PAGO → PROCESANDO_PAGO` → `COMMIT`. Fase B (segunda transacción con `FOR UPDATE`): persistencia del resultado normalizado y aplicación de las reglas (`PAGADO` con descuento de stock atómico e idempotente | `PAGADO_STOCK_NO_AFECTADO` + caso ante importe inconsistente o stock insuficiente | `PEND_PAGO`/`NO_PAGADO` en rechazo | permanencia en `PROCESANDO_PAGO` ante un resultado no definitivo del proveedor — p. ej. `EN_PROCESO`, hoy excluido del simulador (05/10/2026) — o error técnico del proveedor).
- **Contrato nuevo:** `iniciar_pago` responde `aprobado` | `rechazado` | `en_proceso` | `correccion` (o `400`/`404`/`409`); **desaparece `{"resultado":"validado"}`** (cambio de contrato respecto del bloque anterior). Nuevos errores: `400 proveedor_faltante`/`proveedor_inexistente`/`proveedor_no_soportado`/`simulacion_invalida`; `409 maximo_intentos_alcanzado` (con `intentos`/`maximo_intentos`), `409 pago_ya_aprobado`, `409 pedido_no_procesando`.

Pruebas del bloque (30/09/2026): **46/46 OK** a nivel de servicio (`test_bloque_pagos.php`: aprobado completo con descuento único, rechazo → `PEND_PAGO` + reintento, límite de 3 intentos → `NO_PAGADO`, guardas `maximo_intentos_alcanzado`/`pago_ya_aprobado`, `EN_PROCESO` sin stock con compromiso activo, idempotencia de resultados definitivos, importe inconsistente → caso `PAGADO_STOCK_NO_AFECTADO`, stock insuficiente → `PAGADO_STOCK_NO_AFECTADO`, resolución de casos en el rechazo, validaciones de proveedor/simulación/carrito y restauración de baseline), **49/49 OK** de regresión del bloque anterior (`test_bloque_pago.php`, adaptado al nuevo contrato), **26/26 OK** vía HTTP de regresión (`http_tests2.php`) y **25/25 OK** vía HTTP del nuevo bloque (`http_tests3.php`: validación del body, corrección `carrito_desactualizado`, aprobado/rechazado/en_proceso punta a punta, guardas y vencimiento), más **18/18 OK** de regresión N:1. Tras las pruebas la base de datos quedó en su estado baseline (`pedidos`, `pedidos_items`, `emails_verificaciones`, `pagos`, `validaciones_pagos` y `casos_revision` vacíos; 6 productos con precios y stocks originales: `1:10, 2:15, 3:12, 4:8, 5:20, 6:10`).

Quedan fuera de este bloque (por decisión de alcance, ver secciones 13.3 y 20): segundo período automatizado/`PAGO_PROCESANDO_EXCEDIDO` generado por tiempo, endpoint de confirmación de resultados `EN_PROCESO`, webhook/`MercadoPagoProvider` (POST-MVP), email post-pago, resolución administrativa de casos y cambios de esquema.

### Decisión: `EN_PROCESO` queda fuera del simulador del MVP (05/10/2026)

**Decisión funcional cerrada (05/10/2026):** el parámetro `simulacion` de `POST /api/pedidos/{id}/pago` admite **únicamente** `APROBADO` (por defecto) y `RECHAZADO`. El valor `EN_PROCESO` deja de ser un resultado simulable y es rechazado por la validación existente del endpoint con `400 {"error":"simulacion inválida"}`, sin crear `Pago`, sin transición de `Pedido` y sin ningún efecto sobre la base de datos.

- **`EN_PROCESO` / `PROCESANDO_PAGO` existe conceptualmente** en el modelo (catálogo `validaciones_pagos_estados`, estados del `Pedido`, reglas de resultados no definitivos de las secciones 12.x y 13.x) y **se conserva para una evolución futura**: proveedores con resolución asincrónica (webhook de Mercado Pago, pagos pendientes/en procesamiento y conciliación), que son **POST-MVP**. Nada de esto se implementa en el MVP.
- **El simulador del MVP no admite `EN_PROCESO`** desde el 05/10/2026. El cambio se limitó a los dos puntos de validación que gobiernan el conjunto de resultados admitidos: `PedidoController` (lista de valores permitidos de `simulacion`) y `PedidoService::RESULTADOS_SIMULACION` (guarda de defensa del servicio). Ambos quedaron con `['APROBADO', 'RECHAZADO']`. No se modificaron `APROBADO`, `RECHAZADO` ni `CANCELADO`, el modelo de datos, los catálogos de estados, `PaymentProvider`, `DummyPaymentProvider` ni `PaymentValidationService`.
- **La resolución asincrónica, el webhook y la conciliación quedan fuera del MVP**, como ya estaban decididos (ver secciones 12.2, 12.6, 13.3 y 13.4 y `ECOMMERCE_ROADMAP.md`).
- **La confirmación de resultados `EN_PROCESO` NO es un pendiente obligatorio del MVP**: nunca formó parte del alcance implementado y a partir del 05/10/2026 tampoco es alcanzable desde el simulador, de modo que no existe un `Pedido` en `PROCESANDO_PAGO` sin mecanismo de resolución producido por el flujo ejecutable del MVP. La transitoria fila `EN_PROCESO` que `PaymentValidationResult` registra durante la fase A y que la fase B resuelve en la misma operación no cambia (es un detalle interno del flujo en dos fases, sección 17.2).
- **Verificación (05/10/2026):** `php -l` sin errores en ambos archivos; prueba funcional vía HTTP **36/36 OK** (`simulacion=EN_PROCESO` → `400 simulacion inválida` con `Pedido` intacto en `PEND_PAGO` y sin `Pago`/`validaciones_pagos`/`casos_revision`; `CANCELADO` y valores no string conservan el `400` existente; `RECHAZADO` → `200 rechazado` + `PEND_PAGO`; `APROBADO` → `200 aprobado` + `PAGADO` + descuento de stock; sin `simulacion` → `APROBADO`; contratos no relacionados sin regresiones), con la base de datos restaurada a su estado baseline al finalizar. En esta sesión **no se hizo commit**: el árbol de trabajo quedó con los cambios pendientes de revisión.

### Decisión: canales de email por entorno (02/10/2026)

*(Actualización documental del 02/10/2026 — sin código, configuración ni pruebas en esa fecha; el canal `log` se implementó y verificó el 03/10/2026, ver la subsección siguiente.)*

La entrega del email de verificación tendrá **separación explícita entre desarrollo y MVP/producción** (coherente con el principio de la sección 5: las diferencias entre entornos se resuelven por configuración).

**DEV — canal de email por log:**

- durante el desarrollo se utilizará un **canal de email por log**: se genera el token real, se persiste únicamente su hash (como actualmente), se construye el enlace real de verificación y **el enlace queda registrado en log** para poder recuperarlo durante las pruebas;
- el canal DEV **considera la entrega exitosa sin ejecutar un envío SMTP real** y, por lo tanto, **registra `last_sent_at`**;
- deben poder probarse realmente: **validación del token**, **transición `PEND_VERIF → PEND_PAGO`**, **reenvío**, **rotación del token**, **cooldown**, **`200 reenviado`** y **`429 cooldown_activo`**;
- este mecanismo es **exclusivamente infraestructura de desarrollo**; **no reemplaza** el envío real requerido para el MVP final;
- estado: **implementado y verificado el 03/10/2026** (ver la subsección siguiente): el canal `log` reemplazó el comportamiento anterior sin MTA (`mail()` sin transporte, enlace sólo en `error_log`, `last_sent_at` sin registrar y `502 error_envio_email` sin cooldown); hoy DEV sigue **sin transporte real de correo** (sin MTA/SMTP/APIs/credenciales), pero la entrega por canal `log` se registra en `error_log`, se considera exitosa, escribe `last_sent_at` y activa el cooldown de reenvío.

**MVP / producción — canal real de envío:**

- antes de considerar terminado el MVP del E-Commerce deberá existir un **canal real de envío de email**;
- el mecanismo concreto (SMTP, proveedor u otro canal apropiado) **se definirá e implementará cuando corresponda preparar el entorno final**;
- **el proyecto no debe considerarse terminado para MVP/producción si la verificación de email depende de leer manualmente un log**.

**Separación DEV / MVP:**

- la arquitectura y la configuración deben **distinguir el canal de desarrollo del canal real sin cambiar la lógica de negocio de verificación** (ni las responsabilidades del mecanismo genérico, ver sección 15);
- `last_sent_at` representa una **entrega exitosa según el canal configurado** y sigue siendo la base del cooldown de reenvío;
- canales y reglas relacionadas en las secciones 5, 6, 15, 15.4 y 20 de este documento.

### Implementación del canal de email `log` (03/10/2026)

Se implementó y verificó sobre el estado existente, sin modificar las decisiones de diseño cerradas (el canal se definió en la sección 6 y en "Decisión: canales de email por entorno", más arriba):

- **Archivos nuevos:** `src/mail/EmailSender.php` (contrato genérico de transporte: `enviar(string $para, string $asunto, string $cuerpo): bool`; `true` = entrega exitosa según el canal implementado, única condición para registrar `last_sent_at`), `src/mail/LogEmailSender.php` (canal `log`: registra destinatario, asunto y cuerpo — incluido el enlace de verificación — en el `error_log` vía `error_log()` de PHP y devuelve `true`) y `src/config/email.php` (devuelve `['channel' => 'log']`).
- **Modificados:** `public/index.php` (prefijo de autoloader `Incancelables\Mail\` → `src/mail/`, carga de `src/config/email.php` como **composition root**, instancia del canal configurado e inyección como `EmailSender` en `PedidoService`; un canal desconocido lanza `RuntimeException`: fallo explícito, sin degradación silenciosa) y `src/services/PedidoService.php` (recibe `EmailSender` por inyección; `enviarEmailVerificacion()` ya no invoca `mail()`: prepara el email y delega el transporte; además `PRIMER_PERIODO_MINUTOS` pasó de `10` a **`40`**, ajuste operativo no definitivo para que el `Pedido` no venza antes del primer reenvío con cooldown de 15 minutos — ver secciones 13.1 y 13.2).
- **Separación (sección 15):** el canal no toca la lógica de negocio de verificación — `EmailVerificationService` sigue **sin enviar emails**, `PedidoService` sólo conoce el contrato `EmailSender` (no la implementación) y el canal se elige por configuración, sin distinguir entornos en el código.
- **Pruebas (03/10/2026):** creación de `Pedido` → email registrado en `error_log` con el enlace y `last_sent_at` escrito (hoy sí se registra, a diferencia del estado anterior sin MTA); `reenvio` bajo cooldown → `429 cooldown_activo`; tras el cooldown → `200 reenviado` con rotación de token (el anterior → `400 token_invalido`); token nuevo → `200 confirmado` con `verified_at` y `PEND_VERIF → PEND_PAGO`; reutilización del mismo token → `200 confirmado` con `pedidos: []` (idempotencia, ver sección 15.4). Tras las pruebas la base de datos quedó en su estado baseline.
- **Sigue pendiente:** el canal **real** de envío (sección 20). El canal `log` es infraestructura de desarrollo y no reemplaza el envío real requerido para el MVP.

### Parametrización de la URL de verificación del checkout (05/10/2026)

Se parametrizó la URL destino del enlace de verificación del checkout sobre el estado existente, sin modificar las decisiones de diseño cerradas:

- **Archivo nuevo:** `src/config/frontend.php` (devuelve `['url_verificacion_checkout' => 'http://127.0.0.1:5500/pages/resultado.html']`, en el mismo estilo plano de `src/config/email.php`).
- **Modificados:** `public/index.php` (carga `src/config/frontend.php`, valida la clave — una configuración ausente o vacía lanza `RuntimeException`: fallo explícito, sin degradación silenciosa, en el mismo estilo que la validación del canal de email — y pasa el string ya resuelto a `new PedidoService(...)`) y `src/services/PedidoService.php` (se eliminó la constante `URL_BASE_VERIFICACION`; recibe la URL destino como último parámetro del constructor — sólo el string, no el array de configuración — y `enviarEmailVerificacion()` arma el enlace concatenándola con `'?flow=checkout&token=' . urlencode($token)`).
- **Alcance:** la URL deja de estar hardcodeada en `PedidoService`; `flow=checkout`, el token, el asunto y el cuerpo permanecen en el código del consumidor. El mecanismo de verificación, `EmailVerificationService`, `EmailSender`/`LogEmailSender`, el CORS y cualquier otro archivo quedan intactos; la newsletter y los demás consumidores no participan.
- **Configuración MVP directa:** un único valor, **sin selector DEV/PROD**, sin `.env` ni variables de entorno; el destino puede cambiarse al desplegar editando `src/config/frontend.php`, sin tocar la lógica de `PedidoService`.
- **Verificación (05/10/2026):** `php -l` OK en los tres archivos; `src/config/frontend.php` carga y devuelve el array esperado; prueba en memoria (sqlite `:memory:`, `EmailSender` stub que captura el cuerpo, invocación por reflexión de `enviarEmailVerificacion()`) → `enviar()` devuelve `true` y el enlace es exactamente `http://127.0.0.1:5500/pages/resultado.html?flow=checkout&token=...` con `urlencode` intacto (`a+b%2Bc%2Fd%3Fe%3Df%26g`); la constante `URL_BASE_VERIFICACION` no aparece en código ejecutable.

### Decisión: `Pedido`/`PedidoItem` como fuente de verdad para continuar la compra (05/10/2026)

**Decisión arquitectónica cerrada (05/10/2026):**

- **Antes de existir `Pedido`**, la fuente de la intención de compra es el **carrito frontend / `sessionStorage`** (sección 16): el backend no persiste carritos.
- **Una vez creado el `Pedido`**, `Pedido` + `PedidoItem` en el backend pasan a ser la **fuente de verdad para continuar la compra**; `sessionStorage` pertenece exclusivamente a la etapa previa a la creación del `Pedido` y su eventual pérdida no deshace la compra existente.
- **Después de verificar el email**, `resultado.html` mostrará "Email verificado" y el frontend navegará a la tienda **propagando el token**; la tienda recuperará desde el backend el `Pedido` y sus items mediante el nuevo endpoint `POST /api/email-verificaciones/pedidos` (contrato en la sección 15.4), sin depender del estado del `sessionStorage`.
- **El pago sigue usando `POST /api/pedidos/{id}/pago`** con su contrato actual y su validación actual de precio/stock: el endpoint **no se modificó**; los `items[]` enviados al pago provienen de la respuesta del backend (el backend revalida precios/stock como siempre).
- **Por qué un token y no `GET /api/pedidos/{id}`:** la autorización es el propio token de verificación (imposible de enumerar); no se expone un `Pedido` por id numérico (evita IDOR) y no se crea un segundo token (se reutiliza el existente, con su expiración y hash SHA-256).
- **Alcance de esta decisión:** sólo el soporte backend de consulta (`routes/api.php`, `EmailVerificationController::pedidos()`, `EmailVerificationService::consultarPorToken()` y `PedidoService::obtenerPedidosPorToken()`, todos de solo lectura). **El cambio frontend (`resultado.html` → `tienda.html`) todavía NO está implementado y queda documentado como decisión, no como hecho.**

**Implementación y verificación (05/10/2026):** `php -l` sin errores en los cuatro archivos modificados; prueba funcional vía HTTP **37/37 OK** (`test_continuar_compra.php`): token verificado → `200 resultado=ok` con `Pedido` + `items` de estructura exacta (`id`/`estado`/`items` y `producto_id`/`cantidad`/`precio_unitario`); token no verificado → `409 verificacion_pendiente`; token inexistente → `400 token_invalido`; token expirado → `400 token_expirado` (y restaurado el plazo → vuelve `409`); token faltante → `400 Falta el campo token`; tokens con formato inválido (numérico, vacío, >255) → `400 token inválido`; `verified_at` **inmutable** tras consultas repetidas (sin efectos secundarios, consulta idempotente); sólo devuelve los `Pedido` asociados a esa verificación (excluye pedidos de otra verificación y los preexistentes); regresión de endpoints relevantes: `POST /api/pedidos` (`201`), `POST /api/email-verificaciones/validar` (`200 confirmado` con `pedidos[]`), `GET /api/productos` (`200`), `POST /api/pedidos/{id}/pago` con `items[]` tomados de la respuesta de la nueva consulta → `200 aprobado` + `PAGADO`, y `simulacion=EN_PROCESO` → `400 simulacion inválida` intacto. Tras las pruebas la base de datos quedó en su estado baseline (conteos, `verified_at`/`expires_at`, estados y stocks verificados); sin cambios de esquema y sin commit al finalizar.

### Reemplazo de Pedido autorizado por token (06/10/2026)

**Decisión:** sustituir el `Pedido` activo de un proceso **ya verificado** a partir del propio token de verificación, reutilizando el mecanismo existente de aceptación de corrección en lugar de duplicar lógica.

- **Nuevo endpoint:** `POST /api/email-verificaciones/pedidos/reemplazo` (contrato completo en la sección 17.2, resumen en la 19.4). Autorizado por token: no expone ids numéricos (sin IDOR) y no depende del `sessionStorage`.
- **Archivos modificados:** `routes/api.php` (nueva ruta hacia `EmailVerificationController::reemplazar()`), `src/controllers/EmailVerificationController.php` (`reemplazar()` + mapa de errores `responderErrorDeReemplazo()`), `src/services/PedidoService.php` (`reemplazarPedidoPorToken()`: consulta por token → exige exactamente un `Pedido` activo → delega en `procesarPago(..., 'aceptar_correccion', ...)`).
- **Archivo nuevo:** `src/controllers/ItemsRequestValidator.php` (`ItemsRequestValidator::validar()`): la validación de `items[]` dejó de estar duplicada — `PedidoController::validarItemsCuerpo()` ahora delega en el mismo helper, con **idéntico** comportamiento observable (mismos mensajes `400`).
- **Sin cambios en:** DDL/estados/verificación/pagos (`pedidos.email_verificacion_id` sigue sin `UNIQUE`), `iniciarPago`, `PaymentValidationService`, `PagoRepository`, `crearPedido` y `obtenerPedidosPorToken`.
- **Comportamiento observado:** `200 reemplazado` con herencia de estado y `email_verificacion_id`, `200 correccion` sin sustituir ante precio distinto, sin nuevo registro en `emails_verificaciones`, sin email nuevo, sin pago iniciado y con **un solo** `Pedido` activo comprometiendo disponibilidad; pago posterior del nuevo `Pedido` con `POST /api/pedidos/{id}/pago` → `200 aprobado`; `409 pedido_no_activo` sin activos y `409 varios_pedidos_activos` ante dos activos.
- **Desviación informada (esperada):** un **reintento secuencial** mientras el reemplazo anterior sigue activo resuelve `200 reemplazado` (`B→C`), **no** `pedido_no_activo` — éste corresponde a "cero activos" (o a la carrera bloqueada con `FOR UPDATE`); la sustitución encadenada no produce doble compromiso.
- **Pruebas (06/10/2026):** `php -l` OK en los cinco archivos; suite funcional nueva `test_reemplazo_por_token.php` → **60/60 PASS** (validaciones de body, errores de token, happy path A→B, corrección comercial, reintento, recuperación por token, pago posterior, ambigüedad y restauración de baseline); regresión `test_continuar_compra.php` → **37/37 PASS** y `test_scope_en_proceso.php` → **36/36 OK**. Tras las pruebas la base de datos quedó en su estado baseline (conteos, stocks, estados y `emails_verificaciones` verificados). *(Nota de infraestructura de pruebas, preexistente e ajena a este cambio: los suites dependen del estado baseline del pedido 150, que el vencimiento perezoso expira con cualquier request; `test_scope_en_proceso.php` lo restaura a `PEND_PAGO` al finalizar, por lo que conviene correr `test_continuar_compra.php` con el 150 ya expirado y luego `test_scope_en_proceso.php`.)*
- **Sin commit** al finalizar (también quedó sin commit el trabajo previo del frontend en `D:\Incancelables Web`).