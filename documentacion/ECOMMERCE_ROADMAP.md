# ECOMMERCE_ROADMAP.md

## 1. Objetivo

Construir el e-commerce de Incancelables de forma incremental, comenzando por una primera versión funcional que permita realizar ventas reales y que pueda evolucionar posteriormente sin tener que rediseñar la arquitectura conceptual.

La evolución seguirá esta secuencia general:

```text
Diseño conceptual
        ↓
Definición del MVP
        ↓
Diseño técnico del MVP
        ↓
Implementación
        ↓
Pruebas
        ↓
Primera versión funcional y pública
        ↓
Evolución funcional
        ↓
Estabilización y auditoría
```

El objetivo inicial no es implementar todo el modelo conceptual, sino implementar el conjunto mínimo de funcionalidades necesarias para vender.

---

# 2. Estado actual

El diseño conceptual del e-commerce se encuentra definido en:

```text
ECOMMERCE_DESIGN.md
```

Ese documento define los conceptos y reglas generales del sistema.

Este roadmap define el orden en que esos conceptos serán llevados a una implementación funcional.

**Última consolidación documental: 28/09/2026** — ecosistema de tres proyectos; mecanismo genérico de verificación de email (agnóstico de contexto); estructura física de `emails_verificaciones` cerrada; tablas de pago `validaciones_pagos` y `validaciones_pagos_estados`; definición cerrada de `casos_revision`; auditoría del backend histórico Apps Script; pagos del MVP con `PaymentValidationService → PaymentProvider → DummyPaymentProvider` (integración real con Mercado Pago: POST-MVP); identidad de proveedor del MVP corregida a **`DUMMY`** (`DummyPaymentProvider → DUMMY`; `MERCADOPAGO` queda para `MercadoPagoProvider`).

**Auditoría de estado real: 28/09/2026** (solo lectura; detalle en la sección 2.1) — entorno DEV operativo y verificado; base de datos con las decisiones de modelo ya materializadas (15 tablas, 6 productos).

**Implementación incremental: 29/09/2026** — bloques implementados y verificados:

1. `GET /api/productos`;
2. `POST /api/pedidos` (inicio del checkout: primera ejecución de la validación comercial, con validación interna a transacción y bloqueo de filas de `productos`);
3. verificación de email (mecanismo genérico `EmailVerificationService` + endpoints `POST /api/email-verificaciones/validar` y `POST /api/email-verificaciones/reenvio`, con inicio de la verificación dentro de `POST /api/pedidos` y transición `PEND_VERIF → PEND_PAGO` a cargo de `PedidoService`; la entrega del email en DEV queda decisionada como **canal `log`** (02/10/2026; **implementado el 03/10/2026**, bloque 8) y el envío real del email sigue pendiente para el MVP).

**Implementación incremental: 30/09/2026** — bloques implementados y verificados sobre el estado anterior:

4. **rutas con `{id}`** en el router (parámetros de segmento pasados al Controller, `405` con `Allow` calculado por patrón, `400 pedido_id inválido`);
5. **vencimiento general del primer período (10 minutos en ese bloque; hoy el valor operativo es de 40 minutos**, ajuste no definitivo — ver el bloque 8 y la sección 2.1) para `PEND_VERIF`/`PEND_PAGO` (aplicación perezosa con `NOW()` de MariaDB; `409 pedido_vencido` y `NO_PAGADO` en cada operación sobre un `Pedido` vencido);
6. **`POST /api/pedidos/{id}/cancelar`** (cancelación voluntaria) y **`POST /api/pedidos/{id}/pago`** con `accion: "iniciar_pago"` (tercera validación) y `accion: "aceptar_correccion"` (revalidación completa + sustitución en una misma transacción, heredando estado y verificación de email);
7. **bloque de pagos con `DummyPaymentProvider`**: `PaymentValidationService`/`PaymentProvider`/`DummyPaymentProvider`, `PagoRepository`, creación de `Pago` + `PaymentValidationResult`, transición `PEND_PAGO → PROCESANDO_PAGO`, aplicación de `APROBADO`/`RECHAZADO` (con `EN_PROCESO` excluido del simulador desde el 05/10/2026: ver `ECOMMERCE_TECHNICAL_DESIGN.md`, sección 21) con afectación de stock, casos de revisión y parámetro `simulacion` (segundo período automatizado, webhook y `MercadoPagoProvider`: POST-MVP).

**Implementación incremental: 03/10/2026** — bloque implementado y verificado sobre el estado anterior:

8. **canal de email `log` de DEV** (decisión del 02/10/2026): contrato `EmailSender` + `LogEmailSender` en `src/mail/`, configuración `src/config/email.php` (`channel = log`) y composition root en `public/index.php` — `PedidoService` ya no invoca `mail()`: delega el transporte en `EmailSender` y la entrega se registra en `error_log`, se considera exitosa y escribe `last_sent_at`; pruebas: creación → log con enlace → `429 cooldown_activo` → `200 reenviado` con rotación → `200 confirmado` → `PEND_PAGO`. Ajuste operativo no definitivo del primer período de `10` a **`40`** minutos (`PedidoService::PRIMER_PERIODO_MINUTOS`, parámetro configurable) para que el `Pedido` no venza antes del primer reenvío con cooldown de 15 minutos.

**Implementación incremental: 05/10/2026** — bloque implementado y verificado sobre el estado anterior:

9. **URL de verificación del checkout parametrizada**: nuevo `src/config/frontend.php` (clave `url_verificacion_checkout` → `http://127.0.0.1:5500/pages/resultado.html`), cargado y validado en el composition root `public/index.php`, que inyecta el string resuelto en el constructor de `PedidoService`; desaparece la constante `URL_BASE_VERIFICACION` del servicio, el enlace se arma concatenando esa URL con `?flow=checkout&token=` + `urlencode($token)` (el `flow`, el token, el asunto y el cuerpo siguen en el código de `PedidoService`). Para el MVP la configuración es **directa, sin selector DEV/PROD ni `.env`**: el destino puede cambiarse al desplegar sin modificar `PedidoService`. Verificación: `php -l` OK en los tres archivos, la configuración carga correctamente y una prueba en memoria (sqlite `:memory:`, `EmailSender` stub) confirmó el enlace exacto con el token codificado;
10. **consulta de pedidos por token para continuar la compra**: nuevo endpoint `POST /api/email-verificaciones/pedidos` (cuerpo `{"token": "..."}`), **solo lectura**, que devuelve los `Pedido` asociados a la verificación con sus `PedidoItem` (`id`, `estado`, `items[]` con `producto_id`/`cantidad`/`precio_unitario`) — cambios acotados a `routes/api.php`, `EmailVerificationController::pedidos()`, `EmailVerificationService::consultarPorToken()` (sin invocar `validar()`: sin efectos secundarios) y `PedidoService::obtenerPedidosPorToken()` (reutiliza `buscarPorTokenHash`, `buscarTodosPorEmailVerificacion` y `buscarItemsPorPedido`; sin `PedidoItemRepository`); autorizada por el propio token (evita `GET /api/pedidos/{id}` y su IDOR), sin segundo token, sin cambios de esquema ni en `POST /api/pedidos/{id}/pago`. Verificación: `php -l` OK en los cuatro archivos y prueba funcional vía HTTP **37/37 OK** (200 con pedido+items, `409 verificacion_pendiente`, `400 token_invalido`, `400 token_expirado`, `400 Falta el campo token`, `400 token inválido`, `verified_at` inmutable, scope por verificación y regresión de pagos) con base de datos restaurada a baseline.

**Implementación incremental: 07/10/2026** — bloque implementado y verificado sobre el estado anterior:

11. **transporte de email real `ResendEmailSender` (consumo de API REST externa mediante PHP cURL)**: nuevo `src/mail/ResendEmailSender.php`, tercera implementación de `EmailSender`, que envía por la API HTTP de Resend (`POST https://api.resend.com/emails` con `Authorization: Bearer <api_key>`, `Content-Type: application/json`, `User-Agent: Incancelables-ECommerce/1.0` y cuerpo `from`/`to`/`subject`/`text`) usando **PHP cURL directamente, sin SDK, sin Guzzle y sin Composer**; `true` únicamente ante `2xx` + JSON válido con `id` string no vacío y `false` ante cualquier otro fallo (timeout, error de conexión/cURL, status no exitoso, JSON inválido o `2xx` sin `id`), **sin excepciones de transporte** y sin registrar en log la API key, el header `Authorization`, el cuerpo/enlace del email ni la respuesta cruda del proveedor (sólo categoría del fallo, `errno` y `status`); SSL con verificación de peer y host activa, sin `CURLOPT_VERBOSE` y con `CURLOPT_TIMEOUT` tomado de la configuración. `LogEmailSender` **sigue existiendo y sigue siendo el canal por defecto** (`channel = log`); `public/index.php` (composition root) selecciona `log` → `LogEmailSender` y `resend` → `ResendEmailSender`, y **falla explícitamente en el arranque** (`RuntimeException`) ante canal desconocido o configuración obligatoria ausente, **sin fallback silencioso de `resend` a `log`**. Configuración estructural trackeada en `src/config/email.php` (`channel` por defecto `log`, `resend.endpoint = https://api.resend.com/emails`, `resend.timeout = 10`) que incorpora el archivo local **no trackeado** `src/config/email.local.php` (aporta `api_key` y `from`, y opcionalmente `channel`; todos opcionales mientras el canal sea `log`) sólo si existe; `.gitignore` ignora `src/config/email.local.php` y `src/config/email.local.example.php` documenta la estructura con valores vacíos (jamás una credencial). El destinatario sigue siendo exactamente el email que `PedidoService` entrega a `EmailSender`; el remitente y la clave son configurables sin modificar código. **Sin tocar** `EmailSender`, `LogEmailSender`, `PedidoService`, `EmailVerificationService`, controllers, repositories, SQL, rutas ni frontend. Pruebas nuevas **trackeadas** en `tests/` (sin PHPUnit ni dependencias, con fixture HTTP local servido por `php -S` y la clave dummy `re_test_dummy`: sin Internet, sin cuota y sin envío real): `tests/test_resend_email_sender.php` → **29/29** (los 10 casos de respuesta exigidos + `{"id":""}` + timeout + contenido del request — método, `from`, `to`, `subject`, `text`, `Content-Type`, `Authorization` y `User-Agent` — + saneamiento de logs) y `tests/test_seleccion_canal_email.php` → **28/28** (configuración por defecto, contrato intacto, selección de canal, fallo explícito y ausencia de `email.local.php` al terminar). Regresión con canal `log` activo: `test_reemplazo_por_token` **60/60**, `test_continuar_compra` **37/37** y `test_scope_en_proceso` **36/36** (éste depende del estado baseline del pedido 150, nota preexistente en `ECOMMERCE_TECHNICAL_DESIGN.md`). **Sin commit** (cambios en el árbol de trabajo, pendientes de revisión).

**Decisión de alcance: 07/10/2026 — Resend como transporte académico, reemplazable.** Resend se incorpora como **transporte real para la versión académica** y como consumo de API externa con PHP cURL; **no está asumido como proveedor definitivo de producción**: al ser sólo una implementación más de `EmailSender` y al quedar `endpoint`, `from` y `api_key` en configuración, un proveedor futuro se reemplaza sin tocar la lógica de verificación ni de checkout (seguirán existiendo `LogEmailSender` para DEV/tests y `ResendEmailSender` como alternativa). **`channel = resend` NO quedó activado**: la conmutación, la carga de `email.local.php` con la key real, la definición del `from` aceptado por Resend, el envío E2E real y la verificación de recepción del token son pasos posteriores y por separado. La **newsletter queda fuera de este bloque** (pertenece a otro sistema/proyecto y no consume `EmailSender`).

**Decisión arquitectónica: 05/10/2026 — `Pedido`/`PedidoItem` como fuente de verdad para continuar la compra.** Antes de existir `Pedido`, la fuente de la intención de compra es el carrito frontend / `sessionStorage`; una vez creado, **`Pedido` + `PedidoItem` en el backend son la fuente de verdad para continuar la compra** y `sessionStorage` queda restringido a la etapa previa a su creación. Después de verificar el email, `resultado.html` mostrará "Email verificado" y el frontend navegará a la tienda propagando el token, con el que la tienda recuperará desde el backend el `Pedido` y sus items (`POST /api/email-verificaciones/pedidos`), **sin depender de `sessionStorage`**; el pago sigue usando `POST /api/pedidos/{id}/pago` con su contrato y su validación actual de precio/stock, sin modificarse. Soporte backend **implementado y verificado el 05/10/2026**; el cambio frontend (`resultado.html` → `tienda.html`) **todavía NO está implementado** (decisión documentada, no hecho). Detalle: `ECOMMERCE_TECHNICAL_DESIGN.md`, sección 21 y `ECOMMERCE_DESIGN.md`, sección "Estado actual". **Sin commit** (cambios en el árbol de trabajo, pendientes de revisión).

**Decisión de alcance: 05/10/2026** — `EN_PROCESO` queda **fuera del simulador del MVP**. El parámetro `simulacion` de `POST /api/pedidos/{id}/pago` admite únicamente `APROBADO` (por defecto) y `RECHAZADO`; `simulacion=EN_PROCESO` se rechaza con `400 {"error":"simulacion inválida"}` en la validación existente del endpoint, sin crear `Pago` ni transicionar el `Pedido`. Cambio acotado a dos líneas de validación: `PedidoController` (lista de valores admitidos) y `PedidoService::RESULTADOS_SIMULACION` — ambos con `['APROBADO', 'RECHAZADO']` —, sin tocar `APROBADO`/`RECHAZADO`/`CANCELADO`, el modelo, los catálogos de estados, `PaymentProvider`, `DummyPaymentProvider` ni `PaymentValidationService`. Los estados `EN_PROCESO`/`PROCESANDO_PAGO` **se conservan en el modelo para la evolución futura** (webhook, pagos asincrónicos y conciliación: todos POST-MVP); en consecuencia, **la confirmación de resultados `EN_PROCESO` no es un pendiente del MVP** y el flujo ejecutable no puede dejar un `Pedido` en `PROCESANDO_PAGO` sin mecanismo de resolución. Verificación: `php -l` OK y prueba funcional vía HTTP **36/36 OK** con base de datos restaurada a baseline. Detalle: `ECOMMERCE_TECHNICAL_DESIGN.md`, sección 21 (decisión del 05/10/2026) y `ECOMMERCE_DESIGN.md`, sección "Estado actual". **Sin commit** (cambios en el árbol de trabajo, pendientes de revisión).

Arquitectura en uso: `Route → Controller → Service → Repository → MariaDB`, con el SQL únicamente en Repositories. `ProductoService` no calcula disponibilidad; `PedidoService` coordina la disponibilidad, la validación comercial, el vencimiento, la cancelación y el flujo completo de pago (con `PaymentValidationService` como única frontera hacia el proveedor), y consume el mecanismo genérico de verificación de email. El resto de la capa de aplicación del MVP (recuperación de pedidos pendientes y administración) sigue sin implementar (ver sección 2.1).

El diseño técnico no debe intentar implementar desde el comienzo todas las funcionalidades futuras.

---

## 2.1. Estado de implementación

*(Auditoría de solo lectura el 28/09/2026; actualización de estado el 29/09/2026 tras completar y verificar los tres primeros bloques: `GET /api/productos`, `POST /api/pedidos` y verificación de email; actualización del 30/09/2026 tras el bloque de rutas `{id}` + vencimiento del primer período + `POST /api/pedidos/{id}/cancelar` + `POST /api/pedidos/{id}/pago` hasta la tercera validación, y del mismo 30/09/2026 tras el bloque de pagos con `DummyPaymentProvider`; actualización del 03/10/2026 tras el canal de email `log` de DEV y el ajuste operativo del primer período a 40 minutos; actualización del 06/10/2026 tras el reemplazo de `Pedido` autorizado por token.)*

**Infraestructura (operativa):**

- estructura inicial del proyecto PHP;
- entorno local XAMPP;
- Apache;
- **PHP 8.2.12** con `pdo_mysql`;
- **MariaDB 10.4.32**;
- Virtual Host local `incancelables-ecommerce.local` → `public/` (entrada en `hosts`, respuesta HTTP 200 verificada);
- base de datos `incancelables_ecommerce`;
- conexión PHP → MariaDB verificada.

**Base de datos (15 tablas, decisiones de modelo materializadas):**

- tablas iniciales (`productos`, `pedidos`, `pedidos_items`, `pedidos_estados`, `pagos`, `emails_verificaciones`, `proveedores`, `shows`, `shows_estados`);
- `validaciones_pagos` con `UNIQUE(pago_id)` y catálogo `validaciones_pagos_estados` (renombramiento de `pagos_estados`);
- `pagos` ajustado: ya no tiene `estado_id` (`id`, `pedido_id`, `importe`, `created_at`);
- `casos_revision` y sus catálogos `casos_revision_tipos` / `casos_revision_estados` (con `orden` y `activo`), cargados;
- `pedidos_estados` con los **6 estados definitivos** del `Pedido`;
- `productos_imagenes`;
- estructura de `emails_verificaciones` (agnóstica de contexto, índice no único sobre `email`; incluye la columna `last_sent_at DATETIME NULL`, única ampliación de esquema de este bloque, para el cooldown de reenvío);
- catálogo `proveedores` con `MERCADOPAGO` y `DUMMY`;
- relaciones/índices verificados; sin `reservas`, sin `stock_comprometido`, sin tablas de carrito y sin `pedidos.importe_total`;
- **6 productos** cargados (semilla inicial de la tienda).

**Código PHP (bloques implementados y verificados el 29/09/2026):**

- `public/index.php`: front controller / router (autoload manual con `spl_autoload_register`, dispatch de rutas y respuestas `404`, `405` y `500` en JSON, errores en `error_log`);
- `public/.htaccess`: reescritura de URLs hacia `index.php` (`RewriteEngine On`);
- `routes/api.php`: definición de rutas (`GET /api/productos`, `POST /api/pedidos`, `POST /api/pedidos/{id}/cancelar`, `POST /api/pedidos/{id}/pago`, `POST /api/email-verificaciones/validar`, `POST /api/email-verificaciones/reenvio`, `POST /api/email-verificaciones/pedidos` — esta última desde el 05/10/2026 —, `POST /api/email-verificaciones/pedidos/reemplazo` — desde el 06/10/2026);
- `src/controllers/`: `ProductoController.php`, `PedidoController.php` (`crear`, `cancelar`, `pago`), `EmailVerificationController.php` (`validar`, `reenvio`, `pedidos`, `reemplazar` — esta última desde el 06/10/2026) e `ItemsRequestValidator.php` (**nuevo desde el 06/10/2026**: helper compartido de validación de `items[]`, usado por `PedidoController` y `EmailVerificationController`);
- `src/services/`: `ProductoService.php`, `PedidoService.php` (coordina disponibilidad y validación comercial — centralizada en `PedidoService::validarCarrito()` —, aplica el vencimiento del primer período, cancela Pedidos, ejecuta la tercera validación, el inicio real de pago con fase A/B, la aceptación de corrección y el reemplazo por token (`reemplazarPedidoPorToken()`, autorizado por token de verificación y delegado en `procesarPago(..., 'aceptar_correccion', ...)`, desde el 06/10/2026) y la aplicación de los resultados del proveedor — `aplicarAprobado()`/`aplicarRechazado()` —, e inicia/confirma/reenvía la verificación de email como consumidor del mecanismo — componiendo el email y delegando su transporte en `EmailSender` (canal `log` desde el 03/10/2026)), `EmailVerificationService.php` (mecanismo genérico, agnóstico de contexto, sin envío de emails) y — **desde el 30/09/2026** — `PaymentValidationService.php` (única frontera con el proveedor: `registrarIntento()`/`procesarIntento()`);
- `src/repositories/`: `ProductoRepository.php` (incluye `bloquearPorIds()` y `descontarStock()`), `PedidoRepository.php` (aquí vive todo el SQL, incluido `SELECT ... FOR UPDATE` de `productos` y de `pedidos`, más `vencerPrimerPeriodoVencidos()`, `buscarItemsPorPedido()`, `cantidadesComprometidasPorProducto()` con exclusión opcional de un `Pedido` y `insertarPedido()` con `email_verificacion_id` heredado), `EmailVerificationRepository.php` y — **desde el 30/09/2026** — `PagoRepository.php` (pagos, `validaciones_pagos`, proveedores y casos de revisión);
- `src/config/database.php`: conexión PDO actual con configuración **hardcodeada**;
- `src/config/email.php`: configuración específica del canal de email (`channel = log` por defecto, más `resend.endpoint` y `resend.timeout` desde el 07/10/2026) — **nuevo desde el 03/10/2026**; `src/mail/EmailSender.php`, `src/mail/LogEmailSender.php` (contrato genérico de transporte de email y canal `log` del DEV/MVP, **nuevos desde el 03/10/2026**) y `src/mail/ResendEmailSender.php` (canal `resend` vía API HTTP de Resend con PHP cURL, **nuevo desde el 07/10/2026**); `src/config/email.local.php` (**no trackeado**, ignorado por `.gitignore`: `api_key` y `from`) y `src/config/email.local.example.php` (ejemplo trackeado con valores vacíos) — **desde el 07/10/2026**;
- `src/config/frontend.php`: configuración del destino del enlace de verificación del checkout (`url_verificacion_checkout`) — **nuevo desde el 05/10/2026**: la URL deja de estar hardcodeada en `PedidoService` y llega por inyección desde `public/index.php` (composition root); para el MVP es una configuración directa, sin selector DEV/PROD;
- `src/models/PaymentValidationResult.php`, `src/payments/PaymentProvider.php`, `src/payments/DummyPaymentProvider.php` y `src/payments/PaymentProviderException.php` (nuevos desde el 30/09/2026); `src/database/` y `storage/logs/` existen pero siguen **vacíos**;
- sin `composer.json`/autoloader, sin `.env`, sin migraciones `.sql`; tests funcionales propios (sin framework ni dependencias) en `tests/` desde el 07/10/2026 — `tests/fixture_resend.php`, `tests/test_resend_email_sender.php` y `tests/test_seleccion_canal_email.php`; las suites históricas de continuación/reemplazo/scope siguen fuera del repositorio (no trackeadas).

**Endpoints implementados y verificados:**

- `GET /api/productos` — catálogo con `disponibilidad` calculada en caliente;
- `POST /api/pedidos` — inicio del checkout: primera ejecución de la validación comercial; validación completa **dentro** de la transacción de MariaDB, con bloqueo de las filas de `productos` (`SELECT ... FOR UPDATE` en orden ascendente de `producto_id`) para impedir que dos checkouts simultáneos creen Pedidos sin disponibilidad; una vez confirmada la transacción inicia la verificación de email del checkout (fallo de envío ≠ rollback del `Pedido`); ver detalle en la sección 11;
- `POST /api/email-verificaciones/validar` — confirmación de un token de verificación: `200` confirmado con `pedidos[]` (transición N:1 a cargo del consumidor: todos los `Pedido` asociados en `PEND_VERIF` vigentes → `PEND_PAGO`, los `PEND_VERIF` vencidos → `NO_PAGADO`, demás estados intactos; `pedidos[]` sólo con los vigentes habilitados, `[]` si no hay ninguno), `400 token_invalido`, `400 token_expirado`, `400` por campos faltantes;
- `POST /api/email-verificaciones/reenvio` — reenvío para un `pedido_id` en `PEND_VERIF`: `200` reenviado (token nuevo; el anterior queda inválido), `429 cooldown_activo` con `segundos_restantes`, `409 verificacion_ya_verificada`, `409 pedido_no_pendiente_de_verificacion`, `409 pedido_vencido` (30/09/2026), `404 pedido_inexistente`, `502 error_envio_email`, `400` por campos faltantes;
- `POST /api/email-verificaciones/pedidos` — consulta **solo lectura** por token (05/10/2026): `200 {"resultado":"ok","pedidos":[{"id":N,"estado":"...","items":[{"producto_id":N,"cantidad":N,"precio_unitario":N},...]},...]}` (todos los `Pedido` asociados a la verificación, autorizados por el token; sin efectos: no marca `verified_at` ni transiciona estados), `400 token_invalido`, `400 token_expirado`, `409 verificacion_pendiente`, `400 "Falta el campo token"`, `400 token inválido` (no string, vacío o >255); no existe `GET /api/pedidos/{id}`;
- `POST /api/email-verificaciones/pedidos/reemplazo` — reemplazo del `Pedido` activo autorizado por token (06/10/2026): `{"token":"...","items[]":[...]}` con las mismas reglas de `items[]` que `POST /api/pedidos` → `200 reemplazado` (`pedido_anterior` en `NO_PAGADO` + nuevo `Pedido` heredando estado y `email_verificacion_id` en una misma transacción) o `200 correccion` sin sustituir; **sin** nueva `emails_verificaciones`, **sin** email nuevo y **sin** iniciar pago (el nuevo `Pedido` se paga con `POST /api/pedidos/{id}/pago`); `400` `token_invalido` / `token_expirado` / `Falta el campo token` / `token inválido` / `Falta el campo items` / `El carrito está vacío` / estructura de ítem, `409 verificacion_pendiente`, `409 pedido_no_activo` (ningún activo), `409 varios_pedidos_activos` (más de un activo: no sustituye ninguno); implementado reutilizando `PedidoService::procesarPago(..., 'aceptar_correccion', ...)` (sección 17.2 de `ECOMMERCE_TECHNICAL_DESIGN.md`);
- `POST /api/pedidos/{id}/cancelar` — cancelación voluntaria del checkout (30/09/2026): `200` `{"resultado":"cancelado","pedido":{"id":N,"estado":"NO_PAGADO"}}`, `404 pedido_inexistente`, `409 pedido_vencido`, `409 pedido_no_activo`, `400 pedido_id inválido`, `405` con `Allow`; `PedidoItem` intactos y liberación de disponibilidad;
- `POST /api/pedidos/{id}/pago` — tercera validación, inicio real de pago y corrección con `Pedido` existente (30/09/2026, con bloque de pagos): `accion: "iniciar_pago"` (exige `proveedor_id`, `items[]`; opcional `simulacion`: `APROBADO` por defecto o `RECHAZADO` — desde el 05/10/2026 esos son los dos únicos valores admitidos; `EN_PROCESO` → `400 simulacion inválida`) → `200 aprobado` (`pedido` en `PAGADO`, stock descontado una sola vez) | `200 rechazado` (`PEND_PAGO` o `NO_PAGADO` al máximo de 3 intentos) | `200 en_proceso` (sólo ante un error técnico del proveedor: permanece en `PROCESANDO_PAGO`; inalcanzable con `DUMMY` + `simulacion` admitida) | `200 correccion` con motivo `carrito_desactualizado` si `items[]` difiere de los items persistidos (o los motivos comerciales), con `carrito_corregido`, `disponibilidad`, `motivos`, `catalogo` activo completo y `pedido` intacto; `409` con `maximo_intentos_alcanzado` (con `intentos`/`maximo_intentos`), `pago_ya_aprobado`, `pedido_no_procesando`, `pedido_vencido`, `pedido_no_activo`, `pedido_no_pendiente_de_pago`; `400` con `proveedor_faltante`/`proveedor_inexistente`/`proveedor_no_soportado`/`simulacion_invalida` y por estructura/`accion`/`items`; `404 pedido_inexistente`; `405` con `Allow`. `accion: "aceptar_correccion"` con `items[]` → `200 reemplazado` (anterior `NO_PAGADO` + nuevo `Pedido` heredando estado y `email_verificacion_id` en una misma transacción) o `correccion` sin sustituir; **desaparece la respuesta `{"resultado":"validado"}`** (cambio de contrato respecto de la primera versión del 30/09/2026).

> **No existe** `POST /api/email-verificaciones` (solicitud de verificación): esa operación está integrada en `POST /api/pedidos`. No hay un endpoint dedicado de solicitud. Tampoco existe `GET /api/pedidos/{id}`: la consulta de continuación de compra se autoriza con el token de verificación (`POST /api/email-verificaciones/pedidos`), no con un id enumerable.

**Todavía no están implementados** (en este proyecto backend, salvo lo indicado):

- ~~canal de email `log` de DEV~~ **RESUELTO e implementado el 03/10/2026** (decisión del 02/10/2026, ver la decisión más abajo en esta sección): `EmailSender`/`LogEmailSender` en `src/mail/`, `src/config/email.php` y selección/inyección en `public/index.php`; el enlace queda registrado en el `error_log` de Apache, la entrega se considera exitosa y `last_sent_at` se registra, por lo que `POST /api/email-verificaciones/reenvio` opera con su cooldown (`200 reenviado` / `429 cooldown_activo`); sin SMTP/APIs/credenciales, `mail()` ya no se invoca;
- canal de envío real del email de verificación: **requisito pendiente antes de dar por terminado el MVP** — el transporte `ResendEmailSender` existe desde el 07/10/2026 pero **no está activado** (`channel = log`), falta cargar `email.local.php` con la key real, definir el `from`, activar `channel = resend` y verificar un envío E2E real;
- recuperación de pedidos pendientes (el paso `PEND_VERIF → PEND_PAGO` por verificación de email, la cancelación voluntaria, el vencimiento del primer período, la tercera validación con corrección/aceptación y el **inicio real de pago con `DummyPaymentProvider`** — creación de `Pago`, `PROCESANDO_PAGO` y aplicación de `APROBADO`/`RECHAZADO` (con `EN_PROCESO` excluido del simulador desde el 05/10/2026, ver `ECOMMERCE_TECHNICAL_DESIGN.md`, sección 21) con afectación de stock — **están implementados** desde el 30/09/2026);
- Store y carrito (corresponden al frontend, `D:\Incancelables Web`; el backend no los persiste);
- administración;
- configuración por entorno (`.env`) — la elección del canal de email por entorno ya se resuelve por configuración desde el 03/10/2026 (`src/config/email.php`, con canal `log` activo) y, **desde el 07/10/2026**, el secreto y el remitente del canal real viven en el archivo local ignorado `src/config/email.local.php` (mecanismo limitado al email, no hay sistema general de secretos); falta el `.env`/configuración general por entorno.

**Decisión (02/10/2026) — canales de email por entorno:** la entrega del email de verificación queda con separación explícita entre desarrollo y MVP/producción.

- **DEV — canal de email por log:** durante el desarrollo se utiliza un canal de log que genera el token real, persiste únicamente su hash, construye el enlace real de verificación y **lo registra en log** para poder recuperarlo durante las pruebas; el canal DEV **considera la entrega exitosa sin ejecutar un envío SMTP real** y, por lo tanto, **registra `last_sent_at`**. Debe permitir probar realmente: validación del token, transición `PEND_VERIF → PEND_PAGO`, reenvío, rotación del token, cooldown, `200 reenviado` y `429 cooldown_activo`. Es **exclusivamente infraestructura de desarrollo** y **no reemplaza** el envío real requerido para el MVP final. *(Estado actual: implementado y verificado el 03/10/2026 — `EmailSender`/`LogEmailSender`, `src/config/email.php` y composition root en `public/index.php`; el enlace queda en el `error_log` de Apache, la entrega se considera exitosa y `last_sent_at` se registra. DEV sigue sin transporte real de correo — sin MTA, SMTP, APIs ni credenciales; `mail()` ya no se invoca.)*
- **MVP / producción — canal real de envío:** antes de considerar terminado el MVP del E-Commerce deberá existir un **canal real de envío de email**. El mecanismo concreto (SMTP, proveedor u otro canal apropiado) se definirá e implementará cuando corresponda preparar el entorno final. **El proyecto no debe considerarse terminado para MVP/producción si la verificación de email depende de leer manualmente un log.** *(Avance 07/10/2026: existe `ResendEmailSender` — API de Resend vía PHP cURL, canal seleccionable con `channel = resend` en `src/config/email.php` y secreto/remitente en `src/config/email.local.php` —, **aún no activado**; Resend se usa como proveedor académico y **reemplazable**, y un proveedor distinto en producción no exige cambiar código, sólo configuración.)*
- **Configuración, no lógica de negocio:** la arquitectura y la configuración deben distinguir el canal de desarrollo del canal real **sin cambiar la lógica de negocio de verificación**; `last_sent_at` representa una entrega exitosa **según el canal configurado**.

> *(Actualización documental del 02/10/2026; el canal `log` se implementó y verificó el 03/10/2026, ver el bloque 8 y la sección 2.1.)*

> Los cambios de modelo se aplicaron directamente sobre MariaDB: el proyecto **no contiene archivos `.sql` ni migraciones versionadas**. Esta auditoría fue de solo lectura y **no modificó código, base de datos, configuración ni dependencias**.

El desarrollo continuará de forma incremental.

---

# 3. Principio fundamental del desarrollo

La primera versión debe ser:

- pequeña en funcionalidades;
- funcional;
- capaz de realizar ventas reales;
- demostrable;
- compatible con la evolución futura del sistema.

No se debe implementar complejidad que todavía no sea necesaria para vender.

Sin embargo, las decisiones técnicas del MVP no deben bloquear conceptos ya definidos en `ECOMMERCE_DESIGN.md`.

### Ecosistema de proyectos

El ecosistema Incancelables se organiza en tres proyectos con responsabilidades distintas:

- `D:\Incancelables Web` → **frontend** (HTML/CSS/JS) del sitio principal de Incancelables.
- `D:\Incancelables Backend` → **backend histórico** en Google Apps Script (newsletter → Google Sheets); se lo utiliza únicamente como **referencia** de lógica ya existente, sin migrar sus datos ni su modelo.
- `D:\Incancelables E-Commerce` → **backend** (PHP + MariaDB) del e-commerce: API, lógica de negocio y base de datos.

El e-commerce se separa en frontend (`D:\Incancelables Web`) y backend (`D:\Incancelables E-Commerce`). El frontend no contiene reglas de negocio: las reglas viven en el backend.

La comunicación entre frontend y backend del e-commerce se realiza mediante la API del e-commerce.

**Convivencia en el MVP:** el newsletter continúa en el ecosistema histórico (Apps Script → Google Sheets), el e-commerce opera en PHP → MariaDB y **no existe integración entre ambos ecosistemas**. La migración del newsletter a PHP/MariaDB (y su uso posterior del mecanismo genérico de verificación de email) es **POST-MVP**.

---

# 4. MVP

El MVP tendrá como objetivo permitir una compra completa.

El flujo principal será:

```text
Store
  ↓
Producto
  ↓
Carrito
  ↓
Checkout (se crea el Pedido)
  ↓
Verificación de email
  ↓
Pago (proveedor simulado: DummyPaymentProvider)
  ↓
Confirmación
```

El flujo de compra de entradas desde un show pertenece a una **evolución futura** (ver sección 8) y no forma parte del MVP.

El carrito único está preparado para que, cuando se incorporen entradas (evolución futura), distintos tipos de productos puedan convivir en el mismo carrito.

---

# 5. Productos del MVP

El concepto fundamental será:

```text
Producto
```

Todos los productos son entidades genéricas `Producto`.

No existe un `tipo_producto` para distinguir merchandising, música, entradas, etc.

Los productos podrán representar diferentes cosas que se comercializan:

```text
Remera
Gorra
CD
Vinilo
```

Conceptualmente, un producto tiene como mínimo:

```text
Producto
├── nombre
├── descripción
├── precio
├── imagen/es
├── activo / inactivo
└── stock físico
```

Un producto puede tener, opcionalmente, una asociación con un `Show` (`show_id`):

- `show_id = NULL` → producto general del e-commerce;
- `show_id` con valor → producto asociado a un show (por ejemplo, una entrada).

La utilización de productos asociados a shows/entradas pertenece a una **evolución futura** y no se implementa en el MVP.

---

# 6. Stock y disponibilidad

El stock del producto representa el **stock físico real**.

La disponibilidad se calcula **en caliente**:

```text
disponible = stock físico
             − cantidades de PedidoItem pertenecientes a Pedidos activos
```

Los estados de `Pedido` que afectan disponibilidad son:

```text
PEND_VERIF
PEND_PAGO
PROCESANDO_PAGO
```

Los estados que NO afectan disponibilidad son:

```text
PAGADO
NO_PAGADO
```

- **NO** existe una entidad `Reserva` en el MVP.
- **NO** existe un campo `stock_comprometido`.
- **NO** existe una tabla de "reservas", "compromisos" o equivalente.
- La disponibilidad **NO es un dato persistente**: se calcula puntualmente bajo demanda.
- El cálculo lo coordina `PedidoService` con `ProductoRepository` y `PedidoRepository`; `Producto` no conoce `Pedido` y no existen `AvailabilityService` ni `StockService`.
- Las cantidades comprometidas se calculan dinámicamente a partir de los `PedidoItem` de los Pedidos activos.
- Agregar un producto al carrito **NO modifica la disponibilidad global**: el carrito no bloquea stock.
- La disponibilidad que ve el frontend es informativa/operativa para la interfaz; **el frontend nunca es autoridad sobre el stock**.
- El stock físico solo se descuenta cuando el pago queda definitivamente **aprobado** y la afectación de stock se completa.
- El MVP compra sobre stock físico disponible. No existe venta sin stock ni venta bajo demanda.
- El mecanismo técnico de concurrencia para la creación del `Pedido` ya está implementado: validación comercial completa dentro de una transacción de MariaDB con bloqueo de las filas de `productos` (`SELECT ... FOR UPDATE`); la concurrencia de la afectación de stock (pago aprobado) queda para su etapa de implementación.

Ver detalle del ciclo de vida en `ECOMMERCE_DESIGN.md` (sección 8).

---

# 7. Entradas — evolución futura

Las entradas serán productos.

Una entrada tendrá una asociación con un show:

```text
Producto
    ↓
Entrada
    ↓
Show
```

La capacidad disponible para vender entradas se representará mediante el stock del producto.

```text
Entrada para Show X
stock = 100
```

Cuando el stock llegue a cero, la entrada queda agotada.

Esta funcionalidad **no forma parte del MVP** y se incorporará en una etapa posterior.

---

# 8. Diferencia entre una entrada y otros productos — evolución futura

La principal diferencia conceptual entre una entrada y un producto de merchandising no será el mecanismo de venta.

La diferencia estará principalmente en su asociación y ubicación comercial:

```text
Producto
   +
Show asociado
```

La entrada se descubrirá principalmente desde:

```text
Próximo show
    ↓
Comprar entrada
```

La Store estará orientada principalmente a:

```text
Merchandising
Música
Otros productos
```

Esta diferencia pertenece a una **evolución futura** y no condiciona el MVP.

---

# 9. Próximo show — evolución futura

Cuando exista un próximo show con venta de entradas habilitada, el sitio deberá poder mostrar:

```text
Próximo show
Comprar entrada
```

El enlace permitirá acceder directamente al producto correspondiente a la entrada.

Si no existe un próximo show con entradas disponibles para la venta, no deberá mostrarse la opción de compra de entrada.

Esta funcionalidad pertenece a una **evolución futura** y no forma parte del MVP.

---

# 10. Carrito unificado

Existirá un único carrito.

El carrito podrá contener diferentes productos:

```text
1 × Remera
1 × Gorra
```

No habrá un carrito separado para entradas y otro para la store.

Cuando se incorporen entradas (evolución futura), podrán convivir con los productos de tienda en el mismo carrito.

El mismo checkout deberá poder procesarlos.

### El carrito es exclusivamente frontend

- El carrito es una **lista de intención de compra**: no es una transacción y no representa un `Pedido`.
- Se mantiene en `sessionStorage`: sobrevive a recargas en la misma sesión/pestaña y puede perderse al cerrar la pestaña o el navegador.
- El backend **NO persiste** el carrito. Quedan fuera del MVP el carrito persistente entre dispositivos, los múltiples carritos recuperables y la recuperación sofisticada de carritos abandonados.
- No existe `checkout_id`: el checkout es un **único proceso**, aunque durante el mismo puedan producirse correcciones y crearse nuevos `Pedido`.
- El usuario puede modificar libremente el carrito.
- El carrito no reserva ni bloquea stock.
- Una vez creado un `Pedido`, el proceso de compra continúa independientemente del estado posterior del carrito: el carrito puede desaparecer, vaciarse o cambiar y el `Pedido` existente continúa su propio ciclo de vida.

---

# 11. Checkout

El checkout deberá recopilar la información mínima necesaria para realizar la compra.

El MVP utilizará compra como invitado.

No será obligatorio crear una cuenta.

El flujo será:

```text
Carrito
  ↓
Inicio de checkout
  ↓ (validación comercial, incluye disponibilidad)
Pedido creado en PEND_VERIF + PedidoItems
  ↓
Datos del comprador
  ↓
Email
  ↓
Verificación → PEND_PAGO
  ↓ (validación comercial antes de iniciar el pago)
Pago → PROCESANDO_PAGO
  ↓
PAGADO
```

### Validación comercial al iniciar checkout

Al iniciar checkout el frontend envía el carrito al backend y el backend realiza la **validación comercial**, que incluye como mínimo:

- existencia del producto;
- producto activo / disponible para venta;
- cantidades válidas;
- disponibilidad actual;
- precio actual;
- demás reglas comerciales vigentes.

Si todo es válido: se crea el `Pedido` (en `PEND_VERIF`) y sus `PedidoItem`; desde ese momento sus cantidades afectan la disponibilidad global.

Si la validación encuentra diferencias: NO se crea un `Pedido` válido para la operación corregida y el backend devuelve el carrito corregido, la disponibilidad actualizada y el motivo de cada corrección.

La creación del `Pedido` ocurre ANTES de la verificación del email y ANTES del pago. No se realiza una nueva comprobación de stock por la verificación del email.

#### Endpoint implementado: `POST /api/pedidos`

*(Implementado y verificado el 29/09/2026.)*

- corresponde al **inicio del checkout** y realiza la **primera ejecución** de la validación comercial; en este momento **todavía no existe un `Pedido` previo** (tampoco existe un `Pedido` al que aplicar la corrección: ésta sólo devuelve la propuesta corregida);
- todo ocurre dentro de una **transacción de MariaDB**: se bloquean las filas de `productos` del carrito (`SELECT ... FOR UPDATE`, en orden ascendente de `producto_id`) y recién dentro de la transacción se recalculan cantidades comprometidas y disponibilidad y se validan existencia, activo, cantidades, precio y disponibilidad; ante cualquier discrepancia se hace `ROLLBACK` (sin escrituras) y se responde corrección; si todo es válido se insertan `Pedido` + `PedidoItem` y se hace `COMMIT`;
- **request** (JSON): `email`, `nombre` y `apellido` (obligatorios) y `items[]`, donde cada item requiere obligatoriamente `producto_id`, `cantidad` y **`precio_unitario`** (`precio_unitario` **NO** es opcional: si falta, responde `400` y no se crea ningún `Pedido` ni `PedidoItem`);
- **precios**: el precio persistido en `pedidos_items.precio_unitario` proviene **siempre de la base de datos** (`productos.precio`), nunca del request; el `precio_unitario` recibido sólo se usa para detectar `precio_cambiado`;
- **respuestas**: `201` con `{"resultado":"creado","pedido":{"id":N,"estado":"PEND_VERIF"}}`; `200` con `{"resultado":"correccion","carrito_corregido":[...],"disponibilidad":[...],"motivos":[...]}` cuando hay diferencias (motivos: `producto_inexistente`, `producto_no_disponible`, `precio_cambiado`, `disponibilidad_insuficiente`); `400` con `{"error":"..."}` ante errores estructurales del request; `404` y `405` (con `Allow`) según la ruta/método;
- items repetidos del mismo `producto_id` se agrupan sumando cantidades;
- la concurrencia está verificada: dos checkouts simultáneos por el stock completo de un producto generan **un solo** `201` y una corrección, sin deadlocks.

### Tercera comprobación antes de iniciar el pago

**Implementado el 30/09/2026** (y completado el mismo día con el bloque de pagos, más abajo):

- `POST /api/pedidos/{id}/pago` con `accion: "iniciar_pago"` ejecuta la **tercera validación comercial completa** sobre el carrito recibido en el request (`items[]`, obligatorio), que debe coincidir con los `PedidoItem` persistidos en el `Pedido` (carrito inmutable; diferencias → `200 correccion` con motivo `carrito_desactualizado`, carrito corregido = persistido, sin iniciar el pago); si todo es válido, hay intentos disponibles y el proveedor existe, **crea el `Pago` con su `PaymentValidationResult` (`EN_PROCESO`) y pasa el `Pedido` a `PROCESANDO_PAGO`** y procesa el resultado del proveedor en una segunda transacción (ver "Bloque de pagos" más abajo);
- errores: `400` (cuerpo/`accion`/`items`/`proveedor_id`/`simulacion` inválidos), `404 pedido_inexistente`, `409 pedido_vencido`, `409 pedido_no_activo` (estado fuera de `PEND_VERIF`/`PEND_PAGO`), `409 pedido_no_pendiente_de_pago`, `409 maximo_intentos_alcanzado`, `409 pago_ya_aprobado`, `409 pedido_no_procesando`, `405` con `Allow`;
- la validación excluye al propio `Pedido` del cálculo de disponibilidad (sus propios items no se restan dos veces).

`POST /api/pedidos/{id}/pago` recibe `proveedor_id` y `items[]`: **el carrito completo vuelve a enviarse al backend** al momento de iniciar el pago (p. ej. `{ "proveedor_id": 1, "items": [ { "producto_id": 1, "cantidad": 2, "precio_unitario": 1000 } ] }`). El backend **NO confía** en esos valores: el carrito recibido es siempre una propuesta del frontend y debe validarse nuevamente.

Antes de iniciar el pago se realiza nuevamente la **validación comercial completa** (stock, precio, disponibilidad y demás reglas comerciales). Si encuentra diferencias, no se inicia el pago y se aplica la corrección del carrito descrita más abajo.

**Regla general de validación del carrito:** todo carrito recibido desde el frontend se considera no confiable y siempre debe validarse contra el estado actual del backend, reutilizando la misma lógica. Aplica a: (1) creación del `Pedido`, (2) corrección del carrito y (3) inicio del pago. Nunca se reutiliza una validación comercial anterior como garantía de validez actual.

### Corrección del carrito

**Implementado el 30/09/2026:** la corrección se detecta en `POST /api/pedidos` (sin `Pedido` previo) y en `POST /api/pedidos/{id}/pago`; la aceptación ocurre con `accion: "aceptar_correccion"` (recibe `items[]`, revalida el carrito completo y, si es válido, en **una sola transacción** el `Pedido` anterior → `NO_PAGADO` y se inserta un nuevo `Pedido` heredando estado y `email_verificacion_id`; si el reemplazante nace en `PEND_VERIF` se inicia su verificación de email; si sigue en `PEND_PAGO`, debe volver a pasar por `iniciar_pago`). La cancelación como salida del ciclo es `POST /api/pedidos/{id}/cancelar`.

Si la validación comercial encuentra diferencias, NO se crea un `Pedido` válido para la operación corregida (y, si ya existe un `Pedido` en el proceso, NO se inicia el pago). El backend devuelve:

- el carrito corregido propuesto;
- la información actualizada de disponibilidad (y, cuando ya existe un `Pedido`, los **precios y la disponibilidad actualizados del catálogo completo activo**);
- el motivo de cada corrección (cambió el precio; quedan menos unidades; producto dejó de estar disponible; producto dejó de existir; u otra regla comercial).

Cuando ocurre una corrección y ya existe un `Pedido` en el proceso de compra:

- el `Pedido` original **NO** pasa a `NO_PAGADO` al detectarse la diferencia: conserva su estado (`PEND_VERIF` o `PEND_PAGO`) y **sigue afectando la disponibilidad** mientras el usuario decide;
- sus `PedidoItem` NO se modifican para convertirlos en la nueva operación;
- el frontend actualiza la información visible de disponibilidad y de precios;
- el usuario puede editar libremente el carrito corregido (reducir cantidades, eliminar productos, agregar productos que no estaban originalmente, modificar cantidades);
- el usuario puede aceptar la corrección, modificar el carrito o **cancelar** mediante `POST /api/pedidos/{id}/cancelar` (cancelación voluntaria del checkout, que también sirve para rechazar la corrección → `NO_PAGADO`);
- no existe lógica que distinga el carrito "original" del "corregido": cada carrito recibido representa la intención actual del usuario y se valida de la misma manera;
- si lo acepta: se realiza nuevamente la **validación comercial completa** y, si resulta válida, **en la misma operación/transacción** el `Pedido` anterior pasa a `NO_PAGADO` y se crea un **nuevo** `Pedido` y el proceso continúa con la misma lógica general; si vuelve a haber diferencias, se devuelve una nueva corrección **sin sustituir** el `Pedido`;
- el nuevo `Pedido` **nace con el mismo estado que tenía el original antes de la corrección** (`PEND_VERIF` o `PEND_PAGO`): la corrección no fuerza repetir una verificación de email ya cumplida; un reemplazante en `PEND_PAGO` igualmente debe volver a pasar por la tercera validación comercial antes de iniciar realmente el pago;
- el vencimiento del primer período sigue corriendo durante toda la decisión: si vence, el `Pedido` pasa a `NO_PAGADO` por vencimiento.

La nueva validación **SIEMPRE** vuelve a comprobar stock, precio, disponibilidad y reglas comerciales: NO se asume que el carrito corregido está validado simplemente porque fue generado por el backend. La única validación que NO se repite es la verificación de email si el comprador ya fue verificado.

Las correcciones pueden repetirse hasta que la operación sea válida o el usuario la abandone o la cancele:

```text
validar → corregir → usuario modifica/acepta → volver a validar → nuevo Pedido → continuar
                                                        └ o cancela → NO_PAGADO (cancelación voluntaria)
```

Si el usuario acepta continuar con las cantidades disponibles: **en la misma operación/transacción** el `Pedido` anterior queda `NO_PAGADO` y se crea un **nuevo** `Pedido` con nuevos `PedidoItem`, reutilizando la verificación de email ya realizada, sin modificar los `PedidoItem` del Pedido anterior y sin relación especial entre ambos Pedidos.

---

# 12. Verificación de email

La verificación del email será independiente del pago.

El sistema deberá verificar que el comprador tenga acceso a la dirección de email proporcionada antes de completar el proceso de compra.

Conceptualmente:

```text
Email
   ↓
Verificación
   ↓
Continuar con el pago
```

En el MVP, **todo pedido requiere verificación de email**.

La verificación de email no será parte del sistema de autenticación de cuentas.

La arquitectura de verificación deberá ser un **mecanismo genérico y agnóstico de contexto** (sin campo `contexto` ni equivalentes), reutilizable para futuros consumidores (newsletter, registro de cuentas, recuperación de contraseña) sin complicar el MVP. El único consumidor del MVP es la compra; los demás consumidores son **POST-MVP**.

La lógica de verificación existe en el backend histórico (`D:\Incancelables Backend`, Apps Script), desarrollada originalmente para el newsletter (ingreso del email, generación del token, envío, validación, expiración, token inválido/no validado, reenvío y situaciones posteriores a la validación); esa lógica fue **auditada** y queda como referencia conceptual. El e-commerce reutilizará conceptualmente esa misma lógica, implementándola en PHP/MariaDB en lugar de Apps Script, **sin migrar el modelo de datos** del newsletter. No se inventará una segunda lógica diferente.

**Implementado y verificado el 29/09/2026:**

- el inicio de la verificación ocurre dentro de `POST /api/pedidos`, **después** del `COMMIT` que crea el `Pedido` (opción A: un solo endpoint de inicio de checkout; un fallo de envío **no** revierte el `Pedido`);
- consumidor: `PedidoService` (inicia, confirma y reenvía); `EmailVerificationService` es genérico/agnóstico y **no** envía emails, **no** cambia estados del `Pedido` y registra `verified_at`;
- endpoints implementados y probados: `POST /api/email-verificaciones/validar` (`200 confirmado`, `400 token_invalido`, `400 token_expirado`) y `POST /api/email-verificaciones/reenvio` (`200 reenviado`, `429 cooldown_activo`, `409 verificacion_ya_verificada`, `409 pedido_no_pendiente_de_verificacion`, `404 pedido_inexistente`, `502 error_envio_email`); desde el 05/10/2026, también `POST /api/email-verificaciones/pedidos` (consulta solo lectura: `200 ok` con `pedidos[]`, `409 verificacion_pendiente`, `400 token_invalido`/`token_expirado`/`token inválido`/`Falta el campo token`; 37/37 pruebas vía HTTP); no existe `POST /api/email-verificaciones` (solicitud integrada en `POST /api/pedidos`) ni `GET /api/pedidos/{id}`;
- transición `PEND_VERIF → PEND_PAGO` a cargo de `PedidoService`, sin nueva comprobación de stock, con trazabilidad en `pedidos.email_verificacion_id`; la confirmación procesa **todos** los `Pedido` asociados a la verificación (N:1): `PEND_VERIF` con primer período vigente → `PEND_PAGO`, `PEND_VERIF` vencidos → `NO_PAGADO`, demás estados intactos, y responde `pedidos[]` con los habilitados;
- fechas/horas (`expires_at`, cooldown) calculadas en SQL (`NOW()`, `DATE_ADD`, `TIMESTAMPDIFF`) porque la timezone de MariaDB difiere de la de PHP;
- pruebas: 20/20 OK (creación, hash, expiración, cooldown, rotación de token, transición, stock intacto, fallo de envío sin rollback, regresión de los bloques 1 y 2); base limpia al finalizar (`pedidos`, `pedidos_items` y `emails_verificaciones` en 0; 6 productos).

**Pendientes dentro del bloque:** (1) ~~canal de email `log` de DEV~~ **RESUELTO e implementado el 03/10/2026** (decisión cerrada el 02/10/2026: canal de infraestructura de desarrollo que registra el email en `error_log`, considera la entrega exitosa y registra `last_sent_at`, sin envío SMTP real); (2) entrega real del email (canal SMTP u otro; **requisito para dar por terminado el MVP**); (3) URL, asunto y plantilla definitivos del checkout (variante mínima en uso: asunto "Confirmá tu compra en Incancelables" y URL destino `http://127.0.0.1:5500/pages/resultado.html`, tomada desde el 05/10/2026 de `src/config/frontend.php` — clave `url_verificacion_checkout`, inyectada en `PedidoService` por `public/index.php` —, a confirmar con el frontend; el `flow=checkout`, el token, el asunto y el cuerpo siguen en el código de `PedidoService`).

Decisiones cerradas del mecanismo (ver `ECOMMERCE_DESIGN.md`, sección 11.1, y `ECOMMERCE_TECHNICAL_DESIGN.md`, sección 15):

- **estructura física de `emails_verificaciones` cerrada**: `id`, `email`, `token_hash`, `expires_at`, `verified_at`, `created_at`, `last_sent_at`; PK `id`; índice no único `idx_emails_verificaciones_email (email)`; sin `estado_id` (el estado se deriva de los datos); sin `pedido_id`/`contexto`/`tipo`/`uso`/`origen`; `email` NO es UNIQUE (cada `Pedido` crea su propio registro); registros completados conservados (no se eliminan); FK unidireccional `pedidos.email_verificacion_id → emails_verificaciones.id`; estructura e índice ya existentes en la base de datos actual, más `last_sent_at` (única ampliación de esquema, aplicada el 29/09/2026 para el cooldown de reenvío);
- **responsabilidades del servicio de verificación**: crea registros, genera tokens **nuevos** (aleatorios, `bin2hex(random_bytes(32))`, 256 bits) guardando **sólo su hash SHA-256** (el token en claro sólo llega al consumidor; nunca se persiste), controla expiración (referencia: 7 días) y cooldown (referencia: 15 minutos, medido sobre `last_sent_at`), **rota el token en cada reenvío permitido** (el anterior queda inválido; sin reutilizar el vigente; lógica **interna**: el consumidor hace una sola solicitud y no elige la operación), valida, registra `verified_at` y devuelve el resultado;
- **separación de responsabilidades**: el mecanismo **no envía emails** (cada consumidor envía su propio email; en el MVP `PedidoService` envía el email de verificación del checkout); en la confirmación, el consumidor recibe el token → el mecanismo valida y registra `verified_at` → el consumidor decide la acción de negocio; sin callbacks ni eventos;
- cuatro concerns separados: lógica genérica de verificación | lógica del `Pedido` | envío de emails | modelo histórico del newsletter.

En el ciclo de vida del `Pedido`, la verificación exitosa produce `PEND_VERIF → PEND_PAGO`, sin nueva comprobación de stock (**implementado y verificado**: la transición la ejecuta `PedidoService` al recibir `200 confirmado` de `POST /api/email-verificaciones/validar`; desde el 30/09/2026 el comportamiento es N:1 — todos los `Pedido` asociados en `PEND_VERIF` vigentes habilitan `PEND_PAGO` y los vencidos pasan a `NO_PAGADO`, ver sección 14).

### Identidad del comprador

Introducir el email **NO** significa que el comprador esté identificado. La identificación ocurre únicamente cuando el email fue verificado:

```text
email ingresado, sin verificar → comprador NO identificado (PEND_VERIF: Pedido creado + email ingresado + sin verificar)
email verificado               → comprador identificado mediante ese email (PEND_PAGO)
```

### Verificación única por proceso de identidad

- La verificación de email se realiza **una vez por proceso de identidad**; si ya fue verificada, **NO se repite** al crear un nuevo `Pedido` producto de una corrección.
- En cambio, la **validación comercial** (stock, precio, disponibilidad y reglas comerciales) **SIEMPRE** se realiza nuevamente cuando corresponde validar un carrito o un `Pedido`; nunca se da por válida solamente porque el carrito fue corregido por el backend.
- El reenvío se rige por un **cooldown parametrizado**: cumplido el cooldown puede solicitarse el reenvío, generando un token nuevo (rotación; el token anterior queda inválido).
- En desarrollo y producción pueden existir diferencias de entrega del email, pero la lógica funcional se mantiene.

### Recuperación de pedidos pendientes

**Condición previa:** la recuperación solamente puede ofrecerse cuando existe un Pedido actual cuyo email haya sido verificado exitosamente y que se encuentre en `PEND_PAGO`. El email ingresado inicialmente NO es suficiente para acceder a información de operaciones anteriores. La recuperación se inicia desde el Pedido actual ya verificado.

- Debe existir como máximo **UNA** operación anterior recuperable, entre `PEND_VERIF` y `PEND_PAGO`.
- `PROCESANDO_PAGO` **no participa** de este mecanismo: queda fuera de la regla porque ese Pedido puede encontrarse en proceso de conciliación y continúa independientemente.
- No deben existir dos operaciones simultáneamente ofrecidas como recuperables para el mismo email.
- **Caso A — anterior en `PEND_VERIF`:** el anterior pasa `PEND_VERIF → PEND_PAGO`, hereda la verificación de email del Pedido actual (NO se envía una nueva verificación) y el Pedido actual pasa a `NO_PAGADO`; no se modifican sus `PedidoItem`.
- **Caso B — anterior en `PEND_PAGO`:** el anterior permanece en `PEND_PAGO` y el Pedido actual pasa a `NO_PAGADO`.
- **Caso C — continuar con el actual:** el Pedido actual permanece en `PEND_PAGO` y el Pedido anterior recuperable pasa a `NO_PAGADO`. La operación elegida es la que continúa.
- Con un futuro usuario registrado la lógica será conceptualmente la misma; cambia únicamente el mecanismo de identificación (guest: email verificado; usuario registrado: sesión/cuenta autenticada). No se implementan cuentas en el MVP.

**Corrección y recuperación son flujos distintos:** la corrección es que la operación original no puede continuar como estaba y genera un nuevo `Pedido`; la recuperación es que un nuevo checkout verificado encuentra un Pedido pendiente recuperable y permite decidir cuál operación continuar.

---

# 13. Mercado Pago y pagos del MVP

Mercado Pago es el medio de pago previsto y el único medio considerado para el MVP (no se admite comprar con múltiples medios/proveedores reales). Las validaciones simuladas del MVP se registran con el proveedor **`DUMMY`**, no con `MERCADOPAGO`.

**Decisión cerrada:** en el MVP el proveedor se implementa de forma **simulada**, con la arquitectura `PaymentValidationService → PaymentProvider → DummyPaymentProvider`. `DummyPaymentProvider` es la implementación simulada del contrato `PaymentProvider` utilizada en el MVP, asociada al proveedor **`DUMMY`** del catálogo `proveedores`, y debe poder ejercitar, de punta a punta, los resultados `APROBADO` y `RECHAZADO` (los dos únicos valores de `simulacion` desde el 05/10/2026; `EN_PROCESO` quedó excluido del simulador y queda reservado para proveedores con resolución asincrónica, POST-MVP — ver `ECOMMERCE_TECHNICAL_DESIGN.md`, sección 21) (creación de `Pago`, `validaciones_pagos`, estados del `Pedido`, múltiples intentos, procesamiento, aprobación/rechazo, afectación de stock, idempotencia, casos de revisión y el resto de los comportamientos definidos).

La integración **real** con Mercado Pago (`MercadoPagoProvider` + webhook `POST /api/webhooks/mercadopago`) queda **POST-MVP / extensión opcional** (si hay tiempo académico). Reemplazar `DummyPaymentProvider` por `MercadoPagoProvider` **no modifica** la lógica de negocio del pago: sólo se cambia la implementación detrás del contrato `PaymentProvider`.

El pago deberá estar conceptualmente separado de:

```text
Pedido
Email
Fulfillment
Entrega
Cancelación
Devolución
Reembolso
```

El resultado del pago deberá registrarse asociado al pedido, en el `PaymentValidationResult` del intento correspondiente.

El sistema no deberá interpretar un pago como equivalente al estado general del pedido.

La integración con el proveedor de pagos queda aislada en el backend, detrás de `PaymentValidationService` (frontera de validación de pagos, ver `ECOMMERCE_TECHNICAL_DESIGN.md`), con la arquitectura del MVP `PaymentValidationService → PaymentProvider → DummyPaymentProvider`: Frontend → Backend → `PaymentValidationService` → `PaymentProvider` → `DummyPaymentProvider` (resultado simulado). `PaymentValidationService` es la única capa de negocio autorizada a interactuar con el proveedor mediante el adaptador correspondiente; el resto del sistema no llama directamente al proveedor ni al simulador y no depende de sus detalles. El frontend solo muestra el proveedor como información de presentación (identidad `DUMMY` en el catálogo `proveedores` durante el MVP; `MERCADOPAGO` en la integración real futura). Las credenciales del proveedor permanecen en backend (`DummyPaymentProvider` no usa credenciales reales). La modalidad de checkout real (Checkout Pro vs Checkout API/Orders) es un **detalle de implementación** encapsulado en `MercadoPagoProvider` (**POST-MVP**) y **no** constituye una decisión de diseño pendiente. El webhook `POST /api/webhooks/mercadopago` y su autenticación/verificación específica quedan **fuera del MVP** (POST-MVP, detalle de implementación/provider-specific para una etapa posterior).

Modelo de pago decidido:

- `Pago` = nuestro intento de cobro (`id`, `pedido_id`, `importe`, `created_at`); **NO tiene estado propio** ni contiene proveedor, comisión, neto ni referencia del proveedor.
- `PaymentValidationResult` = resultado de nuestra validación contra el proveedor (`id`, `pago_id`, `estado_id`, `proveedor_id`, `referencia_proveedor`, `importe`, `created_at`; `pago_id NOT NULL` y `UNIQUE(pago_id)`); relación `Pago 1 ─── 1 PaymentValidationResult` con FK desde `PaymentValidationResult.pago_id → Pago.id` (sin FK circular desde `Pago`); su tabla se llama **`validaciones_pagos`** y su catálogo de estados **`validaciones_pagos_estados`**;
- Importes separados: total comercial del `Pedido` = `Σ(cantidad × precio_unitario)` (sin `importe_total`); `Pago.importe` = lo que nuestro sistema solicita cobrar (= total comercial en el MVP); `PaymentValidationResult.importe` = lo que el proveedor informa/valida (auditoría y detección de diferencias).
- Comisiones/fees de Mercado Pago, impuestos/cargos del proveedor y neto liquidado: **fuera del MVP** (futura liquidación/conciliación); no se agregan a `Pago` ni a `PaymentValidationResult` y no modifican lo que paga el cliente.
- Catálogo `proveedores` (`id`, `codigo`), **ya existente físicamente** con las filas `MERCADOPAGO` y `DUMMY` (identificación por `codigo`, sin IDs numéricos fijos): en el MVP `DummyPaymentProvider` referencia `DUMMY`; `MERCADOPAGO` queda reservado para `MercadoPagoProvider` (integración real, POST-MVP); `PaymentValidationResult.proveedor_id` lo referencia.

Comportamiento adicional decidido:

- En el MVP el usuario atraviesa un **paso de pago simulado** (`DummyPaymentProvider`); con la integración real (POST-MVP) realizará la operación en el **checkout de Mercado Pago**.
- Flujo completo (MVP): Frontend → Backend → validación del carrito → crear `Pago` → crear `PaymentValidationResult` → `PaymentProvider` → `DummyPaymentProvider` → resultado simulado → `PaymentValidationService` (consulta/valida contra el proveedor) → `PaymentValidationResult` normalizado → `PedidoService`.
- Con la integración real (POST-MVP): los pasos del adaptador se reemplazan por `MercadoPagoProvider` → Mercado Pago → cliente paga → Mercado Pago notifica → Webhook → `PaymentValidationService` → consulta/valida contra Mercado Pago → normalización; la lógica de negocio no cambia.
- Al iniciar la validación se crea el `Pago`, luego su único `PaymentValidationResult` (inicialmente en estado no definitivo, p. ej. `EN_PROCESO`) y recién entonces se interactúa con el proveedor (registra el intento aunque el proveedor no responda).
- El frontend **NO** determina que un pago está aprobado: la confirmación definitiva la realiza el backend validando contra el proveedor (también con el simulador).
- El retorno del navegador con el resultado **NO** es la fuente definitiva de verdad para actualizar el `Pedido`: la confirmación definitiva se realiza mediante el mecanismo de validación del backend (consulta/validación contra el proveedor → validación del estado actual → normalización → `PaymentValidationResult` → actualización idempotente). No se implementa polling permanente.
- Resultado `APROBADO` (en `PaymentValidationResult`, tras validación efectiva contra el proveedor): `Pedido` pasa a `PAGADO` (si el stock fue afectado), se informa al usuario y se envía un email con los datos de verificación de la transacción.
- Resultado `RECHAZADO` (rechazo definitivo informado/validado por el proveedor): se informa al usuario; si quedan intentos y el período vigente continúa, `Pedido → PEND_PAGO` con nuevo intento; si se alcanzó `PAGO_MAX_INTENTOS`, `Pedido → NO_PAGADO`.
- Resultados no definitivos (`PENDIENTE`, `EN_PROCESO`, `DESCONOCIDO`, imposibilidad técnica): **NO** se convierten automáticamente en `RECHAZADO`; el `Pedido` permanece en `PROCESANDO_PAGO` y no se libera disponibilidad.
- Flujo interno conceptual: `Controller → PaymentValidationService → PaymentProvider (DummyPaymentProvider en el MVP) → PaymentValidationResult normalizado → reglas de negocio → Repository / persistencia`. No se acopla el dominio interno a los estados propios de Mercado Pago ni a los escenarios del simulador.
- Estados de `PaymentValidationResult` (exactos, sin conjunto paralelo): `PENDIENTE`, `EN_PROCESO`, `APROBADO`, `RECHAZADO`, `CANCELADO`.
- Un error técnico del proveedor **NO** se convierte automáticamente en `RECHAZADO`.
- La notificación/confirmación del proveedor **no decide directamente** el estado comercial del `Pedido`: informa el cambio, consulta al proveedor y normaliza el resultado; la lógica comercial continúa centralizada en `PedidoService`. El webhook `POST /api/webhooks/mercadopago` pertenece a la integración real (**POST-MVP**); el concepto (notificación → consulta al proveedor → normalización → idempotencia) se mantiene con independencia del proveedor.
- La autenticación/verificación específica de las notificaciones webhook de Mercado Pago queda **fuera del MVP** (POST-MVP); se documenta sólo como detalle de implementación/provider-specific para una etapa posterior (sin definir firmas, headers, secretos ni algoritmos).
- Datos de checkout: la referencia persistente/auditable (`referencia_proveedor`) se separa de los datos temporales de checkout (p. ej. `checkout_url`), que no se convierten automáticamente en campos persistentes; los detalles concretos del proveedor permanecen encapsulados en el adaptador (`DummyPaymentProvider` en el MVP; `MercadoPagoProvider` en la integración real, POST-MVP).

---

# 14. Pedido

Al iniciar el checkout se crea un:

```text
Pedido
```

El pedido representará la transacción comercial original y existirá desde el inicio del checkout, antes de que el pago sea aprobado.

Deberá conservar, como mínimo:

```text
comprador
fecha
items (relación Pedido 1:N PedidoItem)
cantidades
precios aplicados
estado general
información de pago
información de fulfillment
```

Los precios y cantidades correspondientes a la compra deberán conservarse dentro del pedido, en sus `PedidoItem` (`pedido_id`, `producto_id`, `cantidad`, `precio_unitario`).

El pedido **no** conserva un campo `importe_total`: el total se calcula dinámicamente como `Σ(cantidad × precio_unitario)` a partir de sus items. No se duplica el total.

El pedido confirmado no deberá modificarse posteriormente para representar operaciones posteriores.

### Estados del Pedido

Los estados normales del `Pedido` son exactamente:

```text
PEND_VERIF
PEND_PAGO
PROCESANDO_PAGO
PAGADO
NO_PAGADO
```

Existe además un estado excepcional, **no terminal normal**:

```text
PAGADO_STOCK_NO_AFECTADO
```

No existen otros estados de `Pedido`.

Flujo normal:

```text
PEND_VERIF → PEND_PAGO → PROCESANDO_PAGO → PAGADO
```

- `PEND_VERIF`: el `Pedido` se crea al iniciar checkout, ANTES de verificar el email y ANTES de pagar; significa `Pedido` creado + email ingresado + email sin verificar (el comprador todavía NO está identificado); sus `PedidoItem` ya existen y ya afectan la disponibilidad; comienza el primer período temporal.
- Verificación exitosa → `PEND_PAGO`, sin nueva comprobación de stock; el comprador queda identificado mediante ese email.
- Corrección de carrito ante diferencias en la validación comercial **(implementada el 30/09/2026: `iniciar_pago`/`aceptar_correccion`)**: el `Pedido` original **NO** cambia de estado al detectarse la diferencia (conserva su estado y sigue afectando la disponibilidad mientras el usuario decide), sin modificar sus `PedidoItem`; si el usuario acepta, **en la misma operación/transacción** el `Pedido` original → `NO_PAGADO` y se crea un nuevo `Pedido` que hereda el estado previo y `email_verificacion_id`; si vuelve a haber diferencias se devuelve una nueva corrección sin sustituir el `Pedido`; si el usuario cancela, aplica la cancelación voluntaria.
- Cancelación voluntaria del checkout (`POST /api/pedidos/{id}/cancelar`) **(implementada el 30/09/2026)**: `PEND_VERIF`/`PEND_PAGO` → `NO_PAGADO` de inmediato, con liberación de la disponibilidad y `PedidoItem` intactos; sirve también para rechazar una corrección; si el usuario sólo abandona sin cancelar, aplica el vencimiento normal del primer período.
- Recuperación de pedidos pendientes tras verificar el email: transiciones según la opción elegida (ver sección 12); `PROCESANDO_PAGO` queda fuera de esa regla.
- Validación comercial válida al iniciar pago (3.ª comprobación) → `PROCESANDO_PAGO`; comienza el segundo período temporal.
- Pago aprobado y stock afectado → `PAGADO`; si había un caso de revisión pendiente asociado al episodio, se resuelve (`RESUELTO`).
- Pago rechazado con intentos y primer período vigente → vuelve a `PEND_PAGO`; si alcanzó `PAGO_MAX_INTENTOS` → `NO_PAGADO`; el `Pago` rechazado queda registrado, sin modificarse ni reutilizarse; no se crea automáticamente otro intento (el nuevo intento sólo lo inicia el comprador); un `RECHAZADO` no revierte un `Pedido` ya `PAGADO`.
- Alcanzado `PAGO_MAX_INTENTOS` → `NO_PAGADO`.
- Primer período vencido (en `PEND_VERIF`/`PEND_PAGO`, duración inicial **10 minutos** parametrizable, calculada desde `pedidos.created_at`; el frontend puede recibir `expires_at` como cuenta regresiva informativa y sigue corriendo mientras el usuario decide una corrección) → `NO_PAGADO`; para recomprar se inicia un nuevo checkout con un nuevo `Pedido`. **Implementado el 30/09/2026** (aplicación perezosa con `NOW()` de MariaDB: al calcular disponibilidad, al crear un `Pedido` y en las operaciones de `pago`; cada operación sobre un `Pedido` vencido responde `409 pedido_vencido` y lo deja en `NO_PAGADO`).
- Pago aprobado pero stock no afectado → `PAGADO_STOCK_NO_AFECTADO` (excepcional) y se genera el caso de revisión `PAGADO_STOCK_NO_AFECTADO`; resolución fuera del MVP.
- Segundo período vencido sin resolución → permanece en `PROCESANDO_PAGO` y se genera el caso de revisión `PAGO_PROCESANDO_EXCEDIDO` (`PENDIENTE`, sin duplicados para el mismo episodio); NO pasa a `NO_PAGADO`; NO existe estado `PAGO_EN_CONCILIACION`; es un timeout **técnico** que NO se expone al cliente como cuenta regresiva (el frontend sólo informa que se está confirmando el estado del pago).
- `APROBADO` posterior al vencimiento del segundo período → se procesa la aprobación, se afecta el stock, `Pedido → PAGADO` y el caso de revisión pendiente queda `RESUELTO`.
- `RECHAZADO` posterior al vencimiento del segundo período → se resuelve el caso pendiente y se aplica la lógica ya definida (`PEND_PAGO` si quedan intentos parametrizados, o `NO_PAGADO` si se alcanzó el máximo).
- `APROBADO` con inconsistencias (importe diferente, `Pedido` incompatible o dos `Pago` distintos aprobados del mismo `Pedido`) → se registra el resultado, no se afecta stock, no se pasa a `PAGADO`, no se modifica indebidamente el estado comercial y se genera el caso de revisión correspondiente; solamente una aprobación produce efecto comercial.
- Los casos de revisión (`CasoRevision`, ligado al `Pago`) tienen únicamente los tipos `PAGADO_STOCK_NO_AFECTADO` y `PAGO_PROCESANDO_EXCEDIDO`, con estados `PENDIENTE` y `RESUELTO`; se crean como `PENDIENTE`; no se crean tipos, entidades ni estados nuevos, y la revisión no congela la lógica del `Pedido` (ver `ECOMMERCE_DESIGN.md`, sección 13.8).

Estados terminales normales: `PAGADO`, `NO_PAGADO`.

Afectan disponibilidad: `PEND_VERIF`, `PEND_PAGO`, `PROCESANDO_PAGO`.
NO afectan disponibilidad: `PAGADO`, `NO_PAGADO`.

La duración del primer período (**valor inicial de diseño: 10 minutos**; **valor operativo actual: 40 minutos** desde el 03/10/2026 — ajuste operativo no definitivo, parámetro configurable —, parametrizable y de fácil ajuste), la duración del segundo período, `PAGO_MAX_INTENTOS` y `PAGO_MAX_ESPERA_CONCILIACION` están definidos como **parámetros parametrizables**: los valores se cargan en configuración (no son pendientes de diseño).

Ver detalle completo en `ECOMMERCE_DESIGN.md` (sección 8).

---

# 15. Estados y operaciones posteriores

El estado general del pedido no deberá reemplazar los estados particulares de sus componentes.

Conceptualmente:

```text
Pedido
├── Items
├── Pagos
├── Fulfillment
├── Cancelaciones
├── Devoluciones
└── Reembolsos
```

Un pedido puede tener múltiples pagos (intentos), pero como máximo un `PaymentValidationResult` `APROBADO`.

La relación es **Pedido 1:N Pago** y **Pago 1:1 PaymentValidationResult**: un pedido puede tener cero, uno o varios intentos (`Pago`), cada uno con su propio `importe` (registrado independientemente del total calculado del pedido, para auditoría) y con su resultado persistido en su único `PaymentValidationResult`. No existe un campo persistente `numero_pago` / `numero_intento`: el orden del intento se determina dinámicamente a partir de los pagos asociados y su orden cronológico. `PAGO_MAX_INTENTOS` representa el máximo total de intentos, incluyendo el primero.

Las operaciones posteriores deberán quedar asociadas al pedido y, cuando corresponda, a sus items.

---

# 16. Funcionalidades que sí estarán en el MVP

El MVP deberá contemplar:

- productos (genéricos, sin `tipo_producto`);
- merchandising, música y productos generales de la tienda;
- stock físico;
- disponibilidad calculada en caliente como `stock físico − cantidades de PedidoItem de Pedidos activos` (sin `Reserva`, sin `stock_comprometido`, sin tabla de compromisos; no se persiste);
- tres momentos de comprobación (mostrar productos: informativa; iniciar checkout y iniciar pago: validación comercial completa que incluye la disponibilidad); regla general: todo carrito recibido del frontend es no confiable y se valida siempre contra el estado actual del backend;
- validación comercial al iniciar checkout y antes de iniciar el pago (`POST /api/pedidos/{id}/pago` recibe `proveedor_id` y `items[]`; el backend NO confía en esos valores);
- corrección de carrito con nueva validación comercial y creación de un nuevo `Pedido` que hereda el estado previo a la corrección; el usuario puede aceptar, modificar o cancelar la corrección (sin distinguir carrito "original" de "corregido": cada carrito recibido es la intención actual);
- ciclo de vida del `Pedido` con estados `PEND_VERIF`, `PEND_PAGO`, `PROCESANDO_PAGO`, `PAGADO`, `NO_PAGADO` (más `PAGADO_STOCK_NO_AFECTADO` excepcional);
- control de disponibilidad;
- Store;
- carrito unificado, exclusivamente frontend en `sessionStorage`;
- checkout como invitado (sin cuentas, sin `checkout_id`);
- verificación de email obligatoria, una sola vez por proceso de identidad, mediante un mecanismo genérico agnóstico de contexto (consumidor del MVP: la compra; el mecanismo no envía emails: cada consumidor envía el suyo);
- recuperación de un único Pedido pendiente tras verificar el email (entre `PEND_VERIF` y `PEND_PAGO`);
- Mercado Pago como único medio de pago concebido, con arquitectura de pagos del MVP `PaymentValidationService → PaymentProvider → DummyPaymentProvider` (proveedor simulado que ejercita `APROBADO` y `RECHAZADO`, con `EN_PROCESO` excluido del simulador desde el 05/10/2026, ver `ECOMMERCE_TECHNICAL_DESIGN.md`, sección 21) y catálogo `proveedores` (MVP: `DUMMY`; `MERCADOPAGO` reservado para `MercadoPagoProvider`); la integración real (`MercadoPagoProvider` + webhook) queda POST-MVP sobre el mismo contrato;
- modelo de pago `Pago 1 ─── 1 PaymentValidationResult`: `Pago` sin estado propio; estados y proveedor en `PaymentValidationResult`; tablas `validaciones_pagos` y `validaciones_pagos_estados`; importes separados (total comercial, `Pago.importe`, `PaymentValidationResult.importe`); máximo un `PaymentValidationResult` `APROBADO` por `Pedido`;
- casos de revisión (`CasoRevision`, ligado al `Pago`): tipos `PAGADO_STOCK_NO_AFECTADO` y `PAGO_PROCESANDO_EXCEDIDO`, estados `PENDIENTE` y `RESUELTO`, creados como `PENDIENTE`, sin duplicados por episodio (a nivel de aplicación) y con resolución cuando llega un resultado definitivo;
- mecanismo de confirmación del pago: el backend siempre consulta/valida contra el proveedor (`PaymentProvider`) y normaliza el resultado antes de actualizar el sistema (sin polling permanente; la lógica comercial la decide `PedidoService`; la notificación/confirmación es idempotente y no decide el estado del `Pedido`); el webhook de Mercado Pago (`POST /api/webhooks/mercadopago`) y su autenticación quedan **POST-MVP**;
- descuento de stock en el MVP: `Producto.stock = Producto.stock − PedidoItem.cantidad`, sin historial de movimientos de stock;
- creación del pedido al iniciar el checkout;
- confirmación de compra;
- email post-pago con los datos de verificación de la transacción.

---

# 17. Funcionalidades que NO estarán en el MVP

No será necesario implementar inicialmente:

- venta de entradas/tickets (productos asociados a shows) y funcionalidades de shows;
- venta sin stock / bajo demanda (on-demand);
- fulfillment avanzado;
- múltiples fulfillments complejos;
- diferentes métodos de entrega;
- envíos complejos (incluido envío a domicilio);
- retiro en show;
- puntos de entrega / puntos de retiro;
- tracking;
- cuentas de usuario;
- carrito persistente entre dispositivos;
- múltiples carritos recuperables;
- recuperación sofisticada de carritos abandonados;
- promociones complejas (promociones avanzadas);
- cancelaciones;
- devoluciones;
- reembolsos;
- historial de precios;
- gestión de proveedores / sourcing;
- órdenes de compra a proveedores;
- múltiples proveedores de pago;
- newsletter como funcionalidad del e-commerce;
- migración del newsletter a PHP/MariaDB y uso del mecanismo genérico de verificación de email por otros consumidores (newsletter, registro de cuentas, recuperación de contraseña): **POST-MVP** (en el MVP el newsletter sigue en Apps Script → Google Sheets, sin integración con el e-commerce);
- reportes avanzados;
- administración avanzada;
- múltiples imágenes por producto (la estructura quedará preparada);
- facturación avanzada;
- reglas tributarias complejas;
- comisiones/fees de Mercado Pago, impuestos/cargos del proveedor, neto liquidado y liquidación/conciliación (fuera del MVP);
- integración de pagos **real** con Mercado Pago (`MercadoPagoProvider`, modalidad de checkout y webhook `POST /api/webhooks/mercadopago`): **POST-MVP** (en el MVP el proveedor es `DummyPaymentProvider`);
- historial de movimientos de stock;
- resolución administrativa de los casos de revisión `PAGADO_STOCK_NO_AFECTADO` y de los `PAGO_PROCESANDO_EXCEDIDO` que nunca reciben un resultado definitivo (fuera del MVP);
- autenticación/verificación específica de las notificaciones webhook de Mercado Pago (fuera del MVP como definición funcional; detalle de implementación/provider-specific para una etapa posterior).

Nota: la **recuperación de pedidos pendientes** descrita en la sección 12 sí forma parte del MVP; no debe confundirse con la recuperación sofisticada de carritos abandonados, que queda fuera.

Estas funcionalidades deberán quedar previstas conceptualmente, pero no deberán retrasar la primera versión funcional.

---

# 18. Venta sin stock / on-demand

La venta sin stock y la producción/adquisición bajo demanda **no forman parte del MVP**.

No se agrega al modelo una propiedad `permite_venta_sin_stock` solamente por satisfacer documentación anterior.

La arquitectura deberá quedar abierta a incorporar la venta bajo demanda en el futuro (ver Etapa 7), pero eso no condiciona el MVP.

---

# 19. Fulfillment

El concepto de fulfillment queda definido en `ECOMMERCE_DESIGN.md`, pero su implementación completa se realizará posteriormente.

En una etapa futura un mismo pedido podrá tener diferentes fulfillments.

Ejemplo:

```text
Pedido
├── Entrada digital
└── Remera física
```

Cada uno podrá tener un tratamiento diferente.

El MVP no necesita implementar todavía esta complejidad, pero el diseño técnico inicial no deberá impedirla.

---

# 20. Entregas

Las diferentes modalidades de entrega se implementarán posteriormente.

Podrán existir:

```text
Envío a domicilio
Retiro en show
Punto de retiro
Entrega digital
```

La entrega será una consecuencia del fulfillment correspondiente y no una propiedad general que deba aplicarse obligatoriamente a todos los pedidos.

---

# 21. Cancelaciones

Las cancelaciones serán operaciones posteriores al pedido.

Podrán afectar:

```text
pedido completo
```

o:

```text
items específicos
```

o:

```text
parte de la cantidad de un item
```

La cancelación tendrá un origen:

```text
buyer
seller/system
```

El motivo será información adicional.

Por ejemplo:

```text
origin = buyer
reason = buyer_regret
```

No se creará una entidad independiente para representar "arrepentimiento del comprador".

La posibilidad de cancelar deberá determinarse principalmente mediante el estado real del item, fulfillment y las políticas correspondientes.

La cancelación no implicará automáticamente un reembolso.

Las cancelaciones no serán parte del MVP.

---

# 22. Devoluciones y reembolsos

Las devoluciones serán diferentes de las cancelaciones.

Los reembolsos serán diferentes tanto de las cancelaciones como de las devoluciones.

Ejemplo:

```text
Producto defectuoso
    ↓
Devolución
    ↓
Reemplazo / reparación / reembolso
```

Otro ejemplo:

```text
Show suspendido
    ↓
Cancelación
    ↓
Reembolso
```

Estas funcionalidades se implementarán en etapas posteriores.

---

# 23. Abastecimiento y proveedores

La compra del cliente será independiente de la adquisición o producción necesaria para cumplirla.

Conceptualmente:

```text
Pedido del cliente
        ↓
Fulfillment
        ↓
Abastecimiento
        ↓
Proveedor
```

El pedido del cliente no deberá convertirse directamente en una orden al proveedor.

Esta funcionalidad se implementará posteriormente.

---

# 24. Administración

El MVP necesitará únicamente las herramientas administrativas mínimas necesarias para:

- crear productos;
- modificar productos;
- definir precios;
- definir stock físico;
- consultar stock físico y disponible (la disponibilidad se calcula en caliente);
- habilitar o deshabilitar la venta;
- consultar pedidos;
- consultar el resultado del pago.

La administración avanzada se implementará posteriormente.

---

# 25. Etapas del desarrollo

## Etapa 0 — Diseño conceptual

Estado:

```text
COMPLETADA
```

Documento principal:

```text
ECOMMERCE_DESIGN.md
```

Objetivo:

Definir qué significa cada concepto y cómo se relaciona con los demás.

---

## Etapa 1 — Definición del MVP

Estado:

```text
COMPLETADA
```

Objetivo:

Determinar exactamente qué funcionalidades serán necesarias para realizar las primeras ventas.

Resultado esperado:

```text
MVP definido
```

Debe quedar establecido:

- qué productos existen;
- cómo funciona el stock y la disponibilidad;
- cómo se comprueba la disponibilidad al iniciar el checkout;
- cómo funciona el carrito unificado;
- cómo funciona el checkout;
- cómo se verifica el email;
- cómo se procesa el pago;
- cómo se crea el pedido y cómo es su ciclo de vida;
- cómo se confirma la compra.

La definición del MVP se considera cerrada.

Las funcionalidades futuras (entradas, on-demand, cuentas, fulfillment, entregas, cancelaciones, devoluciones y reembolsos) permanecerán fuera del MVP y se incorporarán en las etapas posteriores definidas en este roadmap.

---

## Etapa 2 — Diseño técnico del MVP

Estado:

```text
EN PROGRESO
```

Objetivo:

Transformar el modelo conceptual del MVP en una arquitectura técnica concreta.

Se deberá definir:

- modelo de datos;
- estructuras;
- relaciones;
- arquitectura de la aplicación;
- API;
- endpoints;
- flujo de checkout;
- flujo de verificación de email;
- integración de pagos del MVP (`PaymentProvider` + `DummyPaymentProvider`; la integración real con Mercado Pago es POST-MVP);
- manejo del stock y de la disponibilidad;
- creación del pedido;
- confirmación del pago;
- seguridad;
- validaciones;
- errores.

No se deberá diseñar todavía toda la complejidad futura.

*(Estado al 30/09/2026: los items ya están cerrados a nivel de diseño y, para `GET /api/productos`, `POST /api/pedidos`, los endpoints de verificación de email, `POST /api/pedidos/{id}/cancelar` y `POST /api/pedidos/{id}/pago` (incluido el bloque de pagos con `DummyPaymentProvider`), además están implementados y verificados — ver secciones 2, 2.1, 11, 12 y 14. El resto (recuperación, administración) sigue en esta etapa.)*

Sí deberá garantizarse que el diseño no bloquee su incorporación posterior.

Avances realizados:

- estructura inicial del proyecto PHP;
- configuración del entorno local mediante XAMPP;
- configuración de Apache;
- configuración de MariaDB;
- configuración del Virtual Host local;
- creación de la base de datos `incancelables_ecommerce`;
- creación de las tablas principales del MVP;
- definición de las relaciones mediante claves foráneas;
- configuración de la conexión PHP → MariaDB;
- verificación de la conexión PHP → MariaDB.

Materialización del modelo en la base de datos (verificada por auditoría el 28/09/2026):

- creación de `validaciones_pagos` (con `UNIQUE(pago_id)` y FKs) y del catálogo `validaciones_pagos_estados` (renombramiento de `pagos_estados`);
- eliminación de `pagos.estado_id` (`pagos` = `id`, `pedido_id`, `importe`, `created_at`);
- creación de `casos_revision` con sus catálogos `casos_revision_tipos` y `casos_revision_estados` (tipos y estados cargados);
- ajuste de `pedidos_estados` a los 6 estados definitivos del `Pedido`;
- creación de `productos_imagenes`;
- verificación de la estructura de `emails_verificaciones` y de su índice no único sobre `email`;
- carga de la semilla inicial: **6 productos** en `productos`;
- verificación de la ausencia de `reservas`, `stock_comprometido`, tablas de carrito y `pedidos.importe_total`.

Avances de consolidación documental (28/09/2026):

- auditoría y consolidación documental de los tres documentos fuente (`ECOMMERCE_DESIGN.md`, `ECOMMERCE_TECHNICAL_DESIGN.md`, `ECOMMERCE_ROADMAP.md`);
- cierre de la definición funcional y técnica del MVP (estado de la Etapa 2: en progreso, con decisiones de arquitectura cerradas);
- revisión y cierre del modelo de `Pedido`, `PedidoItem`, `Producto`, `ProductoImagen` y `Pago`;
- separación entre los estados del `Pedido` y los estados de la validación del pago;
- cierre del catálogo `proveedores` (MVP: `DUMMY`; `MERCADOPAGO` reservado para la integración real);
- cierre de `ValidacionesPago` (entidad `PaymentValidationResult`, tabla `validaciones_pagos`, catálogo `validaciones_pagos_estados`);
- cierre de `CasosRevision` (definición de columnas y catálogos);
- auditoría del backend histórico (`D:\Incancelables Backend`, Apps Script) como referencia de la lógica de verificación de email;
- definición arquitectónica del mecanismo genérico de verificación de email (agnóstico de contexto).

Auditoría de estado real (28/09/2026, **solo lectura**: no modificó código, base de datos, configuración ni dependencias):

- entorno DEV operativo: XAMPP, Apache, **PHP 8.2.12** (con `pdo_mysql`), **MariaDB 10.4.32**, Virtual Host con respuesta **HTTP 200** y conexión PHP → MariaDB verificada;
- código existente: sólo `public/index.php` (prueba mínima con `echo`) y `src/config/database.php` (conexión PDO con configuración **hardcodeada**); directorios de código vacíos; `src/repositories/` todavía inexistente;
- **no** hay routing, controllers, services, repositories, models ni endpoints funcionales;
- base de datos: **15 tablas** con las decisiones de modelo ya materializadas y **6 productos** cargados;
- no existen archivos `.sql` ni migraciones versionadas que reproduzcan el esquema.

> Ese estado corresponde a la auditoría del **28/09/2026**. A partir del **29/09/2026** se implementaron y verificaron los bloques `GET /api/productos`, `POST /api/pedidos` y verificación de email, y el **30/09/2026** el bloque de rutas `{id}` + vencimiento del primer período + `POST /api/pedidos/{id}/cancelar` + `POST /api/pedidos/{id}/pago` (incluido el bloque de pagos con `DummyPaymentProvider`, `PaymentValidationService`, `PagoRepository` y afectación de stock): hoy existen front controller con router paramétrico, rutas, controllers, services y repositories para esos seis endpoints (ver secciones 2, 2.1, 11, 12 y 14).

Decisiones de arquitectura ya cerradas y documentadas en `ECOMMERCE_TECHNICAL_DESIGN.md`:

- ecosistema de tres proyectos: `D:\Incancelables Web` (frontend), `D:\Incancelables Backend` (backend histórico Apps Script, referencia) y `D:\Incancelables E-Commerce` (backend PHP + MariaDB), con separación frontend/backend dentro del e-commerce y sin integración entre ecosistemas en el MVP;
- arquitectura de capas del backend: `Routes/Controller/Service/Repository/MariaDB` (más Config y Model/entidades), sin saltos arbitrarios de capas y sin ORM como eje de la arquitectura;
- Services del MVP: `PedidoService`, `ProductoService`, `EmailVerificationService`, `PaymentValidationService` (sin `PagoService`, `StockService`, `AvailabilityService`, `ShowService`);
- Repositories del MVP: `ProductoRepository`, `PedidoRepository`, `PagoRepository`, `EmailVerificationRepository` (sin `ShowRepository`; `shows`/`shows_estados` existen sólo estructuralmente);
- disponibilidad calculada en caliente: `stock físico − cantidades de PedidoItem de Pedidos activos` (sin `Reserva`, sin `stock_comprometido`, sin tabla de compromisos); no se persiste y su cálculo lo coordina `PedidoService` con `ProductoRepository` y `PedidoRepository`;
- estados del `Pedido`: `PEND_VERIF`, `PEND_PAGO`, `PROCESANDO_PAGO`, `PAGADO`, `NO_PAGADO`, más `PAGADO_STOCK_NO_AFECTADO` excepcional;
- flujo normal `PEND_VERIF → PEND_PAGO → PROCESANDO_PAGO → PAGADO`;
- tres momentos de comprobación (mostrar productos: informativa; iniciar checkout y iniciar pago: validación comercial completa que incluye la disponibilidad);
- validación comercial de checkout y corrección de carrito (carrito corregido propuesto + disponibilidad + motivos, y precios/disponibilidad del **catálogo completo activo** cuando ya existe un `Pedido`; detectar diferencias **NO** cambia el estado del `Pedido` — conserva su estado y afecta la disponibilidad mientras el usuario decide; el usuario acepta, modifica o cancela sin distinguir "original" de "corregido"; al aceptar, **en la misma operación/transacción** el `Pedido` anterior → `NO_PAGADO` sin modificar `PedidoItem` y se crea un nuevo `Pedido` que nace con el mismo estado que tenía el original; si vuelven las diferencias, nueva corrección sin sustituir el `Pedido`; un reemplazante `PEND_PAGO` vuelve a pasar por la 3.ª validación antes de pagar) — **corrección y aceptación implementadas el 30/09/2026** (`iniciar_pago`/`aceptar_correccion` en `POST /api/pedidos/{id}/pago`); regla general: todo carrito del frontend es no confiable y se valida siempre (creación, corrección, inicio de pago);
- cancelación voluntaria del checkout: `POST /api/pedidos/{id}/cancelar` (**implementada y verificada el 30/09/2026**) → `PEND_VERIF`/`PEND_PAGO` pasa a `NO_PAGADO` con liberación de disponibilidad; sirve para rechazar una corrección; sin timeout propio (si no se cancela, aplica el vencimiento del primer período); distinta de las cancelaciones post-venta (fuera del MVP);
- carrito exclusivamente frontend en `sessionStorage`, sin persistencia en el backend y sin `checkout_id`;
- recuperación de pedidos pendientes: sólo desde un Pedido actual verificado y en `PEND_PAGO`, máximo un `Pedido` anterior recuperable entre `PEND_VERIF` y `PEND_PAGO` (`PROCESANDO_PAGO` queda fuera de esa regla); casos A/B/C documentados en `ECOMMERCE_DESIGN.md`, sección 11.2;
- descuento del stock físico solo con pago aprobado, como operación atómica;
- frontera de validación de pagos (`PaymentValidationService`, única capa de negocio autorizada, vía `PaymentProvider`) como única puerta hacia el proveedor, con `DummyPaymentProvider` como implementación simulada del MVP y `MercadoPagoProvider` como implementación real POST-MVP; el retorno del navegador no es fuente definitiva; el frontend no determina la aprobación;
- modelo de pago: `Pago` sin estado propio (`id`, `pedido_id`, `importe`, `created_at`) + `PaymentValidationResult` 1:1 (`pago_id NOT NULL`, `UNIQUE(pago_id)`, FK desde el resultado) con `estado_id`, `proveedor_id`, `referencia_proveedor`, `importe`; tablas cerradas: **`validaciones_pagos`** y catálogo **`validaciones_pagos_estados`** (renombramiento de `pagos_estados`); estados en `PaymentValidationResult`: `PENDIENTE`, `EN_PROCESO`, `APROBADO`, `RECHAZADO`, `CANCELADO`; catálogo `proveedores` (MVP: `DUMMY`; `MERCADOPAGO` reservado para `MercadoPagoProvider`); importes separados (total comercial del `Pedido`, `Pago.importe` = lo cobrado, `PaymentValidationResult.importe` = lo informado por el proveedor); comisiones/fees fuera del MVP;
- flujo de pago: validación del carrito → crear `Pago` → crear su único `PaymentValidationResult` (inicialmente no definitivo) → `PaymentProvider` → `DummyPaymentProvider` (resultado simulado) → `PaymentValidationService` (validación contra el proveedor) → `PaymentValidationResult` normalizado → `PedidoService` (con la integración real, POST-MVP: `MercadoPagoProvider` → Mercado Pago → webhook → misma normalización);
- datos de checkout: `referencia_proveedor` persistente/auditable separado de datos temporales de checkout (p. ej. `checkout_url`); detalles del proveedor encapsulados en el adaptador (`DummyPaymentProvider` en el MVP; `MercadoPagoProvider` en la integración real, POST-MVP);
- reintentos de pago y diferencia entre `RECHAZADO` y estados indefinidos;
- dos ventanas de tiempo del `Pedido` (primer período: creación → inicio del pago, duración inicial **10 minutos** parametrizable desde `pedidos.created_at`, con `expires_at` como cuenta regresiva informativa para el frontend — **vencimiento implementado el 30/09/2026**, aplicación perezosa con `NOW()` de MariaDB y `409 pedido_vencido` en cada operación sobre un `Pedido` vencido; segundo período: `PROCESANDO_PAGO` → resolución, timeout técnico **sin cuenta regresiva** visible); vencido el segundo período sin resolución → permanece en `PROCESANDO_PAGO` y se genera el caso de revisión `PAGO_PROCESANDO_EXCEDIDO` (sin duplicados), sin estado `PAGO_EN_CONCILIACION`;
- casos de revisión (`CasoRevision`): ligado al **`Pago`** (no al `Pedido`); columnas `id`, `pago_id`, `tipo_id`, `estado_id`, `created_at`, `updated_at`; sin `descripcion` y sin `UNIQUE(pago_id, tipo_id)` (deduplicación a nivel de aplicación); catálogos `casos_revision_tipos` y `casos_revision_estados` con `orden` y `activo`; tipos exactos `PAGADO_STOCK_NO_AFECTADO` y `PAGO_PROCESANDO_EXCEDIDO`, estados `PENDIENTE` y `RESUELTO`, creados como `PENDIENTE`; sin tipos, entidades ni estados nuevos; la revisión no congela el `Pedido` (al llegar un resultado definitivo se registra, se resuelve el caso y continúa la lógica comercial); `APROBADO` con inconsistencias (importe diferente, `Pedido` incompatible, dos `Pago` aprobados del mismo `Pedido`) → se registra, no se afecta stock, no se pasa a `PAGADO` y se genera el caso de revisión; `RECHAZADO` no revierte un `Pedido` ya `PAGADO`;
- verificación de email mediante un mecanismo genérico agnóstico de contexto (sin campo `contexto`; consumidor del MVP: la compra; consumidores futuros POST-MVP: newsletter, registro de cuentas y recuperación de contraseña), tomando como referencia la lógica auditada del backend histórico Apps Script (expiración 7 días, cooldown 15 minutos), una sola vez por proceso de identidad y con cooldown parametrizado de reenvío; estructura de `emails_verificaciones`, responsabilidades del servicio y separación de responsabilidades (el mecanismo no envía emails) **cerrados e implementados y verificados el 29/09/2026**;
- email post-pago con los datos de verificación de la transacción al pasar a `PAGADO`;
- endpoints conceptuales del MVP (`GET /api/productos`, `POST /api/email-verificaciones[/validar|/reenvio|/pedidos]`, `POST /api/pedidos`, `POST /api/pedidos/{id}/pago`, `POST /api/pedidos/{id}/cancelar`), sin endpoints de carrito, stock, disponibilidad, reservas ni `checkout_id`; `POST /api/webhooks/mercadopago` queda POST-MVP (junto con `MercadoPagoProvider`); **implementados y verificados (29/09/2026):** `GET /api/productos`, `POST /api/pedidos`, `POST /api/email-verificaciones/validar` y `POST /api/email-verificaciones/reenvio` (la solicitud de verificación no tiene endpoint propio: está integrada en `POST /api/pedidos`); **implementado y verificado (05/10/2026):** `POST /api/email-verificaciones/pedidos` (consulta solo lectura de `Pedido`/`PedidoItem` por token para continuar la compra; sin `GET /api/pedidos/{id}`); **implementados y verificados (30/09/2026):** `POST /api/pedidos/{id}/cancelar` y `POST /api/pedidos/{id}/pago` (tercera validación con `iniciar_pago`/`aceptar_correccion` **y bloque de pagos**: `proveedor_id`, `items[]`, `simulacion`, creación de `Pago` + `PaymentValidationResult`, transición a `PROCESANDO_PAGO` y aplicación del resultado);
- clasificación de errores de negocio vs. errores técnicos (el Service determina el resultado; el Controller lo convierte en HTTP/JSON; un error técnico del proveedor no se convierte en `RECHAZADO`);
- transacciones e idempotencia garantizadas por el backend/DB (creación de Pedido+PedidoItem, operaciones críticas de pago, afectación de stock + resultado + transición; sin dobles efectos ante confirmaciones/notificaciones repetidas, con webhook duplicado cuando exista);
- invariantes de negocio (máximo un `PaymentValidationResult` `APROBADO` por pedido; disponibilidad nunca negativa).

> Nota: la tabla `pedidos_estados` **ya contiene los 6 estados definidos** (`PEND_VERIF`, `PEND_PAGO`, `PROCESANDO_PAGO`, `PAGADO`, `NO_PAGADO`, `PAGADO_STOCK_NO_AFECTADO`): el ajuste antes pendiente (`PENDIENTE`, `CONFIRMADO`, `CANCELADO`) fue aplicado y verificado (ver `ECOMMERCE_TECHNICAL_DESIGN.md`, sección 9).

La estructura actual del proyecto (verificada el 29/09/2026) es:

```text
D:\Incancelables E-Commerce

├── documentacion
├── public
│   ├── .htaccess          → reescritura hacia index.php (RewriteEngine On)
│   └── index.php          → front controller / router (autoload, dispatch, JSON 404/405/500)
├── routes
│   └── api.php            → rutas: GET /api/productos, POST /api/pedidos, POST /api/email-verificaciones/validar, POST /api/email-verificaciones/reenvio, POST /api/email-verificaciones/pedidos
├── src
│   ├── config
│   │   ├── database.php   → conexión PDO actual (configuración hardcodeada)
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
│   │   ├── ProductoRepository.php        → SQL del catálogo (incluye SELECT ... FOR UPDATE)
│   │   ├── PedidoRepository.php          → SQL de pedidos/items, cantidades comprometidas y operaciones de verificación
│   │   └── EmailVerificationRepository.php → SQL de emails_verificaciones (token_hash, verified_at, last_sent_at)
│   └── services
│       ├── ProductoService.php           → sin cálculo de disponibilidad
│       ├── PedidoService.php             → coordina disponibilidad, validación comercial y verificación de email (consumidor)
│       └── EmailVerificationService.php  → mecanismo genérico de verificación (sin envío de emails)
└── storage
    └── logs               → vacío
```

> No existen `composer.json`/autoloader (el autoload es manual con `spl_autoload_register`), `.env`, migraciones `.sql` ni tests.

La base de datos contiene **15 tablas** (estado verificado):

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

> La tabla `pagos_estados` **ya no existe**: el catálogo fue renombrado a `validaciones_pagos_estados`.
>
> `proveedores` contiene las filas `MERCADOPAGO` y `DUMMY` (identificación por `codigo`, sin IDs numéricos fijos); en el MVP las validaciones de pago referencian `DUMMY` y `MERCADOPAGO` queda reservado para `MercadoPagoProvider` (integración real, POST-MVP). No requiere cambios SQL.
>
> Existen **6 productos** cargados en `productos` (semilla inicial de la tienda).

Las relaciones mediante claves foráneas existentes son:

```text
casos_revision.pago_id           → pagos.id
casos_revision.tipo_id           → casos_revision_tipos.id
casos_revision.estado_id         → casos_revision_estados.id

validaciones_pagos.pago_id       → pagos.id          (UNIQUE(pago_id): relación Pago 1:1)
validaciones_pagos.estado_id     → validaciones_pagos_estados.id
validaciones_pagos.proveedor_id  → proveedores.id

pagos.pedido_id                  → pedidos.id

pedidos.email_verificacion_id    → emails_verificaciones.id
pedidos.estado_id                → pedidos_estados.id

pedidos_items.pedido_id          → pedidos.id
pedidos_items.producto_id        → productos.id

productos.show_id                → shows.id
productos_imagenes.producto_id   → productos.id

shows.estado_id                  → shows_estados.id
```

> Nota: los cambios de modelo previstos en el modelo de pago **ya fueron aplicados**: `pagos` quedó con `id`, `pedido_id`, `importe`, `created_at` (sin `estado_id`); el estado vive en **`validaciones_pagos`** (entidad conceptual `PaymentValidationResult`, 1:1 con `pagos`, con `estado_id`, `proveedor_id`, `referencia_proveedor`, `importe`), cuyo catálogo es **`validaciones_pagos_estados`** (renombramiento de `pagos_estados`). También están creadas `casos_revision` con sus catálogos, `productos_imagenes` y los 6 estados de `pedidos_estados`. Se verifica además la **ausencia** de `reservas`, de `stock_comprometido`, de tablas de carrito y de `pedidos.importe_total`.
>
> Los cambios se aplicaron directamente sobre MariaDB: **no existen archivos `.sql` ni migraciones versionadas** en el proyecto.

Pendiente:

- ~~definir el detalle de la API~~ **RESUELTO e implementado** (los endpoints conceptuales del MVP están definidos y sus contratos HTTP implementados y verificados; ver `ECOMMERCE_TECHNICAL_DESIGN.md`, secciones 15.4, 17.2 y 19.4);
- ~~definir los controllers y el detalle de cada componente~~ **RESUELTO** para los seis bloques implementados (ver `ECOMMERCE_TECHNICAL_DESIGN.md`, secciones 19 y 21); queda el detalle de los componentes aún no implementados;
- definir el flujo técnico completo del carrito (decidido: exclusivamente frontend en `sessionStorage`, sin persistencia en el backend y sin `checkout_id`);
- ~~definir el flujo completo del checkout, incluida la validación comercial y la corrección de carrito~~ **RESUELTO e implementado** (reglas cerradas en `ECOMMERCE_DESIGN.md`, secciones 8.6 y 8.7);
- definir el detalle técnico de la recuperación de pedidos pendientes (reglas de negocio cerradas; ver `ECOMMERCE_DESIGN.md`, sección 11.2);
- ~~**implementación del mecanismo de verificación de email**~~ **RESUELTO e implementado el 29/09/2026** (estructura de `emails_verificaciones`, ciclo de vida del token, expiración, reenvío/cooldown, rotación de token, uso único, relación con `pedidos.email_verificacion_id`, separación de responsabilidades y flujo de confirmación: cerrados y existentes en la base de datos — ver `ECOMMERCE_DESIGN.md`, sección 11.1, y `ECOMMERCE_TECHNICAL_DESIGN.md`, secciones 15, 15.3 y 15.4); pendiente: envío real del email;
- ~~definir el detalle de implementación de `DummyPaymentProvider`~~ **RESUELTO e implementado el 30/09/2026** (contrato `PaymentProvider`, normalización y reglas ya cerrados; simulación mediante el parámetro opcional `simulacion` del request — `APROBADO` por defecto o `RECHAZADO`, y hasta el 05/10/2026 también `EN_PROCESO`: desde esa fecha el conjunto admitido son sólo `APROBADO`/`RECHAZADO` y `EN_PROCESO` se rechaza con `400 simulacion inválida` —); la integración real con Mercado Pago (`MercadoPagoProvider`, webhook y su modalidad de checkout — Checkout Pro vs Checkout API/Orders, detalle de implementación) es **POST-MVP**;
- ~~definir los detalles técnicos de **código** sobre `validaciones_pagos`, `proveedores` y `casos_revision`~~ **RESUELTO e implementado el 30/09/2026** (las tablas y sus catálogos ya existen; el código vive en `PagoRepository`/`PaymentValidationService`; ver `ECOMMERCE_DESIGN.md`, secciones 13.1 y 13.8, y `ECOMMERCE_TECHNICAL_DESIGN.md`, sección 21);
- ~~definir la creación del pedido~~ **RESUELTO e implementado** (reglas de negocio cerradas);
- ~~definir la confirmación del pago, incluida la generación y resolución de los casos de revisión~~ **RESUELTO e implementado el 30/09/2026** (reglas cerradas y verificadas: generación de `PAGADO_STOCK_NO_AFECTADO` y resolución de casos ante resultados definitivos; ver `ECOMMERCE_DESIGN.md`, sección 13.8);
- ~~definir los códigos y formatos de error HTTP~~ **RESUELTO** (clasificación cerrada en `ECOMMERCE_TECHNICAL_DESIGN.md`, sección 19.5; contratos implementados);
- definir los aspectos de seguridad y CORS;
- definir la administración MVP;
- definir la configuración de producción;
- ~~definir la implementación de idempotencia y trazabilidad de las interacciones con el proveedor en el MVP~~ **RESUELTO e implementado el 30/09/2026** (reglas cerradas y verificadas; ver `ECOMMERCE_TECHNICAL_DESIGN.md`, secciones 12.6 y 19.6); el webhook es POST-MVP junto con la integración real;

Una vez completado el diseño técnico del MVP se podrá comenzar la implementación funcional de la Etapa 3.

---

## Etapa 3 — Implementación del MVP

Estado:

```text
EN PROGRESO — implementación incremental
(bloques 1-7 verificados: catálogo, checkout, verificación de email,
 rutas {id}, vencimiento del primer período, cancelación e inicio de
 pago con DummyPaymentProvider; resto pendiente: carrito/store frontend,
 recuperación y confirmación)
```

Objetivo:

Implementar funcionalmente el MVP definido y diseñado en las etapas anteriores.

Flujo principal:

```text
Productos
   ↓
Store
   ↓
Carrito
   ↓
Checkout (se crea el Pedido)
   ↓
Verificación de email
   ↓
Pago (proveedor simulado: DummyPaymentProvider)
   ↓
Confirmación
```

La implementación deberá contemplar:

- gestión de productos;
- disponibilidad y stock (cálculo en caliente, sin reservas);
- carrito unificado;
- checkout *(implementado y verificado el 29/09/2026)*;
- verificación de email *(implementado y verificado el 29/09/2026; canal `log` de DEV implementado el 03/10/2026 — decisión del 02/10/2026 —; sigue pendiente el canal real de envío)*;
- corrección del carrito con aceptación/sustitución, cancelación voluntaria y vencimiento del primer período *(implementado y verificado el 30/09/2026)*;
- procesamiento del pago *(implementado y verificado el 30/09/2026: `DummyPaymentProvider` + `PaymentValidationService` + `PagoRepository`, con creación de `Pago`, transición a `PROCESANDO_PAGO`, aplicación de `APROBADO`/`RECHAZADO` y afectación de stock — `EN_PROCESO` excluido del simulador desde el 05/10/2026, ver `ECOMMERCE_TECHNICAL_DESIGN.md`, sección 21 —; quedan POST-MVP el webhook/`MercadoPagoProvider` y el segundo período automatizado)*;
- creación del pedido al iniciar el checkout y su ciclo de vida;
- confirmación de la compra.

El objetivo será obtener una primera versión funcional del MVP que permita realizar una compra completa de principio a fin en el entorno local.

---

## Etapa 4 — Pruebas

Probar como mínimo:

### Compra de merchandising

```text
Store
→ Producto
→ Carrito
→ Checkout (se crea el Pedido)
→ Email
→ Pago
→ Confirmación
```

### Disponibilidad y stock

Probar:

```text
stock físico > 0 → disponible
stock físico = 0 → agotado
dos clientes comprando la última unidad al mismo tiempo → solo uno crea el Pedido; el otro es informado de la disponibilidad actual
```

### Verificación de email

Probar:

```text
pedido sin verificación → no se inicia el pago
verificación expirada → reenvío
token incorrecto → rechazo
```

**Ejecutado parcialmente el 29/09/2026** (20/20 OK) con el bloque de verificación implementado: token incorrecto → rechazo (`400 token_invalido`); token expirado → rechazo (`400 token_expirado`); reenvío bajo cooldown → `429`; reenvío permitido → token nuevo, anterior inválido; confirmación → `200` + `PEND_VERIF → PEND_PAGO` + stock intacto.

**Ejecutado parcialmente el 30/09/2026** (18/18 OK, enfoque de servicio + 5 aserciones HTTP) con la corrección N:1: un solo `Pedido` vigente → `PEND_PAGO` con `pedidos[]` de 1 elemento; una misma verificación con 4 `Pedido` (vigente → `PEND_PAGO`, vencido → `NO_PAGADO`, `PEND_PAGO` y `NO_PAGADO` intactos); repetición del token → `confirmado` con `pedidos: []` (idempotencia, estados sin cambios); verificación sin `Pedido` → `confirmado` con `pedidos: []`; token inválido → rechazo; límites 9 minutos (vigente) y 11 minutos (vencido); contrato HTTP `200` con `pedidos[]` y sin campo `pedido` singular + `400` ante cuerpo sin token. Limpieza verificada contra baseline (`pedidos`, `pedidos_items` y `emails_verificaciones` vacíos; 6 productos).

**Ejecutado el 03/10/2026** con el canal de email `log` implementado: creación de `Pedido` en `PEND_VERIF` con el email y el enlace registrados en `error_log` por `LogEmailSender` y `last_sent_at` escrito (hoy sí se registra, a diferencia del estado anterior sin MTA); reenvío bajo cooldown → `429 cooldown_activo`; tras el cooldown → `200 reenviado` con rotación de token (token anterior → `400 token_invalido`); token nuevo → `200 confirmado` con `verified_at` y `PEND_VERIF → PEND_PAGO`; reutilización del token confirmado → `200 confirmado` con `pedidos: []` (idempotencia). Durante las pruebas se ajustó operativamente el primer período a **40 minutos** (parámetro no definitivo) para que el `Pedido` no venzca antes del primer reenvío.

Quedan pendientes una verificación expirada generada en tiempo real (la expiración se probó con fixture de `expires_at`); el caso "pedido sin verificación → no se inicia el pago" **ya está cubierto y probado** desde el 30/09/2026 (`iniciar_pago` sobre un `Pedido` en `PEND_VERIF` responde `409 pedido_no_pendiente_de_pago`; ver sección 14).

### Vencimiento, cancelación e inicio de pago

Probar (primer período con su duración parametrizada — **10 minutos** en la ejecución del 30/09/2026 y **40 minutos** operativos desde el 03/10/2026, ajuste no definitivo; ver sección 2.1 —, `PEND_VERIF`/`PEND_PAGO`):

```text
pedido vencido → NO_PAGADO y 409 pedido_vencido en cancelar/pago/reenvio
cancelar Pedido activo → NO_PAGADO, disponibilidad liberada, items intactos
cancelar dos veces → 409 pedido_no_activo
iniciar_pago con carrito vigente → aprobación/rechazo/en_proceso según `simulacion` (la respuesta `200 validado` de la primera versión ya no existe)
iniciar_pago con precio/disponibilidad alterados → 200 correccion, Pedido intacto
aceptar_correccion válido → anterior NO_PAGADO + nuevo con mismo estado y email_verificacion_id (una sola transacción)
aceptar_correccion con nuevas diferencias → correccion sin sustituir
rutas sin {id} o método equivocado → 404 / 405 con Allow / 400 pedido_id inválido
```

**Ejecutado el 30/09/2026:** **49/49 OK** a nivel de servicio (`test_bloque_pago.php`, adaptado al contrato del bloque de pagos: vencimiento al calcular disponibilidad, cancelación, `iniciar_pago` con casos válidos/precio/disponibilidad/vencidos/no-activos/`EN_PROCESO`, `aceptar_correccion` con sustitución y sin sustitución, herencia de `PEND_VERIF`/`PEND_PAGO`/`email_verificacion_id` y reintentos) + **26/26 OK** vía HTTP (`http_tests2.php`: router `{id}`, `405`/`404`/`400`, flujo completo creación → `iniciar_pago` → `aceptar_correccion` → `cancelar`, vencidos y regresión de verificación N:1) + **18/18 OK** de regresión del bloque N:1 (`test_verificacion_n1.php`). Limpieza verificada contra baseline (`pedidos`, `pedidos_items` y `emails_verificaciones` vacíos; 6 productos con precios originales).

### Pago

Probar con `DummyPaymentProvider` (proveedor simulado del MVP):

```text
pago aprobado → Pedido PAGADO y stock descontado una sola vez
pago rechazado → Pedido vuelve a PEND_PAGO, reintento permitido si quedan intentos y el primer período no venció
simulacion=EN_PROCESO → 400 simulacion inválida, Pedido intacto en PEND_PAGO, sin Pago ni validaciones_pagos ni casos_revision (desde el 05/10/2026; el simulador sólo admite APROBADO/RECHAZADO)
alcanzado PAGO_MAX_INTENTOS → Pedido NO_PAGADO
notificación duplicada → sin doble confirmación ni doble descuento
segundo período vencido → Pedido permanece en PROCESANDO_PAGO y se genera un único caso de revisión PAGO_PROCESANDO_EXCEDIDO
APROBADO posterior al vencimiento → Pedido PAGADO, stock descontado una sola vez y caso de revisión RESUELTO
RECHAZADO posterior al vencimiento → caso de revisión RESUELTO y Pedido PEND_PAGO (o NO_PAGADO si se alcanzó el máximo)
APROBADO con importe distinto del esperado → resultado registrado, stock sin afectar, sin paso a PAGADO y caso de revisión generado
```

**Ejecutado el 30/09/2026:** **46/46 OK** a nivel de servicio (`test_bloque_pagos.php`: aprobado completo con descuento único de stock, rechazo → `PEND_PAGO` con reintento, límite de 3 intentos → `NO_PAGADO`, guardas `maximo_intentos_alcanzado`/`pago_ya_aprobado`, `EN_PROCESO` sin afectar stock, idempotencia de resultados definitivos, importe inconsistente → caso `PAGADO_STOCK_NO_AFECTADO`, stock insuficiente → `PAGADO_STOCK_NO_AFECTADO`, resolución de casos en el rechazo y validaciones de proveedor/simulación/carrito) + **25/25 OK** vía HTTP (`http_tests3.php`: validación del body, corrección `carrito_desactualizado`, aprobado/rechazado/en_proceso punta a punta, guardas y vencimiento) + regresiones completas en verde: **49/49** (`test_bloque_pago.php`), **26/26** (`http_tests2.php`) y **18/18** (`test_verificacion_n1.php`). Limpieza verificada contra baseline (`pedidos`, `pedidos_items`, `emails_verificaciones`, `pagos`, `validaciones_pagos` y `casos_revision` vacíos; 6 productos con precios y stocks originales: `1:10, 2:15, 3:12, 4:8, 5:20, 6:10`).

> El segundo período automatizado (`PAGO_PROCESANDO_EXCEDIDO` generado por tiempo) y la confirmación de resultados `EN_PROCESO` no se prueban aquí: no existen en el MVP (alcance cerrado) y, **desde el 05/10/2026, `simulacion=EN_PROCESO` además se rechaza con `400 simulacion inválida`**, de modo que el simulador del MVP no puede producir un resultado `EN_PROCESO` (decisión registrada en `ECOMMERCE_TECHNICAL_DESIGN.md`, sección 21; ver también `ECOMMERCE_DESIGN.md`, secciones 8.8 y 13.8 y `ECOMMERCE_TECHNICAL_DESIGN.md`, secciones 13.3 y 20). La **resolución** de ese caso ante un resultado definitivo sí está implementada y probada.

---

# 26. Etapa 5 — Primera versión pública

Objetivo:

Disponer de una versión que permita realizar ventas reales.

Criterio de finalización:

```text
Un usuario puede comprar un producto.
```

El sistema deberá:

```text
crear Pedido (PEND_VERIF)
→ verificar email (PEND_PAGO)
→ comprobar disponibilidad e iniciar pago (PROCESANDO_PAGO)
→ pago aprobado (PAGADO, stock descontado)
→ confirmar compra
```

---

# 27. Etapa 6 — Fulfillment y entregas

Después de estabilizar el MVP se incorporará:

- fulfillments;
- entregas físicas;
- entregas digitales;
- retiro en show;
- retiro en punto;
- envío a domicilio;
- tracking cuando corresponda.

El objetivo será permitir que un mismo pedido pueda requerir diferentes formas de cumplimiento.

---

# 28. Etapa 7 — Entradas, stock avanzado y abastecimiento

Incorporar:

- productos asociados a shows (entradas);
- próximo show con compra de entrada;
- stock avanzado;
- productos bajo demanda (on-demand);
- proveedores;
- abastecimiento;
- órdenes de compra;
- relación entre la demanda del cliente y el abastecimiento.

La disponibilidad mediante stock físico y cálculo en caliente de cantidades comprometidas ya existirá desde el MVP. Esta etapa incorporará el comportamiento operativo que permita, en el futuro, cumplir ventas sin stock.

---

# 29. Etapa 8 — Cuentas y usuarios

Incorporar el sistema de usuarios definido en `ECOMMERCE_DESIGN.md`:

- sistema unificado de usuarios con roles (por ejemplo, `CLIENTE` y `ADMIN`);
- un usuario registrado y verificado podrá comprar sin volver a verificar su email en cada compra;
- el checkout como invitado seguirá existiendo.

---

# 30. Etapa 9 — Cancelaciones, devoluciones y reembolsos

Incorporar:

```text
Cancelaciones
Devoluciones
Reembolsos
```

manteniendo la separación conceptual definida en `ECOMMERCE_DESIGN.md`.

Deberán contemplarse:

- cancelación total;
- cancelación de items;
- cancelación parcial de cantidades;
- origen de la cancelación;
- motivos;
- reglas de cancelabilidad;
- devoluciones;
- reemplazos;
- reembolsos;
- relación entre cancelación y reembolso.

---

# 31. Etapa 10 — Administración y estabilización

Incorporar progresivamente la administración avanzada:

- administración completa de productos;
- administración de shows;
- administración de entradas;
- administración de stock y disponibilidad;
- gestión de pedidos;
- gestión de fulfillments;
- gestión de entregas;
- gestión de cancelaciones;
- gestión de devoluciones;
- gestión de reembolsos;
- proveedores;
- reportes;
- métricas;
- promociones.

Luego:

```text
Pruebas
    ↓
Correcciones
    ↓
Estabilización
    ↓
Auditoría técnica
```

Se revisará:

- seguridad;
- consistencia de datos;
- integridad de pedidos;
- pagos;
- stock;
- concurrencia;
- errores;
- recuperación ante fallos;
- logs;
- validaciones;
- experiencia de usuario;
- rendimiento;
- mantenibilidad.

---

# 32. Hitos principales

Los principales hitos serán:

```text
HITO 1
Diseño conceptual definido
```

```text
HITO 2
MVP definido
```

```text
HITO 3
Diseño técnico del MVP definido
```

```text
HITO 4
Primera compra funcionando en entorno de prueba
```

```text
HITO 5
Primera compra real funcionando
```

```text
HITO 6
Sistema público inicial
```

```text
HITO 7
Fulfillment y entregas
```

```text
HITO 8
Entradas, stock avanzado y abastecimiento
```

```text
HITO 9
Cuentas y usuarios
```

```text
HITO 10
Cancelaciones, devoluciones y reembolsos
```

```text
HITO 11
Administración y estabilización
```

---

# 33. Criterio general de evolución

Cada etapa deberá agregar funcionalidad sin romper los conceptos establecidos anteriormente.

La regla será:

```text
No implementar anticipadamente toda la complejidad futura.
No tomar decisiones que impidan implementar esa complejidad posteriormente.
```

El MVP debe ser simple en funcionamiento, pero correcto en sus conceptos fundamentales.

---

## 34. Próximo paso

El próximo paso del proyecto será continuar con:

```text
ETAPA 2 — DISEÑO TÉCNICO DEL MVP
```

Secuencia prevista del trabajo inmediato (actualizada el 30/09/2026; la implementación comenzó de forma incremental):

```text
Bloques implementados y verificados: GET /api/productos, POST /api/pedidos,
verificación de email (validar + reenvio), rutas {id}, vencimiento del
primer período, POST /api/pedidos/{id}/cancelar,
POST /api/pedidos/{id}/pago, bloque de pagos con DummyPaymentProvider
y canal de email `log` de DEV (03/10/2026)
        ↓
Detalle técnico e implementación incremental del resto del MVP
(recuperación; entrega real del email)
        ↓
ETAPA 3 — IMPLEMENTACIÓN DEL MVP (continuada incrementalmente)
```

En esta etapa se deberá completar la definición técnica necesaria para continuar la implementación funcional.

Se deberá definir (los puntos marcados ya están resueltos para los ocho bloques implementados):

- API y endpoints *(parcial: `GET /api/productos`, `POST /api/pedidos`, los dos endpoints de verificación de email, `POST /api/pedidos/{id}/cancelar` y `POST /api/pedidos/{id}/pago` definidos e implementados; resto pendiente)*;
- controllers, models y services *(parcial: `ProductoController`/`PedidoController`/`EmailVerificationController`, `ProductoService`/`PedidoService`/`EmailVerificationService`/`PaymentValidationService` y `PaymentValidationResult` implementados; models/entities restantes y servicios pendientes)*;
- flujo técnico del carrito (decidido: exclusivamente frontend en `sessionStorage`);
- flujo del checkout, validación comercial y corrección de carrito *(parcial: creación del `Pedido` con validación comercial en transacción y corrección con aceptación/sustitución implementadas; flujo de carrito frontend y recuperación pendientes)*;
- detalle técnico de la recuperación de pedidos pendientes;
- **detalle de implementación de la verificación de email** (**cerrado, implementado y verificado** el 29/09/2026: estructura de `emails_verificaciones` con `last_sent_at`, responsabilidades del servicio, separación de responsabilidades y contrato HTTP; ver `ECOMMERCE_TECHNICAL_DESIGN.md`, secciones 15 y 15.4; pendientes del bloque: canal de envío real y URL/asunto/plantilla definitivos (el canal de email `log` de DEV — decisión del 02/10/2026 — está implementado desde el 03/10/2026);
- ~~detalle técnico de los pagos del MVP~~ (**cerrado e implementado y verificado** el 30/09/2026: frontera `PaymentValidationService → PaymentProvider → DummyPaymentProvider`, `PagoRepository`, creación de `Pago`, transición a `PROCESANDO_PAGO`, aplicación de resultados con afectación de stock e idempotencia; la integración real con Mercado Pago es POST-MVP);
- manejo del stock y de la disponibilidad *(parcial: disponibilidad en caliente, vencimiento del primer período y descuento de stock con pago aprobado implementados; falta sólo el frontend/carrito)*;
- creación y confirmación del pedido *(parcial: creación, verificación `PEND_VERIF → PEND_PAGO`, cancelación voluntaria, vencimiento, 3.ª validación e inicio/aplicación del pago con `DummyPaymentProvider` implementados; falta la confirmación post-compra/email y la recuperación)*;
- email post-pago;
- errores y códigos *(parcial: definidos para los siete endpoints implementados)*;
- seguridad y CORS;
- administración MVP;
- configuración de producción.

Una vez finalizada la Etapa 2, se podrá comenzar:

```text
ETAPA 3 — IMPLEMENTACIÓN DEL MVP
```

---

## 35. Clasificación del estado de las decisiones

### CERRADO

- alcance del MVP (merchandising, música y productos generales de la tienda);
- ecosistema de tres proyectos (`D:\Incancelables Web`, `D:\Incancelables Backend`, `D:\Incancelables E-Commerce`) y separación frontend/backend del e-commerce;
- separación de ecosistemas durante el MVP: newsletter en Apps Script → Google Sheets, e-commerce en PHP → MariaDB, sin integración;
- arquitectura de capas del backend (`Routes/Controller/Service/Repository/MariaDB` + Config + Model);
- Services y Repositories del MVP (listas cerradas);
- producto genérico sin `tipo_producto`; `productos_imagenes` 1:N (estructura preparada);
- `pedidos` (`id`, `email`, `nombre`, `apellido`, `estado_id`, `email_verificacion_id`, `created_at`, `updated_at`) y `pedidos_items` (`pedido_id`, `producto_id`, `cantidad`, `precio_unitario`; items inmutables);
- total del pedido calculado dinámicamente (`Σ(cantidad × precio_unitario)`, sin `importe_total`);
- estados del `Pedido`: `PEND_VERIF`, `PEND_PAGO`, `PROCESANDO_PAGO`, `PAGADO`, `NO_PAGADO`, más `PAGADO_STOCK_NO_AFECTADO` excepcional;
- estados que afectan disponibilidad: `PEND_VERIF`, `PEND_PAGO`, `PROCESANDO_PAGO`;
- disponibilidad calculada en caliente (sin `Reserva`, sin `stock_comprometido`, sin `StockService`/`AvailabilityService`);
- carrito exclusivamente frontend en `sessionStorage` (sin `checkout_id`);
- validación comercial siempre repetida; verificación de email una sola vez por proceso de identidad;
- recuperación de un único Pedido pendiente (casos A/B/C);
- corrección de carrito con `Pedido` existente: detectar diferencias **NO** cambia el estado del `Pedido` (conserva su estado y afecta la disponibilidad mientras el usuario decide); respuesta con carrito corregido propuesto + precio y disponibilidad del **catálogo completo activo** + motivos; al aceptar, **en la misma operación/transacción** el anterior → `NO_PAGADO` y se crea el reemplazante con el mismo estado previo; nuevas diferencias → nueva corrección sin sustitución; reemplazante `PEND_PAGO` vuelve a pasar por la 3.ª validación **(implementado el 30/09/2026)**;
- cancelación voluntaria del checkout (`POST /api/pedidos/{id}/cancelar`): `PEND_VERIF`/`PEND_PAGO` → `NO_PAGADO` inmediato, liberando disponibilidad; sirve para rechazar una corrección; sin timeout propio; cancelaciones post-venta fuera del MVP **(implementado y verificado el 30/09/2026)**;
- vencimiento del primer período aplicado de forma **perezosa** con `NOW()` de MariaDB como autoridad (sin proceso programado, sin columna `expires_at`): barrido al calcular disponibilidad, al crear un `Pedido` y en las operaciones de `pago`, más bloqueo `FOR UPDATE` por `Pedido` con respuesta `409 pedido_vencido`; rutas paramétricas `{id}` en el router con `405` + `Allow` y `400 pedido_id inválido` **(implementado y verificado el 30/09/2026)**;
- `POST /api/pedidos/{id}/pago` con `accion` (`iniciar_pago` / `aceptar_correccion`): `iniciar_pago` recibe `proveedor_id`, `items[]` y opcionalmente `simulacion`, ejecuta la 3.ª validación sobre el carrito recibido (que debe coincidir con los items persistidos), crea el `Pago` con su `PaymentValidationResult` y pasa el `Pedido` a `PROCESANDO_PAGO`, y la segunda fase persiste y aplica el resultado del proveedor (la respuesta `validado` de la primera versión ya no existe); `aceptar_correccion` recibe `items[]` y sustituye el `Pedido` en una transacción **(implementado y verificado el 30/09/2026, con el bloque de pagos)**;
- `pagos` (`id`, `pedido_id`, `importe`, `created_at`; sin `estado_id`);
- `PaymentValidationResult` 1:1 con `Pago`; tabla **`validaciones_pagos`**; catálogo **`validaciones_pagos_estados`** (`PENDIENTE`, `EN_PROCESO`, `APROBADO`, `RECHAZADO`, `CANCELADO`);
- catálogo `proveedores` (`id`, `codigo`) con las filas `MERCADOPAGO` y `DUMMY`; en el MVP las validaciones referencian `DUMMY`;
- Mercado Pago como único medio de pago concebido; frontera `PaymentValidationService → PaymentProvider → DummyPaymentProvider` (implementación **simulada** del MVP, ejercitando `APROBADO` y `RECHAZADO`; `EN_PROCESO` excluido del simulador desde el 05/10/2026, ver `ECOMMERCE_TECHNICAL_DESIGN.md`, sección 21); confirmación por validación contra el proveedor, sin polling permanente; identidad de proveedor en el catálogo `proveedores`: **`DUMMY`** en el MVP (`DummyPaymentProvider → DUMMY`) y `MERCADOPAGO` para `MercadoPagoProvider` (integración real, POST-MVP);
- `CasoRevision` ligado al `Pago` (columnas `id`, `pago_id`, `tipo_id`, `estado_id`, `created_at`, `updated_at`; sin `descripcion`; sin `UNIQUE(pago_id, tipo_id)`), catálogos `casos_revision_tipos`/`casos_revision_estados` con `orden` y `activo`, tipos `PAGADO_STOCK_NO_AFECTADO`/`PAGO_PROCESANDO_EXCEDIDO` y estados `PENDIENTE`/`RESUELTO`;
- dos ventanas temporales parametrizables (primer período con valor inicial **10 minutos**, calculado desde `pedidos.created_at`, con `expires_at` informativo para el frontend) y `PAGO_MAX_INTENTOS`;
- mecanismo genérico de verificación de email agnóstico de contexto (sin campo `contexto`), tomando como referencia la lógica auditada del backend histórico Apps Script (sin migrar su modelo de datos);
- estructura física de `emails_verificaciones`: `id`, `email`, `token_hash`, `expires_at`, `verified_at`, `created_at`, `last_sent_at`; PK `id`; índice no único `idx_emails_verificaciones_email (email)`; sin `estado_id` (estado derivado de los datos); sin `pedido_id`/`contexto`/`tipo`/`uso`/`origen`; `email` NO es UNIQUE; registros completados conservados; FK unidireccional `pedidos.email_verificacion_id → emails_verificaciones.id`; estructura e índice ya existentes en la base de datos actual, más `last_sent_at` (única ampliación de esquema, aplicada el 29/09/2026);
- responsabilidades del servicio de verificación: hash SHA-256 del token (token aleatorio de 256 bits, sólo en claro hacia el consumidor), expiración (referencia 7 días), cooldown (referencia 15 minutos, sobre `last_sent_at`), **rotación del token en cada reenvío permitido** (el anterior queda inválido; sin reutilizar el vigente; lógica interna; el consumidor no elige la operación), validación, registro de `verified_at` y devolución del resultado;
- inicio de la verificación dentro de `POST /api/pedidos` (opción A) después del `COMMIT`, con fallo de envío ≠ rollback del `Pedido`; endpoints `POST /api/email-verificaciones/validar` y `POST /api/email-verificaciones/reenvio` con su contrato HTTP (ver `ECOMMERCE_TECHNICAL_DESIGN.md`, sección 15.4); **no existe** `POST /api/email-verificaciones` (solicitud); transición `PEND_VERIF → PEND_PAGO` a cargo de `PedidoService`, sin nueva comprobación de stock; desde el 30/09/2026 la confirmación es N:1 — procesa **todos** los `Pedido` asociados (vigentes → `PEND_PAGO`, vencidos → `NO_PAGADO`, resto intactos) y responde `pedidos[]`;
- separación de responsabilidades: el mecanismo no envía emails (cada consumidor envía el suyo; en el MVP `PedidoService` envía el email de verificación del checkout), el mecanismo registra `verified_at` en la confirmación y el consumidor decide la acción de negocio (sin callbacks ni eventos);
- canales de email por entorno (**decisión cerrada el 02/10/2026**): **DEV** con **canal `log`** (token real, sólo hash persistido, enlace real registrado en log para las pruebas, entrega considerada exitosa sin transporte SMTP y por lo tanto con registro de `last_sent_at`; infraestructura de desarrollo que no reemplaza el envío real) y **MVP/producción** con **canal real de envío** obligatorio antes de cerrar el proyecto (**no** se termina el MVP si la verificación depende de leer manualmente un log), distinguidos **mediante configuración** y sin cambiar la lógica de negocio de verificación (**implementado el 03/10/2026** para el canal `log`: `src/mail/EmailSender.php`/`LogEmailSender.php`, `src/config/email.php` y selección/inyección en `public/index.php`; el canal real sigue pendiente).

### PENDIENTE DE DEFINICIÓN

- ~~detalle de la API para los endpoints restantes (`POST /api/pedidos/{id}/pago` y `POST /api/pedidos/{id}/cancelar`)~~ **RESUELTO el 30/09/2026**: ambos endpoints están implementados y verificados con su contrato HTTP — incluido el bloque de pagos de `POST /api/pedidos/{id}/pago` (`proveedor_id`, creación de `Pago`, transición a `PROCESANDO_PAGO` y resultados del proveedor) — (ver secciones 11 y 14 de este documento y `ECOMMERCE_TECHNICAL_DESIGN.md`, secciones 17.2, 19.4 y 21);
- asunto, plantilla y URL definitivos del email de verificación del checkout (lo envía el consumidor, `PedidoService`; el mecanismo no define su contenido; variante mínima en uso: asunto "Confirmá tu compra en Incancelables" y URL destino `http://127.0.0.1:5500/pages/resultado.html` — **desde el 05/10/2026 sin hardcodear en `PedidoService`**: proviene de `src/config/frontend.php`, se valida e inyecta en `public/index.php` y el enlace se arma con `?flow=checkout&token=...`; para el MVP es configuración directa, sin selector DEV/PROD —, a confirmar con el frontend) y canal de envío real del email (**requisito para dar por terminado el MVP**; hoy la entrega en DEV/MVP funciona con el canal `log` — `mail()` ya no se invoca —, pero no existe transporte real); ~~el canal de email `log` de DEV~~ **RESUELTO e implementado el 03/10/2026**: detalle cerrado y aplicado — registra el email compuesto (destinatario, asunto, cuerpo y enlace) en `error_log` vía `LogEmailSender`, canal elegido por `src/config/email.php` y composition root en `public/index.php`;
- detalle técnico del carrito frontend y de la recuperación de pedidos pendientes (la creación del `Pedido`, la corrección con aceptación/sustitución, la cancelación y la 3.ª validación ya están definidas e implementadas);
- ~~detalle de implementación de `DummyPaymentProvider`~~ **RESUELTO el 30/09/2026** (simula `APROBADO` por defecto o el resultado `simulacion` del request — `RECHAZADO` (y `EN_PROCESO` hasta el 05/10/2026, fecha en que quedó excluido del simulador) — con `referencia_proveedor = DUMMY-{pagoId}`; los resultados admitidos atraviesan todo el flujo punta a punta);
- seguridad y CORS;
- administración MVP;
- configuración de producción.

### PENDIENTE DE IMPLEMENTACIÓN

*(El esquema ya está materializado en la base de datos; lo pendiente corresponde a la capa de código.)*

- el resto de la capa de aplicación: recuperación de pedidos pendientes, models/entities restantes y administración *(ya implementados y verificados: front controller con rutas `{id}`, routing, `ProductoController`/`PedidoController`/`EmailVerificationController`, `ProductoService`/`PedidoService`/`EmailVerificationService`/`PaymentValidationService`, `ProductoRepository`/`PedidoRepository`/`PagoRepository`/`EmailVerificationRepository`, `PaymentValidationResult` + `PaymentProvider`/`DummyPaymentProvider`/`PaymentProviderException`, endpoints `GET /api/productos`, `POST /api/pedidos`, `POST /api/email-verificaciones/validar`, `POST /api/email-verificaciones/reenvio`, `POST /api/pedidos/{id}/cancelar` y `POST /api/pedidos/{id}/pago` con el bloque de pagos completo; además, vencimiento del primer período y corrección con aceptación/sustitución)*;
- ~~revisar el comportamiento de `PedidoRepository::buscarPorEmailVerificacion()` (devuelve **un solo** `Pedido`, pero una misma verificación puede ser reutilizada por varios `Pedido`)~~ **RESUELTO el 30/09/2026**: método reemplazado por `buscarTodosPorEmailVerificacion()` (todos los `Pedido` asociados, `ORDER BY p.id`, con `created_at` y `NOW()`); confirmación N:1 completa (vigentes → `PEND_PAGO`, vencidos → `NO_PAGADO`, resto intacto, respuesta `pedidos[]`);
- ~~canal de email `log` de DEV~~ **RESUELTO e implementado el 03/10/2026** (decisión cerrada el 02/10/2026; canal de desarrollo que registra el email compuesto en `error_log`, considera la entrega exitosa y registra `last_sent_at`, sin envío SMTP real): `src/mail/EmailSender.php` (contrato `enviar(para, asunto, cuerpo): bool`), `src/mail/LogEmailSender.php` (canal `log`), `src/config/email.php` (`channel = log`) y selección/inyección en `public/index.php`; habilita y verificó las pruebas de reenvío, rotación, cooldown, `200 reenviado` y `429 cooldown_activo`;
- entrega real del email de verificación (canal SMTP u otro; **requisito para dar por terminado el MVP**; hoy en DEV/MVP la entrega funciona por el canal `log` — `mail()` ya no se invoca, sin MTA/SMTP/APIs/credenciales — y `last_sent_at` sólo se escribe cuando la entrega, según el canal configurado, tiene éxito);
- flujo completo de pago y confirmación (la creación del `Pedido`, la verificación de email con transición `PEND_VERIF → PEND_PAGO`, la cancelación voluntaria, el vencimiento del primer período, la corrección con aceptación y el **inicio/aplicación del pago con `DummyPaymentProvider`** ya están implementados; falta la confirmación post-compra con email y la recuperación);
- configuración por entorno (hoy `src/config/database.php` tiene la configuración de la base **hardcodeada**, `src/config/email.php` define el canal de email — `channel = log`, implementado el 03/10/2026 — y `src/config/frontend.php` define la URL destino de la verificación de checkout — `url_verificacion_checkout`, configuración MVP directa sin selector DEV/PROD, implementado el 05/10/2026 —; no existe `.env`; la conmutación al canal real deberá resolverse por esa misma configuración);
- ~~idempotencia y trazabilidad de las interacciones con el proveedor~~ **RESUELTO e implementado el 30/09/2026** (verificado en las suites del bloque de pagos; webhook POST-MVP);
- email post-pago.

### POST-MVP

- integración de pagos **real** con Mercado Pago: `MercadoPagoProvider` (modalidad de checkout) y webhook `POST /api/webhooks/mercadopago`, sobre el mismo contrato `PaymentProvider` y sin modificar la lógica de negocio del pago;
- migración del newsletter a PHP/MariaDB (hoy: Apps Script → Google Sheets, sin integración con el e-commerce);
- reutilización del mecanismo genérico de verificación de email por otros consumidores (newsletter, registro de cuentas, recuperación de contraseña);
- registro de cuentas y sistema unificado de usuarios (`CLIENTE`/`ADMIN`);
- entradas/tickets y funcionalidades de shows;
- venta sin stock / on-demand;
- fulfillment, entregas, tracking;
- cancelaciones, devoluciones y reembolsos;
- promociones, historial de precios, historial de movimientos de stock;
- resolución administrativa de los casos de revisión (fuera del MVP);
- comisiones/fees, impuestos/cargos y liquidación/conciliación;
- autenticación de las notificaciones webhook de Mercado Pago (detalle provider-specific).