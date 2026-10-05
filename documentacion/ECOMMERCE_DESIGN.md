# Diseño funcional y conceptual del e-commerce — Incancelables

> Documento de diseño funcional y de negocio.  
> No define todavía implementación técnica, base de datos, endpoints ni código.
>
> **Estado:** Diseño conceptual — decisiones de negocio cerradas hasta el 28/09/2026  
> **Última actualización:** 3 de octubre de 2026  
>
> **Estado de implementación (03/10/2026):** la base de datos `incancelables_ecommerce` ya materializa el modelo descrito aquí (15 tablas, incluidas `validaciones_pagos`, `casos_revision`, `productos_imagenes` y los 6 estados de `pedidos_estados`; ver `ECOMMERCE_TECHNICAL_DESIGN.md`, sección 9) y contiene 6 productos cargados; la capa de código del MVP está **parcialmente implementada**: bloques `GET /api/productos`, `POST /api/pedidos`, verificación de email (mecanismo + endpoints `/validar` y `/reenvio`), el bloque de vencimiento/cancelación/inicio de pago (`POST /api/pedidos/{id}/cancelar`, `POST /api/pedidos/{id}/pago` con `iniciar_pago`/`aceptar_correccion` y vencimiento perezoso del primer período) y el **bloque de pagos con `DummyPaymentProvider`** (`PaymentValidationService → PaymentProvider → DummyPaymentProvider`, creación de `Pago` + `PaymentValidationResult`, transición a `PROCESANDO_PAGO` y aplicación de `APROBADO`/`RECHAZADO`, con `EN_PROCESO` **excluido del simulador del MVP** desde el 05/10/2026 — ver `ECOMMERCE_TECHNICAL_DESIGN.md`, sección 21 —, incluida la afectación de stock) implementados y verificados (ver `ECOMMERCE_ROADMAP.md`, sección 2.1 y `ECOMMERCE_TECHNICAL_DESIGN.md`, secciones 10.3, 13.2, 15.4, 17.1, 17.2, 19.4 y 21). El resto de la capa de código (recuperación de pedidos pendientes, administración) sigue sin implementarse; la corrección de carrito está implementada como aceptación con sustitución (el `Pedido` es inmutable) y no como edición; del bloque de verificación de email, el canal de email `log` de DEV (decisión del 02/10/2026, sección 11.1) quedó **implementado y verificado el 03/10/2026**, y quedan pendientes el canal real de envío del email y la URL/asunto/plantilla definitivos; del flujo de pago quedan pendientes el webhook/`MercadoPagoProvider` (POST-MVP) y la resolución administrativa de los casos de revisión.

---

## 1. Objetivo

Definir el funcionamiento conceptual del futuro e-commerce de Incancelables antes de comenzar su implementación técnica.

El sistema deberá permitir comercializar productos genéricos de Incancelables, manteniendo una arquitectura suficientemente flexible para incorporar nuevos productos y modalidades de comercialización en el futuro.

**Alcance del MVP:**

* merchandising;
* música;
* productos generales de la tienda.

**Fuera del MVP (evoluciones futuras; ver `ECOMMERCE_ROADMAP.md`):**

* venta de entradas/tickets;
* fulfillment avanzado;
* envíos complejos;
* retiros y puntos de entrega;
* cuentas de usuario;
* carrito persistente entre dispositivos;
* múltiples carritos recuperables;
* promociones complejas;
* devoluciones y reembolsos;
* historial de precios;
* proveedores/sourcing;
* reportes y administración avanzados;
* múltiples proveedores de pago;
* newsletter como funcionalidad del e-commerce;
* recuperación sofisticada de carritos abandonados.

El objetivo de esta etapa es definir:

* qué se puede vender;
* cómo se inicia una compra;
* cómo se compone un pedido;
* cómo se verifica al comprador;
* cómo se procesa el pago;
* cómo se obtiene o abastece un producto;
* cómo se cumple cada parte de un pedido;
* cómo se entrega;
* cómo se realizan cancelaciones, devoluciones y reintegros.

Los cinco primeros puntos corresponden al alcance del MVP. Los cuatro últimos describen conceptos de evoluciones futuras: se conservan en este documento de diseño conceptual, pero **no se implementan en el MVP**.

---

## 1.1. Arquitectura del proyecto

El ecosistema Incancelables se organiza en tres proyectos con responsabilidades claramente separadas:

```text
D:\Incancelables Web        → FRONTEND (HTML/CSS/JS) del sitio principal de Incancelables
D:\Incancelables Backend    → backend histórico en Google Apps Script (newsletter → Google Sheets)
D:\Incancelables E-Commerce → BACKEND (PHP + MariaDB) del e-commerce: API, lógica de negocio y base de datos
```

* El e-commerce, en sí mismo, se separa en frontend y backend con responsabilidades distintas:
  * `D:\Incancelables Web` — frontend, integrado visualmente en el sitio principal;
  * `D:\Incancelables E-Commerce` — backend, API, lógica de negocio y base de datos.
* El frontend **no contiene reglas de negocio**: las reglas de negocio viven en el backend.
* El backend expone la API que consume el frontend.
* `D:\Incancelables Backend` (Apps Script) **no forma parte del e-commerce**: es el backend histórico del sitio principal y sólo se lo utiliza como **referencia** de lógica ya existente (ver sección 11). Nunca se migran sus datos ni su modelo (tablas `Pendientes`, `Comunidad`, `TOKEN_BAJA`, campañas, etc.).

### Separación de ecosistemas y convivencia en el MVP

* **El newsletter no es una funcionalidad del e-commerce** (ver sección 12): durante el MVP, el newsletter continúa en el ecosistema histórico (Apps Script → Google Sheets) y el e-commerce opera con su propio stack (PHP → MariaDB).
* Durante el MVP **no existe integración entre el newsletter y el e-commerce**; los dos ecosistemas conviven sin comunicarse.
* La migración del newsletter a PHP/MariaDB y su posterior uso del mecanismo genérico de verificación de email corresponden a una evolución **POST-MVP**.

```text
Frontend (D:\Incancelables Web)
      ↓
API REST
      ↓
Backend (D:\Incancelables E-Commerce)
      ↓
Base de datos (MariaDB)
```

### Arquitectura de capas del backend (conceptual)

La arquitectura acordada para el backend es:

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

Además existen:

* **Config**: configuración de aplicación, base de datos y variables de entorno;
* **Model / entidades**: representación conceptual/de datos de las entidades del dominio únicamente cuando resulte útil; no se crea un Model artificial sólo porque exista una tabla y no se utiliza un ORM como eje de la arquitectura.

Responsabilidades:

* **Routes / API**: determinan qué Controller atiende cada endpoint; no contienen lógica de negocio.
* **Controller**: recibe HTTP; valida la estructura básica del request; invoca Services; convierte los resultados y errores de aplicación en respuestas HTTP/JSON; no contiene lógica de negocio y no ejecuta SQL directamente.
* **Service**: contiene y coordina la lógica de negocio; es quien decide las reglas y las transiciones; no debe acceder directamente a SQL.
* **Repository**: acceso a persistencia; consulta, inserta y actualiza datos; no decide reglas de negocio.
* **Database**: persistencia e integridad referencial.

**Regla general:** una capa no debe saltarse arbitrariamente las capas inferiores. El flujo normal es `Frontend → Route → Controller → Service → Repository → DB`.

Services del MVP (cerrados): `PedidoService`, `ProductoService`, `EmailVerificationService` y `PaymentValidationService`. No se crean Services adicionales sólo por existir una entidad: no existen en el MVP `PagoService`, `StockService`, `AvailabilityService` ni `ShowService`.

Repositories del MVP (cerrados): `ProductoRepository`, `PedidoRepository`, `PagoRepository` y `EmailVerificationRepository`. No existe `ShowRepository` para el MVP.

Además de las capas de negocio, desde el 03/10/2026 existe una frontera genérica de transporte de email en `src/mail/`: el contrato `EmailSender` (`enviar(para, asunto, cuerpo): bool`) y su implementación para el canal `log` de DEV/MVP (`LogEmailSender`), seleccionada por configuración (`src/config/email.php`) en `public/index.php`. No es una capa de negocio: no conoce `Pedido`, estados ni tokens, y `PedidoService` sólo depende del contrato (ver "Canales de entrega por entorno" en la sección 11.1).

El detalle de responsabilidades está en `ECOMMERCE_TECHNICAL_DESIGN.md` (sección 19).

---

# 2. Concepto de Producto

El concepto de `Producto` debe ser genérico.

No se debe diseñar el sistema pensando exclusivamente en merchandising.

Un producto puede ser, por ejemplo:

* remera;
* gorra;
* vaso;
* CD;
* vinilo;
* entrada para un show;
* futuros productos físicos;
* futuros productos digitales;
* futuros servicios.

El producto representa **qué se comercializa**, independientemente de cómo se obtiene, cómo se entrega o dónde se ofrece al comprador.

El producto es genérico: **NO** se crea un campo `tipo_producto` para diferenciar merchandising, música, tickets, etc. La diferenciación no pertenece al modelo de producto.

En el MVP se comercializan exclusivamente merchandising, música y productos generales de la tienda.

---

## 2.1. Producto y su ciclo de vida

Un producto puede estar **activo** o **inactivo**.

```text
Activo   → se ofrece a la venta
Inactivo → no se ofrece a la venta, sin eliminar el registro
```

Los productos no se eliminan del sistema: se desactivan cuando corresponde.

### 2.1.1. Precio y snapshot de la compra

Cuando cambia el precio de un producto, el `PedidoItem` conserva el **precio aplicado en el momento de la compra**. El precio posterior del producto no modifica los pedidos ya generados.

Para el `PedidoItem` se preserva el precio de la compra en el campo `precio_unitario`; no se copian características nominales que puedan quedar desactualizadas.

`precio_unitario` es el precio histórico del producto para esa operación.

Queda previsto, como evolución futura, mantener un **historial de precios** que registre cada cambio con su fecha y un motivo/observación opcional.

---

## 2.2. Imágenes del producto

Un producto puede tener varias imágenes, con una imagen principal. La relación será `Producto` 1:N `ProductoImagen`.

Para el MVP es suficiente gestionar una sola imagen, sin impedir que el modelo quede preparado para múltiples imágenes en el futuro.

---

## 2.3. Producto asociado a un show (evolución futura)

Un producto puede tener, opcionalmente, una asociación con un `Show`:

```text
sin show asociado  → producto general del e-commerce
con show asociado  → producto vinculado a un show (por ejemplo, una entrada)
```

Esta asociación **pertenece a una evolución futura** y no condiciona el MVP. Ver sección 3.

---

# 3. Entradas para shows

> La comercialización de entradas pertenece a una **evolución futura** y no forma parte del MVP (ver `ECOMMERCE_ROADMAP.md`).

Las entradas se modelan como productos asociados a un show.

Una entrada, como producto asociado a un show, puede tener características específicas como:

* show asociado;
* fecha y horario;
* lugar;
* precio;
* capacidad/cupo, que determina el stock disponible de entradas;
* cantidad máxima por comprador;
* estado de venta;
* disponibilidad.

No todos los shows tienen necesariamente un producto de tipo entrada disponible.

Un show puede existir aunque:

* todavía no tenga entradas a la venta;
* las entradas todavía no estén habilitadas;
* la venta haya finalizado;
* no exista venta de entradas online.

---

# 4. Diferencia entre Store y entradas

> **Fuera del MVP:** esta sección corresponde a la evolución futura de venta de entradas/tickets.

Las entradas son productos, pero su ubicación comercial y descubrimiento para el usuario es diferente al de los productos de la Store.

## Entradas

La entrada se ofrece desde el contexto del show, cuando el show tiene habilitada la venta de entradas online:

```text
Próximo show
      ↓
Comprar entrada
```

La compra comienza desde el contexto del show.

## Store

La tienda está orientada principalmente a:

* merchandising;
* música;
* productos físicos;
* futuros productos que no correspondan directamente a la compra de una entrada.

Por lo tanto, **producto y ubicación comercial no son necesariamente la misma cosa**.

---

# 5. Flujo de compra de entradas

> **Fuera del MVP:** la venta de entradas/tickets pertenece a una evolución futura y no forma parte del MVP. Se conserva el flujo conceptual para no condicionar el diseño.

Flujo conceptual:

```text
Próximo show
      ↓
Comprar entrada
      ↓
Agregar entrada al carrito
      ↓
Carrito
      ↓
Oferta contextual de productos de la tienda
      ↓
Checkout (se crea el Pedido)
      ↓
Verificación de email
      ↓
Mercado Pago
      ↓
Confirmación
```

La oferta de productos de la tienda debe ser:

* opcional;
* contextual;
* discreta;
* no invasiva.

Ejemplo conceptual:

> ¿Querés agregar algo de Incancelables a tu compra?  
> Remeras, gorras y otros productos.  
> **Ver tienda** / **Continuar**

Si el comprador continúa sin agregar productos, la oferta no debe repetirse constantemente durante el mismo flujo.

---

# 6. Flujo de compra desde la Store

> **Nota de alcance:** la referencia contextual al próximo show/entradas mencionada en esta sección pertenece a una evolución futura; en el MVP la Store vende únicamente merchandising, música y productos generales de la tienda.

Desde la Store el comprador puede iniciar directamente una compra de productos.

No es necesario pasar por el flujo de entradas.

Opcionalmente puede existir una referencia contextual al próximo show, por ejemplo:

> ¿Vas al próximo show? Todavía hay entradas disponibles.

Pero esta referencia no debe convertirse en un paso obligatorio del checkout.

La relación entre ambos contextos es deliberadamente asimétrica:

```text
Entrada → Store
```

es una oportunidad natural de venta adicional.

Mientras que:

```text
Store → Entrada
```

es una referencia contextual, no un paso obligatorio.

---

# 7. Carrito único

Debe existir un único carrito para toda la compra.

Un mismo carrito puede contener productos de diferentes tipos.

Ejemplo:

```text
Carrito
├── Entrada ×2
├── Remera ×1
└── Gorra ×1
```

No se debe crear un carrito separado para entradas y otro para productos de tienda.

Esto permite realizar una única operación de compra y pago.

### 7.1. El carrito es exclusivamente frontend

El carrito es, conceptualmente, una **lista de intención de compra**. No es una transacción y no representa un `Pedido`.

* El carrito vive únicamente en el frontend y se mantiene en `sessionStorage`.
* Sobrevive a recargas en la misma sesión/pestaña.
* Puede perderse al cerrar la pestaña o el navegador.
* El backend **NO persiste** el carrito: no existe un carrito guardado en el servidor, no hay carrito persistente entre dispositivos ni múltiples carritos recuperables (todo ello queda **fuera del MVP**).

### 7.2. Relación entre carrito y Pedido

* El usuario puede modificar libremente el carrito en cualquier momento.
* El carrito por sí mismo **NO reserva ni bloquea stock** (ver secciones 8.6 y 14.1).
* Una vez creado un `Pedido`, el proceso de compra continúa independientemente del estado posterior del carrito: el carrito puede desaparecer, vaciarse o cambiar y el `Pedido` existente continúa su propio ciclo de vida.
* No existe `checkout_id`. El checkout es un **único proceso**, aunque durante el mismo puedan producirse correcciones y crearse nuevos `Pedido` (ver sección 8.6).

---

# 8. Pedido

Al iniciar el checkout se crea un `Pedido`.

El `Pedido` representa la **transacción comercial original** y existe desde ese momento, antes de que el pago sea aprobado. No existe una entidad intermedia de Reserva: el `Pedido` es la representación de la operación comercial desde el inicio del checkout.

Cada `Pedido` se compone de líneas individuales denominadas `PedidoItem` (tabla `pedidos_items`). `PedidoItem` representa una línea del pedido; no define reglas de stock: la política de stock pertenece a `Producto` (ver sección 14).

### 8.1. Relación Pedido y PedidoItem

La relación es:

**Pedido 1:N PedidoItem**

`PedidoItem` tiene una referencia a `Pedido` mediante `pedido_id`.

No debe interpretarse que `Pedido` almacena físicamente una lista de items. Es una relación entre entidades.

Cada `PedidoItem` representa una línea de la operación y contiene conceptualmente:

* `pedido_id`
* `producto_id`
* `cantidad`
* `precio_unitario`

`precio_unitario` es el precio histórico del producto para esa operación (ver sección 2.1.1).

El subtotal de cada item se obtiene:

```text
subtotal del item = cantidad × precio_unitario
```

### 8.2. Total del Pedido

NO agregar `importe_total` a `Pedido`.

El total del Pedido se obtiene dinámicamente a partir de sus `PedidoItem`:

```text
Total Pedido = Σ(cantidad × precio_unitario)
```

La razón es que `Pedido` y `PedidoItem` forman parte de la misma lógica interna del sistema y no se considera necesario duplicar ese dato.

Por lo tanto:

* `Pedido` NO almacena `importe_total`.
* `PedidoItem` conserva `precio_unitario`.
* El total puede calcularse a partir de los items.

No introducir ningún mecanismo adicional de duplicación del total.

### 8.3. Estados del Pedido

Los estados normales del `Pedido` son exactamente:

* `PEND_VERIF`
* `PEND_PAGO`
* `PROCESANDO_PAGO`
* `PAGADO`
* `NO_PAGADO`

Existe además un estado excepcional:

* `PAGADO_STOCK_NO_AFECTADO` (ver sección 8.10)

`PAGADO_STOCK_NO_AFECTADO` **no** es un estado terminal normal.

No existen otros estados de `Pedido`. No agregar estados adicionales.

Los estados terminales normales son `PAGADO` y `NO_PAGADO` (ver sección 8.12).

### 8.4. Flujo normal

El flujo normal es:

```text
PEND_VERIF → PEND_PAGO → PROCESANDO_PAGO → PAGADO
```

#### PEND_VERIF

El `Pedido` se crea cuando comienza el checkout, **antes** de la verificación de email y **antes** del pago.

`PEND_VERIF` significa:

* `Pedido` creado;
* email ingresado;
* email todavía no verificado.

Mientras está en `PEND_VERIF`:

* el `Pedido` ya existe;
* sus `PedidoItem` ya existen;
* sus cantidades ya afectan el cálculo de disponibilidad;
* comienza el primer período temporal del `Pedido` (ver sección 8.5);
* el comprador **todavía no está identificado** (ver sección 10).

Cuando la verificación del email es exitosa:

```text
PEND_VERIF → PEND_PAGO
```

En ese momento el comprador queda **identificado** mediante ese email (ver sección 10).

No se realiza una nueva comprobación de stock específicamente por la verificación del email. La única validación que no se repite en este punto es la verificación de email ya realizada; la validación comercial **siempre** se realiza nuevamente cuando corresponde validar un carrito o un `Pedido` (ver sección 8.6).

### 8.5. Primer período temporal

El primer período comienza exactamente cuando se crea el `Pedido` al iniciar checkout.

* NO comienza al agregar productos al carrito.
* NO comienza al enviar el email.

Incluye el tiempo que el `Pedido` permanece en:

* `PEND_VERIF`
* `PEND_PAGO`

Finaliza cuando comienza el pago (ver sección 8.7).

Si este período vence antes de iniciar el pago:

```text
PEND_VERIF / PEND_PAGO → NO_PAGADO
```

El `Pedido` deja de participar en la disponibilidad.

Para volver a comprar, el usuario debe iniciar un nuevo checkout y se crea un nuevo `Pedido`.

La duración de este período está definida como **parametrizable**; su duración inicial es de **10 minutos**, parametrizada y fácilmente ajustable (ver sección 14.4). **Valor operativo actual (no definitivo): 40 minutos** — ajuste operativo aplicado el 03/10/2026 en DEV/MVP para que el `Pedido` no venza antes del primer reenvío con cooldown de 15 minutos; sigue siendo un parámetro configurable.

Detalles de cálculo y exposición:

* la ventana se calcula desde `pedidos.created_at` (timestamp de creación del `Pedido`): `expires_at = created_at + duración_parametrizada`;
* **NO** se agrega ninguna columna `expires_at`: el valor se deriva del `created_at` ya existente;
* el backend y MariaDB (`NOW()`) son la **autoridad** del vencimiento: el frontend nunca decide;
* el frontend puede recibir `expires_at` y mostrar una **cuenta regresiva informativa**; al vencer, el backend es quien impide continuar con ese `Pedido`;
* la ventana sigue corriendo mientras el usuario decide una corrección del carrito (ver sección 8.6);
* la ventana se evalúa también al **confirmar la verificación de email** (ver sección 11.1): un `Pedido` asociado en `PEND_VERIF` con el primer período vencido pasa a `NO_PAGADO` en lugar de habilitarse a `PEND_PAGO`.

**Implementado el 30/09/2026 (vencimiento general):** el backend aplica el vencimiento de forma **perezosa** y con MariaDB (`NOW()`) como única autoridad — no hay proceso programado ni decisión del frontend. Se vencen masivamente los `PEND_VERIF`/`PEND_PAGO` cuyo `created_at` + la duración parametrizada (hoy 40 minutos operativos; ver sección 8.5) ya pasó al calcular la disponibilidad, al crear un `Pedido` y al operar sobre `POST /api/pedidos/{id}/pago`; y toda operación sobre un `Pedido` concreto (cancelar, pago, reenvío de verificación) lo bloquea, lo pasa a `NO_PAGADO` si venció y responde con error `pedido_vencido`: **el `Pedido` vencido no puede continuar**.

### 8.6. Validación comercial y disponibilidad

Cuando el usuario inicia checkout:

1. el frontend envía el carrito al backend;
2. el backend realiza la **validación comercial**;
3. si todo es válido: se crea el `Pedido`, se crean sus `PedidoItem` y el `Pedido` comienza en `PEND_VERIF`.

Regla general: **un checkout nuevo crea un `Pedido` antes de la verificación de email y antes del pago**, y ese `Pedido` nace en `PEND_VERIF`. La única excepción es la corrección de carrito (ver más abajo), donde el nuevo `Pedido` nace en el mismo estado que tenía el original antes de la corrección.

**Regla general de validación del carrito:** todo carrito recibido desde el frontend se considera **no confiable** y siempre debe validarse contra el estado actual del backend, reutilizando la misma lógica de validación. Aplica en:

1. la creación del `Pedido`;
2. la corrección del carrito;
3. el inicio del pago.

La validación incluye, según corresponda: existencia del producto, producto activo, precio actual, disponibilidad y demás reglas comerciales vigentes. **Nunca se reutiliza una validación comercial anterior como garantía de validez actual.**

La validación comercial incluye, como mínimo:

* existencia del producto;
* producto activo / disponible para venta;
* cantidades válidas;
* disponibilidad actual;
* precio actual;
* demás reglas comerciales vigentes.

Existen exactamente **tres momentos conceptuales** de comprobación; en el segundo y el tercero el backend realiza la validación comercial completa (de la que la disponibilidad es una parte).

#### Primera comprobación — mostrar productos

Es únicamente informativa.

Al entrar al e-commerce el backend calcula la disponibilidad, la devuelve al frontend y el frontend muestra ese dato, utilizándolo para limitar cuánto puede agregarse al carrito.

La disponibilidad entregada al frontend es informativa/operativa para la interfaz, pero **el frontend nunca es autoridad sobre el stock**: toda decisión de negocio se valida siempre en el backend con información actual.

Agregar un producto al carrito **NO modifica la disponibilidad global**: el carrito no reserva ni bloquea stock.

El carrito es privado del comprador y no interactúa con el stock de otros compradores.

#### Segunda comprobación — iniciar checkout (validación comercial)

Antes de crear el `Pedido` se realiza la validación comercial completa.

**Endpoint `POST /api/pedidos` (implementado y verificado el 29/09/2026):** corresponde al inicio del checkout y realiza esta **primera** ejecución de la validación comercial; en este momento **todavía no existe un `Pedido` previo**. El request es un JSON con `email`, `nombre` y `apellido` (obligatorios) y `items[]`, donde cada item requiere obligatoriamente `producto_id`, `cantidad` y **`precio_unitario`** (no es opcional: si falta, responde `400` y no se crea ningún `Pedido` ni `PedidoItem`). La validación completa se ejecuta **dentro de una transacción de MariaDB**, bloqueando las filas de `productos` del carrito (`SELECT ... FOR UPDATE`, en orden ascendente de `producto_id`), de modo que dos checkouts simultáneos no pueden crear Pedidos sin disponibilidad. El precio persistido en cada `PedidoItem` proviene siempre de la base de datos, no del request.

Si la validación es válida:

* se crea el `Pedido`;
* se crean sus `PedidoItem`;
* el `Pedido` comienza en `PEND_VERIF`;
* desde ese momento las cantidades pasan a afectar la disponibilidad global.

Si la validación encuentra diferencias:

* NO se crea un `Pedido` válido para la operación corregida (en esta primera ejecución no hay ningún `Pedido` previo que dejar en `NO_PAGADO`);
* el backend devuelve el carrito corregido, la información actualizada de disponibilidad y el motivo de cada corrección (ver **Corrección del carrito** más abajo).

#### Tercera comprobación — antes de iniciar el pago (validación comercial)

Antes de iniciar el pago se realiza nuevamente la validación comercial completa: stock, precio, disponibilidad y demás reglas comerciales.

Si la validación es válida:

* se inicia el pago;
* el `Pedido` pasa a `PROCESANDO_PAGO`.

Si la validación encuentra diferencias:

* NO se inicia el pago;
* el backend devuelve una propuesta de corrección: el carrito corregido propuesto, los **precios y la disponibilidad actualizados del catálogo completo activo** y el motivo de cada corrección;
* el `Pedido` actual **NO** pasa inmediatamente a `NO_PAGADO`: **conserva su estado** (`PEND_VERIF` o `PEND_PAGO`) y **sigue afectando la disponibilidad** mientras el usuario decide;
* sus `PedidoItem` NO se modifican.

Mientras decide, el usuario puede:

* **aceptar** la corrección propuesta;
* **modificar o rearmar** nuevamente el carrito antes de aceptar;
* **rechazar/cancelar** la operación (cancelación voluntaria del checkout, ver más abajo).

Si el usuario acepta, se realiza nuevamente la **validación comercial completa** sobre el carrito que finalmente eligió:

* si es válida, **en la misma operación/transacción**:
  * el `Pedido` anterior pasa a `NO_PAGADO`;
  * se crea un NUEVO `Pedido`;
  * se crean nuevos `PedidoItem`;
  * el reemplazante **hereda el estado** que tenía el original antes de la corrección (`PEND_VERIF` o `PEND_PAGO`);
  * se reutiliza la verificación de email ya realizada;
  * no se modifican los `PedidoItem` del `Pedido` anterior;
  * no existe una relación especial entre ambos `Pedido`.
* si vuelve a haber diferencias, **no hay sustitución parcial**: se devuelve nuevamente el carrito corregido propuesto con los precios y la disponibilidad actualizados del catálogo completo activo, y el `Pedido` conserva su estado.

Un reemplazante que herede `PEND_PAGO` **igualmente debe volver a pasar por la tercera validación comercial** cuando posteriormente se intente iniciar el pago.

Si algún producto tiene disponibilidad cero, puede quitarse de la nueva operación. Si no queda ningún producto, no se crea una nueva operación.

#### Corrección del carrito

Cuando una validación comercial encuentra diferencias, los motivos pueden ser, por ejemplo:

* cambió el precio;
* quedan menos unidades;
* producto dejó de estar disponible;
* producto dejó de existir;
* otra regla comercial impide mantener la operación original.

El backend devuelve, como mínimo:

* el carrito corregido;
* la información actualizada de disponibilidad;
* el motivo de cada corrección.

Cuando ya existe un `Pedido` creado en el proceso (tercera comprobación), la respuesta incluye además el **precio y la disponibilidad actualizados del catálogo completo activo** del e-commerce.

Reglas de la corrección:

* Si ya existe un `Pedido` creado en el proceso de compra, las diferencias detectadas **NO** lo invalidan: el `Pedido` **conserva su estado** (`PEND_VERIF` o `PEND_PAGO`) y **sigue afectando la disponibilidad** mientras el usuario decide; pasa a `NO_PAGADO` únicamente si el usuario **cancela** (cancelación voluntaria) o **dentro de la misma operación/transacción en que se acepta** la corrección y se crea el reemplazante. Sus `PedidoItem` **NO** se modifican en ningún caso para convertirlos en la nueva operación.
* El frontend actualiza la información visible de disponibilidad y de precios (catálogo completo).
* El usuario puede editar libremente el carrito: reducir cantidades, eliminar productos, agregar productos que no estaban originalmente, modificar cantidades, etc.
* El usuario puede aceptar la corrección, modificar/rearmar el carrito o **cancelar** la operación mediante la cancelación voluntaria del checkout (ver más abajo).
* No existe lógica que distinga el carrito "original" del "corregido": **cada carrito recibido representa la intención actual del usuario** y se valida de la misma manera.
* Si lo acepta: se realiza nuevamente la **validación comercial completa**; si resulta válida, **en la misma operación/transacción** el `Pedido` anterior pasa a `NO_PAGADO` y se crea un **nuevo** `Pedido` y el proceso continúa con la misma lógica general; si vuelve a haber diferencias, se devuelve una nueva propuesta de corrección **sin sustituir** el `Pedido` (no hay sustitución parcial).
* **El nuevo `Pedido` nace con el MISMO estado que tenía el `Pedido` original antes de la corrección**: si el original estaba en `PEND_VERIF`, el nuevo nace en `PEND_VERIF`; si el original estaba en `PEND_PAGO`, el nuevo nace en `PEND_PAGO`. La corrección del carrito no fuerza repetir una verificación de email que el proceso anterior ya cumplió.
* La nueva validación **SIEMPRE** vuelve a comprobar stock, precio, disponibilidad y reglas comerciales. NO se asume que el carrito corregido está validado simplemente porque fue generado por el backend anteriormente.
* La única validación que NO se repite es la verificación de email si el comprador ya fue verificado.
* Las correcciones pueden repetirse:

```text
validar → corregir → usuario modifica/acepta → volver a validar → nuevo Pedido → continuar
```

hasta que la operación sea válida o el usuario la abandone o la cancele.

Durante toda la decisión sigue corriendo el **primer período** (ver sección 8.5): si vence, el `Pedido` pasa a `NO_PAGADO` por vencimiento y ya no puede continuar.

**Implementado el 30/09/2026** (`ECOMMERCE_TECHNICAL_DESIGN.md`, secciones 17.2 y 19.4): revalidación completa del carrito final recibido; con diferencias devuelve una nueva corrección **sin sustituir** (transacción revertida, `Pedido` intacto) y, si es válida, en **una sola transacción** el `Pedido` anterior queda `NO_PAGADO` y se crea el nuevo con los items validados, heredando **el mismo estado** y el `email_verificacion_id`. La tercera validación quedó implementada como `{"accion":"iniciar_pago"}` (recibe `proveedor_id`, `items[]` y opcionalmente `simulacion`): valida el carrito recibido contra el persistido, ejecuta la validación comercial completa y, si todo es válido y hay intentos disponibles, crea el `Pago` con su `PaymentValidationResult` y pasa el `Pedido` a `PROCESANDO_PAGO` (ver sección 8.7); si hay diferencias responde `correccion` sin iniciar el pago.

#### Cancelación voluntaria del Pedido en checkout

El usuario puede **descartar/cancelar voluntariamente** un `Pedido` activo durante el checkout. Se documenta conceptualmente el endpoint:

```text
POST /api/pedidos/{id}/cancelar
```

Reglas:

* aplica a un `Pedido` activo del checkout (`PEND_VERIF` o `PEND_PAGO`);
* el `Pedido` pasa a `NO_PAGADO` de forma inmediata y **libera la disponibilidad** que comprometía;
* sus `PedidoItem` permanecen históricos e inmutables (misma regla de inmutabilidad que la corrección);
* **también sirve para rechazar una corrección**: si el usuario rechaza la propuesta, invoca esta cancelación y el `Pedido` queda `NO_PAGADO` (no espera al vencimiento del primer período);
* si el usuario simplemente abandona sin cancelar, aplica el vencimiento normal del primer período (sección 8.5); no se agrega otro timeout;
* **no confundir** con las **cancelaciones post-venta** (cancelaciones de un `Pedido` ya gestionado, con fulfillment, reembolsos y políticas de cancelabilidad): éstas siguen **fuera del MVP** (ver sección 26 en adelante) y son operaciones posteriores al pedido.

**Implementado el 30/09/2026** (`ECOMMERCE_TECHNICAL_DESIGN.md`, sección 19.4): `POST /api/pedidos/{id}/cancelar` sin body; `200` con `{"resultado":"cancelado","pedido":{...NO_PAGADO}}`; `404 pedido_inexistente`; `409 pedido_vencido` (venció el primer período: pasa a `NO_PAGADO` y no puede continuar); `409 pedido_no_activo` (p. ej. segunda cancelación); `400 pedido_id inválido` si el `{id}` no es numérico; `405` con `Allow` ante otro método. El `Pedido` se bloquea con `SELECT ... FOR UPDATE` dentro de la transacción.

Este endpoint es de nuevo creación: no había mecanismo de cancelación equivalente en los bloques anteriores.

### 8.7. Inicio del pago y segundo período

Al iniciar el pago, el frontend vuelve a enviar el carrito completo al backend junto con el proveedor elegido (conceptualmente, `proveedor_id` y `items[]`). El backend **NO confía** en esos valores: el carrito recibido es siempre una **propuesta del frontend** y se valida nuevamente contra el estado actual del backend (misma lógica de validación comercial; ver sección 8.6).

Si la validación encuentra diferencias, se aplica la corrección del carrito (sección 8.6) y **no se inicia el pago sobre datos obsoletos**.

Cuando la tercera comprobación (validación comercial) confirma disponibilidad:

```text
PEND_PAGO → PROCESANDO_PAGO
```

En ese momento comienza el segundo período temporal.

**Estado de implementación (30/09/2026):** la tercera validación, la corrección, **la transición `PEND_PAGO → PROCESANDO_PAGO` y la creación del `Pago`** están implementados en `POST /api/pedidos/{id}/pago` con `{"accion":"iniciar_pago","proveedor_id":...,"items":[...],"simulacion":"APROBADO|RECHAZADO"}` (desde el 05/10/2026 el conjunto admitido de `simulacion` son sólo esos dos valores: `EN_PROCESO` se rechaza con `400 simulacion inválida`; `ECOMMERCE_TECHNICAL_DESIGN.md`, secciones 17.2, 19.4 y 21): la comparación del carrito recibido contra el persistido, el máximo de intentos (`PAGO_MAX_INTENTOS = 3`) y la validación comercial se ejecutan **antes** de crear el `Pago` y transicionar; recién en la segunda transacción se persiste el resultado del proveedor y se aplican sus efectos (ver sección 8.8). En este bloque el carrito validado por `iniciar_pago` es el recibido en el request (coincidencia obligatoria con el persistido, inmutable); las ediciones pasan por `aceptar_correccion`.

### 8.8. Segundo período temporal

Comienza cuando el pago se inicia y el `Pedido` pasa a `PROCESANDO_PAGO`.

Normalmente termina cuando existe un resultado definitivo del proveedor.

Este período es un **timeout técnico de conciliación**, distinto del primer período:

* **NO** invalida el `Pedido`: si vence sin resolución, el `Pedido` **permanece en `PROCESANDO_PAGO`** y se dispara la conciliación/revisión (ver secciones 8.11 y 13.8);
* **NO** se expone al cliente como cuenta regresiva: el frontend únicamente informa que se está confirmando el estado del pago, sin timer de vencimiento;
* **alcance en el MVP (05/10/2026):** el simulador del MVP sólo produce resultados definitivos (`APROBADO`/`RECHAZADO`), por lo que este período no se activa desde el flujo ejecutable del MVP; queda reservado para proveedores con resolución asincrónica (webhook/conciliación, POST-MVP). La confirmación de resultados `EN_PROCESO` **no** es un pendiente del MVP (ver `ECOMMERCE_TECHNICAL_DESIGN.md`, sección 21).

**Pago rechazado:**

Si el pago resulta definitivamente rechazado:

```text
PROCESANDO_PAGO → PEND_PAGO
```

siempre que:

* todavía queden intentos disponibles;
* y el primer período temporal todavía no haya vencido.

El `Pago` rechazado permanece registrado como histórico.

Nunca se modifica ni se reutiliza ese `Pago` (ver sección 13.5).

**Máximo de intentos:**

Si se alcanza `PAGO_MAX_INTENTOS`:

```text
PROCESANDO_PAGO → NO_PAGADO
```

`PAGO_MAX_INTENTOS` representa el total de intentos permitidos, incluyendo el primero.

No existe un campo persistente `numero_pago` o `numero_intento`; el orden de los intentos se obtiene dinámicamente a partir de los `Pago` asociados al `Pedido` y su orden cronológico.

**Vencimiento de la segunda ventana:**

Si `PROCESANDO_PAGO` supera el tiempo máximo parametrizado sin un resultado definitivo:

* el `Pedido` NO pasa a `NO_PAGADO`;
* permanece en `PROCESANDO_PAGO`;
* se genera el caso de revisión `PAGO_PROCESANDO_EXCEDIDO`, creado como `PENDIENTE` (ver sección 13.8);
* no deben generarse casos de revisión duplicados para el mismo episodio/evento.

NO crear un estado `PAGO_EN_CONCILIACION`, ni una entidad ni un estado nuevos para resolver esta situación.

Si posteriormente llega un resultado definitivo, el caso de revisión pendiente se resuelve cuando corresponde y continúa la lógica normal del `Pedido` según ese resultado (ver secciones 8.9, 8.14 y 13.8). La revisión no congela indefinidamente la lógica del `Pedido`.

La duración de esta segunda ventana es un parámetro parametrizable; su valor concreto no se fija en esta etapa (ver sección 14.4).

### 8.9. Pago aprobado y stock

Cuando el proveedor informa una aprobación definitiva (validada por el backend, ver sección 13.7):

1. el `PaymentValidationResult` correspondiente queda `APROBADO`;
2. si existía un caso de revisión pendiente asociado al episodio de procesamiento (`PAGO_PROCESANDO_EXCEDIDO`), se resuelve (ver sección 13.8);
3. el sistema intenta afectar el stock físico;
4. si la afectación de stock se completa correctamente:

```text
PROCESANDO_PAGO → PAGADO
```

5. si el pago fue aprobado pero la afectación de stock no pudo completarse:

```text
PROCESANDO_PAGO → PAGADO_STOCK_NO_AFECTADO
```

y se genera el caso de revisión `PAGADO_STOCK_NO_AFECTADO` correspondiente (ver secciones 8.10 y 13.8).

La operación normal de aprobación del pago y afectación del stock debe tratarse como una operación atómica a nivel de persistencia.

El detalle técnico de transacciones/locks **ya está implementado**: la aprobación con afectación de stock ocurre en la segunda transacción del flujo, con bloqueo `SELECT ... FOR UPDATE` de `productos` y descuento condicional atómico (ver sección 8.13 y `ECOMMERCE_TECHNICAL_DESIGN.md`, secciones 10.3, 12.7 y 19.6).

Cuando el pago queda definitivamente aprobado y el `Pedido` pasa correctamente a `PAGADO`:

* se informa **inmediatamente** al usuario el resultado correcto;
* se envía un email con los datos necesarios para verificar la transacción/compra.

El contenido exacto de ese email y su plantilla se definen posteriormente en diseño técnico/UX; no se define en esta etapa.

El caso excepcional de que el stock físico resulte insuficiente después de la aprobación no recibe una nueva lógica ni un estado adicional más allá del descrito; su resolución queda fuera del MVP (ver sección 8.10).

### 8.10. PAGADO_STOCK_NO_AFECTADO

Este estado significa:

> El pago fue aprobado, pero el sistema no pudo completar la afectación del stock.

NO significa necesariamente que no hubiera stock.

NO es un estado terminal normal.

Debe generar un caso de revisión `PAGADO_STOCK_NO_AFECTADO`, creado como `PENDIENTE` (ver sección 13.8), que a su vez produce una condición de alerta/conciliación.

La resolución de este caso de revisión queda fuera del MVP.

En el futuro podría resolverse:

* afectando finalmente el stock y pasando a `PAGADO`; o
* iniciando el proceso correspondiente de devolución/reintegro si no puede regularizarse.

NO agregar ahora estados adicionales ni diseñar ese proceso futuro.

### 8.11. Disponibilidad y estados del Pedido

Los estados de `Pedido` que **afectan** la disponibilidad son:

* `PEND_VERIF`
* `PEND_PAGO`
* `PROCESANDO_PAGO`

Los estados que **NO afectan** la disponibilidad son:

* `PAGADO`
* `NO_PAGADO`

`PAGADO_STOCK_NO_AFECTADO` es excepcional y su resolución queda fuera del MVP.

No queda ninguna decisión conceptual pendiente sobre el mecanismo de cantidades comprometidas: las cantidades comprometidas se calculan dinámicamente a partir de los `PedidoItem` pertenecientes a Pedidos en estados que afectan disponibilidad (ver sección 14.1).

### 8.12. Estados terminales

Los estados terminales normales son:

* `PAGADO`
* `NO_PAGADO`

`PAGADO_STOCK_NO_AFECTADO` es excepcional y no terminal.

### 8.13. Concurrencia conceptual

Al iniciar checkout, la validación comercial —que incluye la comprobación de disponibilidad— se realiza antes de crear el `Pedido`.

La creación del `Pedido` y sus `PedidoItem` solamente debe concretarse si la cantidad solicitada sigue disponible.

Si dos usuarios intentan comprar simultáneamente y la primera operación consume la disponibilidad antes de que la segunda pueda confirmarse:

* la segunda operación debe volver a verificar la disponibilidad;
* si ya no existe cantidad suficiente, NO se crea el segundo `Pedido`;
* se informa al usuario la disponibilidad actual.

El mecanismo técnico concreto para garantizar esto en MariaDB está **definido e implementado** para la creación del `Pedido`: validación comercial completa dentro de una transacción de MariaDB con bloqueo de las filas de `productos` (`SELECT ... FOR UPDATE`, en orden ascendente de `producto_id`) y `ROLLBACK` ante cualquier discrepancia (ver sección 8.6 y `ECOMMERCE_TECHNICAL_DESIGN.md`, secciones 10.2, 17.1 y 19.6). La concurrencia de la **afectación de stock al aprobar el pago** también está implementada desde el 30/09/2026: verificación del stock de todas las cantidades antes de descontar, descuento condicional (`UPDATE ... WHERE stock >= ?`) verificado por `rowCount` y `ROLLBACK` ante cualquier fallo, dentro de la misma transacción de la aprobación (ver sección 8.9 y `ECOMMERCE_TECHNICAL_DESIGN.md`, secciones 10.3 y 19.6); una aprobación repetida no descuenta stock dos veces (ver sección 12.6).

### 8.14. Pago rechazado

Un `Pago` rechazado queda permanentemente registrado: conserva su importe y su resultado, no se modifica ni se reutiliza (ver sección 13.5).

No se crea automáticamente otro intento: un nuevo intento solamente existe cuando el comprador inicia un nuevo pago (ver sección 13.3).

El `Pedido` puede volver a `PEND_PAGO` y permitir un nuevo intento si corresponde:

* si todavía está dentro de los límites parametrizados de intentos y el primer período no venció → `PEND_PAGO`;
* si alcanzó el máximo de intentos → `NO_PAGADO`.

Un `RECHAZADO` **no** revierte un `Pedido` que ya está `PAGADO`.

Si existía un caso de revisión pendiente asociado al episodio de procesamiento, se resuelve cuando corresponde antes de continuar con la lógica normal del `Pedido` (ver sección 13.8).

Esto permite conservar el historial completo de intentos de pago.


---

# 9. Pedido inmutable

Una vez confirmado un pedido, **el pedido original no se edita**.

El pedido debe conservar el registro de lo que el comprador adquirió originalmente.

Por ejemplo:

```text
PEDIDO #125

Entrada ×2       $40.000
Remera ×1        $25.000
-------------------------
TOTAL            $65.000
```

Si posteriormente se cancela la remera, el pedido original no se modifica para convertirlo en otro pedido.

Las situaciones posteriores se registran mediante operaciones asociadas al pedido y/o a sus ítems.

Por ejemplo:

```text
PEDIDO #125
├── Items originales
│   ├── Entrada ×2
│   └── Remera ×1
│
├── Cancelación
│   └── Remera ×1
│
└── Reintegro
    └── $25.000
```

Esto permite conservar trazabilidad sobre:

* qué se compró originalmente;
* qué ocurrió posteriormente;
* qué parte fue cancelada;
* qué parte fue devuelta;
* qué importes fueron reintegrados.

El mismo principio aplica durante el checkout: ninguna corrección edita un `Pedido` existente ni sus `PedidoItem`. Mientras el usuario decide, el `Pedido` conserva su estado; al **aceptar** la corrección, en la misma operación/transacción el `Pedido` anterior queda en `NO_PAGADO` y se genera un nuevo `Pedido` mediante una nueva validación comercial, y al **cancelar** la cancelación voluntaria del checkout lo lleva a `NO_PAGADO` (ver sección 8.6).

---

# 10. Usuario y cuenta

No se requiere obligatoriamente una cuenta de usuario.

El sistema debe permitir realizar compras como visitante (`guest checkout`).

No se debe exigir:

* registro;
* usuario;
* contraseña;

para poder comprar.

La posibilidad de crear una cuenta posteriormente puede evaluarse como funcionalidad futura.

### Compra como invitado

El comprador ingresa los datos necesarios para realizar la compra sin crear una cuenta.

Una compra como invitado persiste en el sistema (pedido, comprador, verificación), aunque no exista una cuenta de usuario.

### Identidad y email en el MVP

Para llegar al checkout el usuario introduce un email.

**Introducir el email NO significa que el comprador esté identificado.**

La identificación del comprador mediante email ocurre únicamente cuando el email fue verificado:

```text
email ingresado, todavía sin verificar  → comprador NO identificado (Pedido en PEND_VERIF)
email verificado                        → comprador identificado mediante ese email (Pedido en PEND_PAGO)
```

La verificación de email es obligatoria para todos los pedidos del MVP (ver sección 11).

### Sistema de cuentas futuro

Está previsto un sistema unificado de usuarios con roles, por ejemplo:

```text
CLIENTE
ADMIN
```

* `CLIENTE`: puede comprar y consultar sus compras.
* `ADMIN`: administra el e-commerce.

Un usuario registrado y verificado podrá comprar sin volver a verificar su email en cada compra, reutilizando la verificación previa de su cuenta.

El checkout como invitado continuará existiendo.

Una cuenta futura podrá unificar el historial de una o varias compras realizadas como invitado con el mismo email.

---

# 11. Verificación de email

La compra debe verificar que el comprador tiene acceso al email proporcionado.

La verificación de email es independiente del pago.

Conceptualmente:

```text
Datos de compra
      ↓
Verificación de email
      ↓
Pago
```

La verificación no significa que el pago haya sido realizado.

### Verificación obligatoria para todo pedido en el MVP

En el MVP, **todo pedido requiere verificación de email**:

```text
Pedido
   ↓
Verificación de email
   ↓
Pago
```

No se inicia el pago sin haber verificado el email.

En el MVP, la verificación ocurre con el `Pedido` en `PEND_VERIF`; la verificación exitosa lo lleva a `PEND_PAGO` (ver sección 8.4). No se realiza una nueva comprobación de stock por la verificación del email.

### Verificación única por proceso de identidad

La verificación de email se realiza **una vez por proceso de identidad**:

* si el comprador ya verificó su email, la verificación **NO se repite** al crear un nuevo `Pedido` producto de una corrección ni en ninguna nueva validación del proceso de compra;
* en cambio, la **validación comercial** (existencia, producto activo, cantidades, stock, precio, disponibilidad y demás reglas comerciales) **SIEMPRE** se realiza nuevamente cuando corresponde validar un carrito o un `Pedido`;
* la validación comercial nunca debe darse por válida únicamente porque el carrito fue corregido previamente por el backend.

La diferencia esencial:

```text
validación de email   → se realiza una vez; si ya fue verificada, no se repite
validación comercial  → siempre se realiza nuevamente cuando corresponde validar
```

### Lógica existente de referencia

En el backend histórico (`D:\Incancelables Backend`, Google Apps Script) existe una lógica funcional de verificación de email, desarrollada originalmente para el newsletter, que contempla el proceso completo:

* ingreso del email;
* generación del token;
* envío del email;
* validación;
* expiración;
* token inválido / no validado;
* reenvío;
* situaciones posteriores a la validación.

Esa lógica fue **auditada** y queda como **referencia conceptual**: el e-commerce debe **reutilizar conceptualmente esa misma lógica**, implementándola en PHP/MariaDB en lugar de Apps Script.

**Valores de referencia funcionales históricos** (tomados de la lógica auditada; se usan como referencia funcional, con parametrización):

* expiración del token: **7 días**;
* cooldown de reenvío: **15 minutos**;
* reutilización del token **mientras siga vigente**;
* regeneración del token **cuando expiró**;
* uso **único efectivo** del token.

**Adaptación adoptada al implementar (29/09/2026):** en lugar de reutilizar el token vigente, **cada reenvío permitido genera un token nuevo** (rotación; el anterior queda inválido). Se conservan sin cambios los valores de referencia (7 días, 15 minutos y uso único efectivo del token, que la rotación refuerza).

**No se migra su modelo de datos.** Tablas y conceptos propios del newsletter (por ejemplo `Pendientes`, `Comunidad`, `TOKEN_BAJA`, datos de campañas) **no** forman parte del modelo del e-commerce ni se incorporan a `emails_verificaciones`.

No inventar una segunda lógica de verificación diferente.

### Progreso de la verificación en el ciclo de vida del Pedido

El progreso de la verificación de email se refleja en los estados del `Pedido`:

* `PEND_VERIF`: `Pedido` creado, email ingresado, email todavía no verificado;
* `PEND_PAGO`: email verificado y comprador identificado.

La trazabilidad de la verificación queda en `Pedido.email_verificacion_id`, que referencia el registro de verificación correspondiente. **No** se agrega `email_verificado` como booleano al `Pedido`.

**Confirmación con varios Pedidos asociados (30/09/2026):** la relación **no es única**: una misma verificación puede estar asociada a varios `Pedido` (la asocian, por ejemplo, la sustitución por corrección y la recuperación). Al confirmarla se procesan **todos** los asociados:

* `PEND_VERIF` con primer período **vigente** → `PEND_PAGO` (sin nueva comprobación de stock);
* `PEND_VERIF` con primer período **vencido** → `NO_PAGADO`;
* cualquier otro estado (`PEND_PAGO`, `PROCESANDO_PAGO`, `PAGADO`, `NO_PAGADO`) → **no se modifica**.

La vigencia se calcula desde `created_at` con el primer período (40 minutos operativos, ver sección 8.5) usando `NOW()` de MariaDB como autoridad. La respuesta de la confirmación informa `pedidos[]` con los `Pedido` **vigentes habilitados** por esa confirmación (`[]` si no hay ninguno): la verificación puede quedar correctamente confirmada aunque `pedidos` esté vacío. La operación es **idempotente**: repetir la confirmación del mismo token vuelve a responder `confirmado` con `pedidos: []`.

**Datos comerciales vs. identidad:** los datos comerciales del carrito se revalidan siempre (ver sección 8.6); la identidad/email verificado, en cambio, una vez verificado puede conservarse y reutilizarse dentro del flujo de compra correspondiente. En una corrección que genera un nuevo `Pedido`, si el email ya fue validado no se repite la verificación y el nuevo `Pedido` reutiliza la verificación correspondiente (via `email_verificacion_id`).

La estructura de la tabla de verificación (`emails_verificaciones`) está **cerrada** (ver sección 11.1). **Implementado y verificado el 29/09/2026:** la solicitud de verificación ocurre dentro de `POST /api/pedidos` (después de crear el `Pedido`; un fallo de envío no lo revierte), los endpoints de confirmación y reenvío (`POST /api/email-verificaciones/validar` y `/reenvio`) están operativos con su contrato HTTP, y la transición `PEND_VERIF → PEND_PAGO` la ejecuta el consumidor (`PedidoService`) sin nueva comprobación de stock (ver `ECOMMERCE_TECHNICAL_DESIGN.md`, secciones 15 y 15.4); desde el 30/09/2026 esa confirmación es **N:1**: procesa todos los `Pedido` asociados a la verificación — `PEND_VERIF` vigentes → `PEND_PAGO`, `PEND_VERIF` vencidos → `NO_PAGADO`, demás estados intactos — y responde `pedidos[]` con los habilitados (ver "Progreso de la verificación en el ciclo de vida del Pedido"). Dentro del bloque, el canal de email `log` de DEV (**decisión cerrada el 02/10/2026**, ver "Canales de entrega por entorno" en la sección 11.1) quedó **implementado y verificado el 03/10/2026**; siguen pendientes el canal real de envío del email (**requisito para dar por terminado el MVP**) y la URL/asunto/plantilla definitivos del checkout.

---

## 11.1. Verificación reutilizable

La verificación de email no forma parte de un sistema de autenticación de cuentas: verifica que el comprador tiene acceso a la dirección proporcionada.

Es una **funcionalidad reutilizable**, desacoplada de los pedidos, mediante un **mecanismo genérico de verificación de email** (decisión cerrada):

* el mecanismo es **agnóstico de contexto**: **NO** existe campo de contexto (ni `contexto`, ni `tipo`, ni `uso`, ni `origen`, ni `pedido_id` ni ningún equivalente) en la verificación ni en su tabla; el mecanismo no contiene lógica de negocio de ningún consumidor;
* el único **consumidor del MVP** es el e-commerce (la compra);
* consumidores **futuros (POST-MVP)**: newsletter, registro de cuentas y recuperación de contraseña, entre otros que se incorporen;
* cada consumidor aporta su propia lógica alrededor del mecanismo; el mecanismo sólo verifica el acceso al email.

### Responsabilidades del mecanismo (cerrado)

Un **servicio genérico de verificación** es el responsable de:

* crear y gestionar los registros de verificación;
* generar los tokens y guardar **únicamente su hash** (nunca el token en texto plano);
* controlar la **expiración** y el **cooldown de reenvío** (parametrizados; referencia funcional: 7 días y 15 minutos; el cooldown se mide sobre `last_sent_at`);
* **generar un token nuevo en cada creación y en cada reenvío permitido** (rotación: el token anterior queda inválido; se adoptó al implementar, 29/09/2026, en lugar de reutilizar el vigente; esta lógica es **interna** al servicio);
* validar los tokens;
* registrar la finalización exitosa (`verified_at`);
* devolver al consumidor el resultado/estado de la verificación.

La entrega del email puede diferir entre **desarrollo y producción** (en desarrollo el enlace puede entregarse por otro canal; en producción se envía por correo), manteniendo la misma lógica funcional: esa diferencia la maneja el **consumidor que envía el email**, no el mecanismo.

**Canales de entrega por entorno (decisión cerrada el 02/10/2026):** la entrega queda con separación explícita entre entornos.

* **DEV — canal de email por log:** durante el desarrollo se utiliza un canal de email por log que genera el token real, persiste únicamente su hash, construye el enlace real de verificación y **lo registra en log** para poder recuperarlo durante las pruebas; **considera la entrega exitosa sin ejecutar un envío SMTP real** y, por lo tanto, **registra `last_sent_at`**. Debe permitir probar realmente: validación del token, transición `PEND_VERIF → PEND_PAGO`, reenvío, rotación del token, cooldown, `200 reenviado` y `429 cooldown_activo`. Es **exclusivamente infraestructura de desarrollo** y **no reemplaza** el envío real requerido para el MVP final. *(Implementado y verificado el 03/10/2026: canal `log` activo vía `EmailSender`/`LogEmailSender` y `src/config/email.php`; el enlace queda registrado en el `error_log` de Apache, la entrega se considera exitosa y `last_sent_at` se registra.)*
* **MVP / producción — canal real de envío:** antes de considerar terminado el MVP del E-Commerce deberá existir un **canal real de envío de email** (SMTP, proveedor u otro canal apropiado; el mecanismo concreto se definirá e implementará cuando corresponda preparar el entorno final). **El proyecto no debe considerarse terminado para MVP/producción si la verificación de email depende de leer manualmente un log.**
* **Configuración, no lógica de negocio:** la arquitectura y la configuración deben distinguir el canal de desarrollo del canal real **sin cambiar la lógica de negocio de verificación**; `last_sent_at` representa una entrega exitosa **según el canal configurado**. **Resuelto el 03/10/2026:** la elección del canal vive en `src/config/email.php` y se aplica en `public/index.php` (composition root), sin tocar la lógica de negocio; un canal desconocido falla explícitamente.

El consumidor realiza **una sola solicitud de verificación** y **no elige la operación** (no distingue entre crear o regenerar; tampoco decide cuándo rota el token): esa decisión es interna del servicio.

### Separación de responsabilidades (cerrado)

* **El mecanismo NO envía emails.** No conoce el tipo de email, su contenido ni su plantilla: cada consumidor envía su propio email (asunto, plantilla, URL del enlace). El consumidor del MVP (`PedidoService`) invoca el mecanismo, compone el email de verificación del checkout y lo transporta mediante el contrato genérico `EmailSender` (sin conocer el canal; desde el 03/10/2026 el canal `log` de DEV/MVP registra el email en log); los consumidores futuros (p. ej. el servicio de newsletter) harán lo propio.
* **La confirmación es un flujo único:** el endpoint del consumidor recibe el enlace/token → invoca al mecanismo → el mecanismo valida → si es correcto, **el propio mecanismo registra `verified_at`** → devuelve el resultado → el consumidor decide la acción de negocio (p. ej. `PEND_VERIF → PEND_PAGO`). El consumidor **nunca** escribe `verified_at` directamente. **No** se requiere un callback ni una notificación por eventos.
* **Cuatro concerns claramente separados:** lógica genérica de verificación | lógica del `Pedido` | envío de emails | modelo histórico del newsletter.

### Estructura física de `emails_verificaciones` (cerrada)

```text
id             INT UNSIGNED  NOT NULL AUTO_INCREMENT
email          VARCHAR(254)  NOT NULL
token_hash     VARCHAR(255)  NOT NULL
expires_at     DATETIME      NOT NULL
verified_at    DATETIME      NULL DEFAULT NULL
created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
last_sent_at   DATETIME      NULL DEFAULT NULL
```

* PK: `id`.
* Índice **no único**: `idx_emails_verificaciones_email (email)`.
* **NO** existe columna de estado (`estado_id`): el estado se **deriva de los datos** (`verified_at` NULL/no NULL y `expires_at` respecto al momento actual).
* **NO** existen `pedido_id`, `contexto`, `tipo`, `uso`, `origen` ni ningún campo equivalente: la tabla es agnóstica de consumidor.
* `email` **NO es UNIQUE**: cada `Pedido` que requiere verificación crea **su propio registro**, incluso cuando se repite el mismo email.
* La relación es **unidireccional**: `pedidos.email_verificacion_id → emails_verificaciones.id` (el `Pedido` conoce su verificación; la verificación **no** conoce al `Pedido`).
* Los registros completados **NO se eliminan** (se conservan como evidencia); el token queda inutilizable tras la finalización.
* `last_sent_at` (agregada el 29/09/2026, única ampliación de esquema) sostiene el cooldown de reenvío y sólo se escribe cuando el envío del email tiene éxito.
* La estructura, el índice y la FK **ya existen en la base de datos actual**.

---

## 11.2. Recuperación de pedidos pendientes

### Condición previa

La recuperación **solamente puede ofrecerse** cuando existe un Pedido actual cuyo email haya sido verificado exitosamente y que se encuentre en:

```text
PEND_PAGO
```

* El email ingresado inicialmente **NO** es suficiente para acceder a información de operaciones anteriores: sin una verificación exitosa no hay recuperación.
* La recuperación se inicia siempre desde el Pedido actual ya verificado.

### Regla de unicidad

Debe existir como máximo **UNA** operación anterior recuperable, entre:

* `PEND_VERIF`
* `PEND_PAGO`

`PROCESANDO_PAGO` **no participa** de este mecanismo: queda expresamente fuera de la regla porque ese Pedido puede encontrarse en proceso de conciliación y su resultado puede estar fuera del control del comprador; continúa independientemente (ver sección 8.8).

No deben existir dos operaciones simultáneamente ofrecidas como recuperables para el mismo email.

### Caso A — Pedido anterior en `PEND_VERIF`

Si existe un Pedido anterior recuperable en `PEND_VERIF` y el usuario decide recuperarlo:

* el Pedido anterior pasa de `PEND_VERIF` a `PEND_PAGO`;
* hereda la verificación de email realizada por el Pedido actual;
* **NO** se vuelve a enviar una verificación;
* el Pedido actual pasa a `NO_PAGADO`;
* **no se modifican los `PedidoItem` del Pedido recuperado**.

### Caso B — Pedido anterior en `PEND_PAGO`

Si existe un Pedido anterior recuperable en `PEND_PAGO` y el usuario decide recuperarlo:

* el Pedido anterior permanece en `PEND_PAGO`;
* el Pedido actual pasa a `NO_PAGADO`.

### Caso C — continuar con el Pedido actual

Si el usuario decide NO recuperar el Pedido anterior:

* el Pedido actual permanece en `PEND_PAGO`;
* el Pedido anterior recuperable pasa a `NO_PAGADO`.

La operación elegida es la que continúa.

### Compradores futuros registrados

La lógica para un futuro usuario registrado deberá ser conceptualmente la misma; cambiará únicamente el mecanismo mediante el cual se identifica al comprador:

* guest (MVP): email verificado;
* usuario registrado futuro: sesión/cuenta autenticada.

No se implementan cuentas de usuario en el MVP.

---

## 11.3. Diferencia entre corrección y recuperación

Son flujos distintos y no deben confundirse ni mezclarse en la documentación:

* **Recuperación**: busca una operación anterior asociada al mismo email; requiere un Pedido actual **verificado** (en `PEND_PAGO`); puede recuperar un Pedido anterior en `PEND_VERIF` o `PEND_PAGO`; el Pedido actual puede pasar a `NO_PAGADO` (ver sección 11.2).
* **Corrección**: ocurre cuando el carrito/Pedido actual no puede continuar por cambios de disponibilidad, precio o reglas comerciales; al detectar las diferencias el `Pedido` conserva su estado y sigue afectando la disponibilidad (NO pasa a `NO_PAGADO` automáticamente); si el usuario acepta, en la misma operación/transacción el `Pedido` original pasa a `NO_PAGADO` y se genera un nuevo `Pedido` que hereda el estado previo a la corrección; si cancela, la cancelación voluntaria del checkout lo lleva a `NO_PAGADO`; sus `PedidoItem` son inmutables; no se vuelve a verificar el email si ya estaba verificado (ver sección 8.6).

---

# 12. Newsletter

> **Fuera del MVP:** el newsletter como funcionalidad del e-commerce pertenece a una evolución futura.

El newsletter **no es una funcionalidad del e-commerce**: es una funcionalidad del sitio principal de Incancelables, ejecutada por el backend histórico (`D:\Incancelables Backend`, Apps Script) y persistida en Google Sheets.

**Durante el MVP:**

* el newsletter continúa en su ecosistema actual (Apps Script → Google Sheets);
* el e-commerce opera con su stack propio (PHP → MariaDB);
* **no existe integración** entre el newsletter y el e-commerce.

**POST-MVP:** la suscripción a la comunidad/newsletter podrá ofrecerse durante la compra y su backend podrá migrarse a PHP/MariaDB, reutilizando en ese momento el **mecanismo genérico de verificación de email** (sección 11.1). Cuando eso ocurra, deberá ser:

* opcional;
* explícita;
* independiente de la compra.

No deberá exigirse la suscripción para poder comprar.

La infraestructura de verificación de email del e-commerce queda diseñada de forma genérica y reutilizable, sin atributos de contexto, de modo que pueda servir a:

* verificación de compras (consumidor del MVP);
* suscripción a la comunidad/newsletter y otros consumidores (evolución POST-MVP).

La suscripción al newsletter **no forma parte del MVP**.

---

# 13. Pago

El medio de pago previsto es Mercado Pago.

Para el MVP:

* El único medio de pago considerado es **Mercado Pago** (no se admite comprar con múltiples medios/proveedores reales en el MVP). Las validaciones simuladas del MVP **no** se registran como producidas por Mercado Pago: se registran con el proveedor `DUMMY` (ver sección 13.2, catálogo de proveedores).
* La implementación del proveedor en el MVP es **simulada**: `PaymentValidationService → PaymentProvider → DummyPaymentProvider`. `DummyPaymentProvider` es la implementación simulada del contrato `PaymentProvider` utilizada en el MVP, asociada al proveedor **`DUMMY`** del catálogo `proveedores`, y debe poder ejercitar, de punta a punta, los resultados `APROBADO` y `RECHAZADO` (los dos únicos valores de `simulacion` admitidos por el simulador del MVP desde el 05/10/2026; `EN_PROCESO` queda excluido del simulador y reservado para proveedores con resolución asincrónica — webhook, conciliación, POST-MVP —, ver `ECOMMERCE_TECHNICAL_DESIGN.md`, sección 21).
* La integración real con Mercado Pago (`MercadoPagoProvider` + webhook) queda como **POST-MVP / extensión opcional**. Reemplazar `DummyPaymentProvider` por `MercadoPagoProvider` **no modifica** la lógica de negocio del pago.

En el MVP el usuario atraviesa un **paso de pago simulado** (no existe un checkout real de Mercado Pago); con la integración real (POST-MVP) el usuario realizará la operación en el **Checkout de Mercado Pago**.

Esto no modifica la frontera con el proveedor descrita en la sección 13.2: el resto del sistema sigue consumiendo el resultado normalizado, independientemente de que detrás haya Mercado Pago real o un simulador.

La lógica de pago debe mantenerse conceptualmente separada de:

* verificación de email;
* cumplimiento;
* entrega;
* cancelaciones;
* devoluciones.

Una compra puede tener un pago aprobado y posteriormente experimentar una cancelación o un reintegro.

---

## 13.1. Modelo de pago: `Pago` y `PaymentValidationResult`

El modelo definitivo del MVP separa el intento de cobro del resultado de la validación contra el proveedor.

### `Pago`

Representa **nuestro intento de cobro**.

Campos conceptuales:

* `id`
* `pedido_id`
* `importe`
* `created_at`

`Pago` **NO tiene estado propio**.

`Pago` **NO contiene**: estado del pago; proveedor; comisión; neto recibido; referencia del proveedor.

### `PaymentValidationResult`

Representa el resultado de **nuestra validación** del intento de pago contra el proveedor externo.

Campos:

* `id`
* `pago_id`
* `estado_id`
* `proveedor_id`
* `referencia_proveedor`
* `importe`
* `created_at`

Restricciones:

* `pago_id` NOT NULL;
* `UNIQUE(pago_id)`.

Relación:

```text
Pago 1 ─── 1 PaymentValidationResult
```

**No se crean múltiples `PaymentValidationResult` para el mismo `Pago`.** Si el proveedor informa nuevos resultados sobre el mismo `Pago`, se **actualiza** el registro existente respetando la lógica ya definida. Cada intento diferente de pago (`Pago`) tiene su propio resultado de validación.

La clave foránea está en `PaymentValidationResult.pago_id → Pago.id`. **NO** se utiliza una FK circular desde `Pago`.

Los estados viven en `PaymentValidationResult` (ver sección 13.6): `PENDIENTE`, `EN_PROCESO`, `APROBADO`, `RECHAZADO`, `CANCELADO`. No existe otro conjunto paralelo de estados.

### 13.1.1. Relación Pedido y Pago

La relación es:

**Pedido 1:N Pago**

Un Pedido puede tener cero, uno o varios intentos de pago.

Cada `Pago` representa un intento independiente; su resultado queda registrado en su único `PaymentValidationResult`.

Un Pedido puede tener como máximo **UN** `PaymentValidationResult` `APROBADO` (equivalente a la regla de máximo un pago aprobado por Pedido).

No existe un campo persistente `numero_pago` / `numero_intento`.

El orden/número del intento puede determinarse dinámicamente a partir de los Pagos asociados al Pedido y su orden cronológico.

`PAGO_MAX_INTENTOS` representa el máximo total de intentos, incluyendo el primero.

### 13.1.2. Significado de los importes

Se mantienen claramente separados tres conceptos de importe.

**Pedido** — el importe comercial se obtiene dinámicamente: `Σ(cantidad × precio_unitario)`. No existe `importe_total` persistido en `Pedido` (ver sección 8.2).

**`Pago.importe`** — es el importe que **nuestro sistema solicita cobrar** al cliente por ese intento de pago. En el MVP, al no existir descuentos comerciales definidos:

```text
Pago.importe = total comercial del Pedido
```

**`PaymentValidationResult.importe`** — es el importe que **el proveedor informa/valida** para la operación. Su existencia separada de `Pago.importe` es intencional y sirve para auditoría y detección de diferencias.

**Comisiones / fees** — las comisiones de Mercado Pago, los impuestos/cargos del proveedor y el importe neto efectivamente liquidado **NO forman parte del MVP**: no se agregan a `Pago` ni a `PaymentValidationResult`, y no modifican el importe que paga el cliente. Quedan documentados únicamente como una futura funcionalidad de **liquidación / conciliación**.

Ejemplo conceptual:

```text
Pedido calculado = $35.000

Pago 1
  importe = $35.000
  PaymentValidationResult 1 → RECHAZADO

Pago 2
  importe = $35.000
  PaymentValidationResult 2 → APROBADO
```

La diferencia entre el importe calculado del Pedido, el importe solicitado (`Pago.importe`) y el importe informado por el proveedor (`PaymentValidationResult.importe`) debe poder detectarse para fines de auditoría.

No agregar nuevamente `Pedido.importe_total` para resolver esto.

---

## 13.2. Frontera con el proveedor de pago

La integración con el proveedor de pagos (en el MVP, implementación simulada `DummyPaymentProvider`, identidad `DUMMY`) queda **aislada** dentro del sistema, detrás del servicio de validación de pagos `PaymentValidationService`, que actúa como frontera.

Arquitectura del proveedor de pagos — implementación del MVP:

```text
PaymentValidationService
        ↓
PaymentProvider
        ↓
DummyPaymentProvider
        ↓
resultado simulado
```

Evolución prevista (POST-MVP), sobre el mismo contrato `PaymentProvider`:

```text
PaymentValidationService
        ↓
PaymentProvider
        ↓
MercadoPagoProvider
        ↓
Mercado Pago
```

`PaymentProvider` define el contrato del adaptador; `DummyPaymentProvider` es su implementación **simulada** para el MVP y `MercadoPagoProvider` será su implementación real para Mercado Pago. El intercambio de implementaciones se hace detrás del contrato, sin tocar el resto del sistema.

`PaymentValidationService` es la **única capa de negocio autorizada** a interactuar con el proveedor, mediante el adaptador correspondiente. El resto del sistema no debe depender de detalles específicos de Mercado Pago ni del simulador.

La arquitectura **NO** debe quedar acoplada a una modalidad concreta de checkout de Mercado Pago. La modalidad concreta de checkout (Checkout Pro vs Checkout API/Orders) es un **detalle de implementación** encapsulado en `MercadoPagoProvider` (**POST-MVP**) y **NO** constituye una decisión de diseño ni una decisión de arquitectura pendiente.

Flujo conceptual (MVP):

```text
Frontend
  → Backend
  → PaymentValidationService
  → PaymentProvider
  → DummyPaymentProvider
```

Flujo interno de la validación:

```text
Controller
  → PaymentValidationService
  → PaymentProvider (DummyPaymentProvider en el MVP)
  → PaymentValidationResult (normalizado)
  → reglas de negocio
  → Repository / persistencia
```

Flujo completo del proceso de pago (MVP):

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

Con la integración real (POST-MVP), los pasos `PaymentProvider → DummyPaymentProvider` se reemplazan por `PaymentProvider → MercadoPagoProvider → Mercado Pago`, y la confirmación se dispara desde la notificación webhook de Mercado Pago; la normalización, las reglas de negocio y la persistencia no cambian.

El frontend **NO** determina que un pago está aprobado: la confirmación definitiva la realiza nuestro backend mediante validación contra el proveedor.

* Ninguna otra parte de la aplicación debe llamar directamente al proveedor ni al adaptador: sólo `PaymentValidationService`.
* El resto del sistema no conoce directamente al proveedor.
* No se acopla el dominio interno a los estados propios de Mercado Pago ni a los escenarios del simulador.
* Los estados específicos del proveedor se traducen a estados de `PaymentValidationResult` (ver sección 13.6).
* El sistema consume el **resultado normalizado** del pago (ver sección 13.6).
* El retorno del navegador con el resultado **NO es la fuente definitiva de verdad** para actualizar el `Pedido`, ni siquiera con el proveedor simulado: la confirmación definitiva siempre la realiza el backend (ver sección 13.7). Con la integración real (POST-MVP), Mercado Pago podrá devolver al usuario a nuestro sitio con información sobre el resultado, con la misma regla.
* La confirmación definitiva del resultado se realiza mediante el mecanismo de validación del backend (ver sección 13.7).
* Las credenciales del proveedor permanecen exclusivamente en el backend. `DummyPaymentProvider` no requiere credenciales reales; cuando existan credenciales (integración real, POST-MVP) aplicará la misma regla.

### Catálogo de proveedores

Se mantiene un catálogo de proveedores (`proveedores`: `id`, `codigo`). El catálogo **distingue la implementación real que produjo una validación de pago**. Físicamente contiene:

```text
MERCADOPAGO
DUMMY
```

La identificación es **por `codigo`** (no se asumen IDs numéricos fijos). `PaymentValidationResult.proveedor_id` referencia este catálogo. Mapeo cerrado:

* **MVP:** `DummyPaymentProvider → DUMMY` (las filas de `validaciones_pagos` generadas por el simulador referencian `DUMMY`);
* **integración real futura (POST-MVP):** `MercadoPagoProvider → MERCADOPAGO`.

Motivo: no registrar una validación simulada como si hubiera sido producida realmente por Mercado Pago; la separación conserva la trazabilidad entre pagos/validaciones simulados del MVP y pagos/validaciones provenientes de Mercado Pago real. El catálogo debe quedar preparado para agregar proveedores futuros.

### Información de checkout: persistente vs. temporal

Se separan dos tipos de datos:

* **Información persistente/auditable** — por ejemplo, `PaymentValidationResult.referencia_proveedor`.
* **Información temporal necesaria para continuar el checkout** — por ejemplo, `checkout_url` (aplicable a la integración real, POST-MVP) u otros datos que requiera la modalidad concreta del proveedor.

Los datos temporales de presentación/checkout **NO** deben convertirse automáticamente en campos persistentes de `PaymentValidationResult`. El adaptador de proveedor puede devolver un resultado normalizado que contenga `referencia_proveedor` y `datos_checkout`. Los detalles concretos del proveedor permanecen encapsulados en el adaptador (`DummyPaymentProvider` en el MVP; `MercadoPagoProvider` en la integración real, POST-MVP).

Esto permite que el proveedor cambie o conviva con otros sin modificar la lógica de negocio.

---

## 13.3. Reintentos de pago

Existe un máximo **total** de intentos de pago por pedido (`PAGO_MAX_INTENTOS`), incluyendo el primero.

Un nuevo intento solo es posible si se cumplen **todas** estas condiciones:

1. no se superó el máximo de intentos;
2. el pedido no tiene ningún `PaymentValidationResult` `APROBADO`;
3. el primer período temporal del `Pedido` todavía no venció (el `Pedido` está en `PEND_PAGO`; ver secciones 8.5 y 8.8).

Un pago rechazado no crea automáticamente otro intento: el nuevo intento se crea cuando el comprador inicia efectivamente otro pago.

Si se alcanza `PAGO_MAX_INTENTOS`: `PROCESANDO_PAGO → NO_PAGADO` (ver sección 8.8).

---

## 13.4. RECHAZADO y estados indefinidos

`RECHAZADO` es una resolución **definitiva** del proveedor.

Los estados indefinidos (por ejemplo, `PENDIENTE`, `EN_PROCESO`, desconocido) no son una resolución definitiva: el `Pedido` permanece en `PROCESANDO_PAGO` y se genera el caso de revisión `PAGO_PROCESANDO_EXCEDIDO` cuando se supera el tiempo máximo parametrizado (ver secciones 8.8, 13.8 y 14.5).

Diferencia práctica:

* `RECHAZADO` → el `Pedido` vuelve a `PEND_PAGO` (si quedan intentos y el primer período está vigente) o pasa a `NO_PAGADO` (si alcanzó el máximo de intentos), sin esperar conciliación.
* indefinido → no se asume rechazo, no pasa a `NO_PAGADO`, no se libera stock; permanece en `PROCESANDO_PAGO` con su caso de revisión correspondiente cuando corresponda.

En ambos casos, si existía un caso de revisión pendiente asociado al episodio de procesamiento, se resuelve cuando corresponde y luego continúa la lógica normal del `Pedido` según ese resultado (ver sección 13.8). Un `RECHAZADO` **no** revierte un `Pedido` que ya está `PAGADO`.

---

## 13.5. Pago rechazado: registro permanente

Un intento de pago rechazado:

* queda permanentemente registrado (`Pago` + su `PaymentValidationResult`);
* conserva su importe;
* conserva su resultado (`RECHAZADO`);
* no se modifica;
* no se reutiliza.

El `Pedido` puede volver a `PEND_PAGO` y permitir otro intento si corresponde. Esto permite conservar el historial completo de intentos de pago.

---

## 13.6. Resultados de la validación (`PaymentValidationResult`)

`PaymentValidationService` normaliza los resultados del proveedor (ver sección 13.2) y el estado normalizado se registra en el `PaymentValidationResult` correspondiente.

**Creación del intento.** Al iniciar el proceso de validación:

1. se crea el `Pago`;
2. se crea su único `PaymentValidationResult`;
3. inicialmente queda en un estado no definitivo, por ejemplo `EN_PROCESO`;
4. se realiza la interacción con el proveedor.

Esto permite registrar el intento aunque el proveedor posteriormente no responda o exista un problema técnico.

Los estados definidos son exactamente: `PENDIENTE`, `EN_PROCESO`, `APROBADO`, `RECHAZADO`, `CANCELADO`. No se crea otro conjunto paralelo de estados.

### APROBADO

* `APROBADO` requiere **validación efectiva contra el proveedor**;
* el `PaymentValidationResult` queda `APROBADO`;
* si existía un caso de revisión pendiente asociado al episodio de procesamiento, se resuelve (ver sección 13.8);
* el `Pedido` pasa a `PAGADO` si el stock fue afectado correctamente; si no, pasa a `PAGADO_STOCK_NO_AFECTADO` (ver secciones 8.9 y 8.10);
* si el `APROBADO` presenta inconsistencias (importe diferente, `Pedido` incompatible o un segundo `Pago` aprobado del mismo `Pedido`), se registra el resultado, **no** se afecta stock, **no** se pasa el `Pedido` a `PAGADO` y se genera el caso de revisión correspondiente (ver sección 13.8);
* se informa al usuario que el pago fue correcto;
* se envía un email con los datos de verificación de la transacción (ver sección 8.9).

Una notificación duplicada del mismo pago aprobado **no** vuelve a afectar stock ni vuelve a ejecutar la transición comercial (ver sección 13.7).

### RECHAZADO

* `RECHAZADO` significa **rechazo definitivo informado/validado por el proveedor**;
* el `PaymentValidationResult` queda `RECHAZADO`;
* si existía un caso de revisión pendiente asociado al episodio de procesamiento, se resuelve (ver sección 13.8);
* no se crea automáticamente otro intento: el nuevo intento sólo existe cuando el comprador inicia un nuevo pago;
* se informa al usuario que el pago fue rechazado;
* si quedan intentos y el período correspondiente sigue vigente: `Pedido → PEND_PAGO` y se ofrece un nuevo intento;
* si se alcanzó el máximo de intentos: `Pedido → NO_PAGADO`;
* un `RECHAZADO` **no** revierte un `Pedido` que ya está `PAGADO` (ver sección 8.14).

### Resultados no definitivos

* `PENDIENTE`;
* `EN_PROCESO`;
* `DESCONOCIDO`;
* imposibilidad técnica de determinar el resultado (error técnico, timeout, ausencia de respuesta).

Un error técnico, timeout o ausencia de respuesta **NO** equivale automáticamente a `RECHAZADO`. `EN_PROCESO` y los estados no definitivos representan situaciones que todavía no permiten concluir que el pago fue rechazado:

* el `Pedido` permanece en `PROCESANDO_PAGO`;
* no se libera disponibilidad mientras el resultado no sea definitivo;
* si se supera el tiempo máximo parametrizado se genera el caso de revisión `PAGO_PROCESANDO_EXCEDIDO` (`PENDIENTE`, sin duplicados para el mismo episodio; ver sección 13.8);
* el mismo `Pago` puede recibir posteriormente otro resultado, que se registra actualizando su `PaymentValidationResult` (ver sección 13.1).

La conciliación interna de estos casos existe conceptualmente, pero la experiencia avanzada de usuario para conciliación queda fuera del MVP (ver secciones 13.4 y 14.5).

---

## 13.7. Confirmación definitiva y notificación del proveedor

La confirmación definitiva de un resultado **siempre** la realiza el backend: `PaymentValidationService` consulta/valida contra el proveedor a través de `PaymentProvider` y normaliza el resultado antes de registrarlo. El mecanismo es genérico e independiente del proveedor.

Flujo conceptual (MVP, proveedor simulado):

```text
Evento de confirmación
  → PaymentValidationService
  → consulta/validación contra el proveedor (PaymentProvider → DummyPaymentProvider)
  → validación del estado actual
  → normalización del resultado
  → PaymentValidationResult
  → actualización idempotente del sistema
```

Con la integración real (POST-MVP) el mismo flujo se dispara desde la notificación webhook de Mercado Pago (`POST /api/webhooks/mercadopago`), sin modificar la lógica de negocio ni la normalización.

* El backend **no debe confiar ciegamente** en ningún dato externo (retorno del navegador, notificación o respuesta del simulador): siempre se consulta el estado actual al proveedor, se valida y se normaliza antes de actualizar el sistema.
* La notificación/confirmación **no decide directamente** el estado comercial del `Pedido`: sólo informa del cambio, normaliza el resultado y lo pasa al sistema; la lógica comercial continúa centralizada en `PedidoService`.
* El retorno del navegador con el resultado **no es la fuente definitiva de verdad** para actualizar el `Pedido` (ver sección 13.2).
* No se implementa polling permanente.
* El endpoint `POST /api/webhooks/mercadopago` y su notificación pertenecen a la **integración real con Mercado Pago (POST-MVP)**. La **autenticación/verificación específica de esas notificaciones webhook** queda **fuera del MVP como definición funcional**: se documenta únicamente como detalle de implementación/provider-specific para una etapa posterior. No se definen ahora requisitos concretos de firmas, headers, secretos, algoritmos ni mecanismos de autenticación.
* La idempotencia está garantizada por el backend/la base de datos, no por el comportamiento del proveedor: una notificación/confirmación repetida o una repetición de la misma operación NO descuenta stock nuevamente, NO crea otro `Pago` para la misma operación, NO genera otro `APROBADO`, NO vuelve a aplicar una transición ya aplicada ni produce efectos comerciales duplicados. La regla aplica igual con el proveedor simulado que con el real.
* Regla: **máximo un `PaymentValidationResult` `APROBADO` por `Pedido`**; el procesamiento debe reconocer operaciones ya procesadas mediante las relaciones internas y la referencia del proveedor.
* Debe existir diseño para idempotencia y trazabilidad de las interacciones con el proveedor (incluido el historial de interacciones descrito en el diseño técnico); el detalle técnico de esa implementación queda para la etapa correspondiente.

---

## 13.8. Casos de revisión

Los escenarios excepcionales del ciclo de pago se registran mediante una única entidad de revisión: **`CasoRevision`**. No se crean entidades ni estados adicionales para resolverlos.

### Tipos (cerrados)

Los tipos de revisión son exactamente:

* `PAGADO_STOCK_NO_AFECTADO` — el pago quedó aprobado pero los efectos comerciales (afectación de stock / transición a `PAGADO`) no pudieron completarse;
* `PAGO_PROCESANDO_EXCEDIDO` — el `Pedido` permaneció en `PROCESANDO_PAGO` más allá del tiempo máximo parametrizado sin un resultado definitivo.

No se agregan nuevos tipos de revisión para resolver otros escenarios.

### Estados (cerrados)

Los estados son exactamente:

* `PENDIENTE`
* `RESUELTO`

Los casos se crean siempre como `PENDIENTE`.

### Definición de la entidad (cerrada)

* El caso de revisión se relaciona con el **`Pago`** (no con el `Pedido`).
* Campos de la entidad: `id`, `pago_id`, `tipo_id`, `estado_id`, `created_at`, `updated_at`.
* **NO** tiene campo `descripcion`.
* **NO** existe restricción `UNIQUE(pago_id, tipo_id)`: la prevención de duplicados para el mismo episodio se resuelve **a nivel de aplicación** (regla ya cerrada).
* Catálogos de soporte:
  * `casos_revision_tipos` — con los campos `orden` y `activo`, además del identificador y la descripción del tipo;
  * `casos_revision_estados` — con los campos `orden` y `activo`, además del identificador y la descripción del estado.
* Ambos catálogos contienen únicamente los valores cerrados más arriba (tipos `PAGADO_STOCK_NO_AFECTADO` y `PAGO_PROCESANDO_EXCEDIDO`; estados `PENDIENTE` y `RESUELTO`); no se agregan valores nuevos.

### Cuándo se generan

* `PAGO_PROCESANDO_EXCEDIDO`: cuando un `Pedido` en `PROCESANDO_PAGO` supera el tiempo máximo parametrizado sin resultado definitivo. El `Pedido` **permanece en `PROCESANDO_PAGO`** y **no** deben generarse casos duplicados para el mismo episodio/evento (ver sección 8.8).
* `PAGADO_STOCK_NO_AFECTADO`: cuando el pago queda aprobado pero la afectación de stock no puede completarse, incluidas las situaciones de inconsistencia descritas más abajo (ver secciones 8.9, 8.10 y 13.6).

### La revisión no congela el Pedido

La revisión **no congela indefinidamente** la lógica del `Pedido`. Si el pago deja de estar en una situación de procesamiento y existe un caso de revisión pendiente:

1. el resultado definitivo del pago se registra (sobre el `PaymentValidationResult` correspondiente);
2. el caso pendiente se resuelve cuando corresponde (`RESUELTO`);
3. luego continúa la lógica normal del `Pedido` según ese resultado y las reglas ya definidas.

En particular:

* **`APROBADO` posterior** → se procesa la aprobación, se afecta el stock de forma transaccional e idempotente, `Pedido → PAGADO` y el caso pendiente queda `RESUELTO`;
* **`RECHAZADO` posterior** → el pago queda registrado como rechazado, el caso pendiente se resuelve y se aplica la lógica ya definida: el `Pedido` en `PROCESANDO_PAGO` vuelve a `PEND_PAGO` si todavía está dentro de los límites parametrizados de intentos, o pasa a `NO_PAGADO` si alcanzó el máximo (ver secciones 8.14, 13.3 y 13.4).

No se crea una lógica paralela de revisión: la lógica comercial continúa siendo la ya definida.

No se crea automáticamente otro intento de pago cuando llega un resultado definitivo: un nuevo intento solamente existe cuando el comprador inicia un nuevo pago.

### APROBADO con inconsistencias

**APROBADO pero importe diferente.** Si el proveedor informa `APROBADO` pero el importe no coincide con el importe esperado del `Pago`:

* se registra el resultado;
* no se afecta stock;
* no se pasa el `Pedido` a `PAGADO`;
* se genera un caso de revisión;
* el `Pedido` mantiene su estado según la lógica ya definida.

**APROBADO pero `Pedido` incompatible.** Si el proveedor informa `APROBADO` pero las condiciones del `Pedido` no permiten completar la operación:

* se registra el resultado;
* no se afecta stock;
* no se modifica indebidamente el estado comercial del `Pedido`;
* se genera un caso de revisión.

**Dos `Pago` distintos aprobados para el mismo `Pedido`.** Si dos intentos diferentes resultan `APROBADO`:

* se registran ambos resultados;
* solamente una aprobación puede producir el efecto comercial;
* el segundo no vuelve a afectar stock ni vuelve a ejecutar la transición comercial;
* se genera un caso de revisión cuando corresponda según las reglas ya definidas.

Para todos estos escenarios se utilizan únicamente los tipos de revisión ya definidos; no se crean tipos, entidades ni estados nuevos.

---

# 14. Producto y disponibilidad

Debe distinguirse entre:

```text
Producto
```

y:

```text
Disponibilidad
```

Un producto representa qué se vende.

La disponibilidad representa si puede venderse y bajo qué condiciones.

La política de stock pertenece a `Producto`: `Producto` determina si el producto requiere control de stock o si, en una evolución futura, podrá venderse sin stock o bajo demanda (ver sección 14.2).

---

### 14.1. Disponibilidad en el MVP

Para los productos del MVP que controlan stock, la disponibilidad se calcula **en caliente** a partir de:

```text
disponible = stock físico
             − cantidades de PedidoItem pertenecientes a Pedidos activos
```

Donde **Pedidos activos** son los `Pedido` en estados que afectan disponibilidad:

* `PEND_VERIF`
* `PEND_PAGO`
* `PROCESANDO_PAGO`

(los estados `PAGADO` y `NO_PAGADO` NO afectan la disponibilidad; ver sección 8.11).

- Las **cantidades comprometidas** se calculan dinámicamente a partir de los `PedidoItem` de esos Pedidos. El mecanismo ya no está pendiente: no depende de ninguna otra decisión.
- La disponibilidad **NO es un dato persistente**: no se almacena en ninguna tabla ni columna; se calcula puntualmente bajo demanda.
- El cálculo lo realiza el `PedidoService`, con la información obtenida mediante `ProductoRepository` y `PedidoRepository`; `Producto` no conoce `Pedido` y el `ProductoService` no calcula disponibilidad.
- **NO** existe una entidad `Reserva` en el MVP.
- **NO** existe un campo `stock_comprometido`.
- **NO** existe una tabla alternativa de "reservas", "compromisos" o equivalente.
- **NO** existen `AvailabilityService` ni `StockService`: no se crea un Service por existir un cálculo.
- Si un Pedido pasa a `PAGADO` (pago definitivo y afectación de stock completada), su cantidad deja de computarse como compromiso y el stock físico ya fue reducido.
- Si un Pedido pasa a `NO_PAGADO`, sus cantidades dejan de computarse como compromiso y vuelven a quedar disponibles.
- No se modifican los `PedidoItem` para liberar stock.

El mecanismo técnico concreto de concurrencia, transacciones y locks para la **creación del `Pedido`** está **definido e implementado** (transacción de MariaDB + `SELECT ... FOR UPDATE` de `productos` en `POST /api/pedidos`; ver secciones 8.6, 8.13 y `ECOMMERCE_TECHNICAL_DESIGN.md`, secciones 10.2, 17.1 y 19.6). El mecanismo equivalente para la **afectación de stock con pago aprobado** también está **implementado** desde el 30/09/2026: dentro de la segunda transacción del flujo de pago, con bloqueo `FOR UPDATE` de `productos`, verificación de todas las cantidades antes de descontar, descuento condicional verificado y `ROLLBACK` ante cualquier fallo (ver secciones 8.9 y 8.13 y `ECOMMERCE_TECHNICAL_DESIGN.md`, secciones 10.3 y 19.6).

---

### 14.2. Futuras modalidades de disponibilidad

Quedan contempladas arquitectónicamente para evolucionar el sistema, pero:

**NO se implementan en el MVP.**

Podrían existir productos:

* que puedan venderse aunque su stock sea cero;
* que se produzcan/vendan bajo demanda;
* con otras reglas de disponibilidad.

Estas posibilidades deben quedar previstas en el diseño, sin inventar todavía nombres definitivos de campos, enums o tablas para estas futuras modalidades.

---

## 14.3. Confirmación y descuento de stock

El stock físico **solo se descuenta cuando el pago queda definitivamente aprobado**.

En el MVP, la afectación es directa sobre el stock del producto:

```text
Producto.stock = Producto.stock − PedidoItem.cantidad
```

**No existe historial de movimientos de stock en el MVP.**

La confirmación es una operación:

* **atómica**: no puede quedar parcialmente aplicada;
* **idempotente**: procesar dos veces la misma aprobación no descuenta stock dos veces.

Conceptualmente, la operación crítica sobre un pago aprobado contempla, dentro de una **transacción de la base de datos**:

1. verificar que el pago/operación todavía puede producir efectos;
2. verificar las condiciones necesarias;
3. verificar que no exista ya un efecto aplicado;
4. afectar el stock (`Producto.stock = Producto.stock − PedidoItem.cantidad`);
5. registrar/actualizar el `PaymentValidationResult` (`APROBADO`);
6. establecer el `Pedido` en `PAGADO`.

Si una parte crítica falla: **rollback**, sin dejar el sistema en un estado parcialmente aplicado. Si el pago está aprobado pero el stock no pudo afectarse correctamente: `PAGADO_STOCK_NO_AFECTADO` (excepcional, para auditoría/recovery; no es un flujo comercial normal).

---

## 14.4. Vencimientos

El ciclo del `Pedido` contempla dos ventanas de tiempo conceptuales (ver secciones 8.5 y 8.8):

* **Primer período:** comienza exactamente cuando se crea el `Pedido` al iniciar checkout (NO al agregar al carrito, NO al enviar el email). Incluye el tiempo en `PEND_VERIF` y `PEND_PAGO`. Si el pago no se inicia dentro de la ventana: `PEND_VERIF / PEND_PAGO → NO_PAGADO` y el `Pedido` deja de participar en la disponibilidad. Para volver a comprar se inicia un nuevo checkout y se crea un nuevo `Pedido`. La ventana se calcula desde `pedidos.created_at` (**sin** columna `expires_at` nueva); su duración inicial es de **10 minutos**, parametrizada y fácilmente ajustable (valor operativo actual en DEV/MVP: **40 minutos**, ajuste operativo no definitivo del 03/10/2026; ver sección 8.5); el backend con MariaDB es la autoridad del vencimiento y el frontend puede recibir `expires_at` para mostrar una cuenta regresiva informativa (ver sección 8.5). Este período sigue corriendo mientras el usuario decide una corrección. **Vencimiento implementado el 30/09/2026:** aplicación perezosa con `NOW()` de MariaDB como autoridad (al calcular disponibilidad, al crear un `Pedido` y en las operaciones de `pago`), y bloqueo `FOR UPDATE` del `Pedido` en cada operación concreta: si venció pasa a `NO_PAGADO` y responde `pedido_vencido` (no puede continuar); ver sección 8.5.
* **Segundo período:** comienza cuando el pago se inicia (`PROCESANDO_PAGO`). Si la ventana vence sin resolución: el `Pedido` **permanece en `PROCESANDO_PAGO`**, NO pasa a `NO_PAGADO` y NO se asume rechazo; se genera el caso de revisión `PAGO_PROCESANDO_EXCEDIDO` (`PENDIENTE`, sin duplicados para el mismo episodio; ver sección 13.8). NO se crea un estado `PAGO_EN_CONCILIACION`. Si posteriormente llega un resultado definitivo, el caso se resuelve y continúa la lógica normal del `Pedido`; la resolución administrativa de los casos que nunca reciben un resultado definitivo queda fuera del alcance del MVP. Este timeout es **técnico/de conciliación**: NO invalida el `Pedido` y **NO** se expone al cliente como cuenta regresiva (el frontend sólo informa que se está confirmando el estado del pago).

La duración del primer período tiene **valor inicial cerrado de 10 minutos** (parametrizable) y **valor operativo actual de 40 minutos** desde el 03/10/2026 (ajuste operativo no definitivo; parámetro configurable). La duración del segundo período está definida como **parametrizable**; su valor concreto no se fija en esta etapa (ver también `PAGO_MAX_INTENTOS` y `PAGO_MAX_ESPERA_CONCILIACION`).

---

## 14.5. Conciliación

Algunos pagos pueden quedar en un estado indefinido (ni aprobado ni rechazado).

Mientras el resultado siga indefinido, el `Pedido` permanece en `PROCESANDO_PAGO`:

* no se asume rechazo;
* no se libera stock automáticamente;
* no se crea un estado `PAGO_EN_CONCILIACION`;
* si se supera el tiempo máximo parametrizado se genera el caso de revisión `PAGO_PROCESANDO_EXCEDIDO` (`PENDIENTE`, sin duplicar el caso para el mismo episodio; ver sección 13.8).

Si posteriormente llega un resultado definitivo, se registra, el caso pendiente se resuelve cuando corresponde y continúa la lógica normal del `Pedido` (ver sección 13.8).

La resolución administrativa de los casos de revisión que nunca reciben un resultado definitivo queda fuera del alcance del MVP.

---

# 15. Abastecimiento

> La implementación del abastecimiento **no forma parte del MVP**. Esta sección describe el concepto para evoluciones futuras (ver secciones 14, 16 y 23).

El abastecimiento representa **cómo se obtiene el producto que debe entregarse al comprador**.

Puede existir:

### Stock propio

El producto ya está disponible físicamente.

Ejemplo:

```text
Remera
stock: 8
```

Una venta reduce la cantidad disponible.

### Bajo demanda

El producto se fabrica, compra u obtiene después de concretarse la venta.

Ejemplo:

```text
Remera
stock físico: 0
disponibilidad: BAJO_DEMANDA
```

En este caso la venta puede continuar.

El comprador debe ser informado, antes del pago, cuando corresponda, de que el producto es bajo demanda y de los plazos estimados disponibles.

---

# 16. Combinación de stock y bajo demanda

> Se trata de un concepto futuro. El MVP vende exclusivamente desde stock físico disponible (ver sección 14).

Un mismo producto puede utilizar distintas modalidades de abastecimiento.

Por ejemplo:

```text
Remera
├── Stock propio: 3
└── Bajo demanda: disponible
```

Mientras haya stock propio, se puede utilizar ese stock.

Una vez agotado, el producto puede continuar vendiéndose bajo demanda.

Por lo tanto:

```text
stock = 0
```

no implica necesariamente:

```text
venta cerrada
```

Pero para una entrada:



```text
Entrada
├── Show: próximo show
├── Stock: 100
└── Venta sin stock: NO
```

Cuando llega a:



```text
Stock: 0
```

queda agotada.

# 17. Abastecimiento a proveedor

> **Fuera del MVP:** el abastecimiento y los proveedores no forman parte del MVP.

Cuando una venta requiere obtener un producto de un proveedor o fabricante, debe existir conceptualmente una operación de abastecimiento.

Esto permite separar:

```text
Pedido del cliente
```

de:

```text
Pedido al proveedor
```

Un pedido del cliente no debe convertirse directamente en un pedido al proveedor.

Un mismo pedido de abastecimiento puede eventualmente cubrir productos destinados a varios pedidos de clientes.

---

# 18. Cumplimiento del pedido

> **Fuera del MVP:** las secciones 18 a 24 y 26 a 33 describen conceptos de evoluciones futuras (cumplimiento, entregas, seguimiento, cancelaciones, devoluciones y reintegros). No forman parte del MVP; se conservan para no condicionar el diseño conceptual.

Un pedido puede contener productos que requieren formas de cumplimiento diferentes.

Por este motivo, **no se debe asumir que un pedido tiene una única entrega**.

Se introduce el concepto de `Cumplimiento`.

El cumplimiento representa cómo se resuelve una parte determinada del pedido.

Conceptualmente:

```text
Pedido
├── Items
└── Cumplimientos
```

Cada cumplimiento está asociado a uno o varios ítems del pedido.

---

# 19. Múltiples cumplimientos dentro de un mismo pedido

Un mismo pedido puede contener diferentes tipos de productos y, por lo tanto, requerir diferentes cumplimientos.

Ejemplo:

```text
PEDIDO #125
├── Entrada ×2
└── Remera ×1

CUMPLIMIENTOS
├── Cumplimiento A
│   ├── Entrada ×2
│   ├── tipo: DIGITAL
│   └── estado: EMITIDA
│
└── Cumplimiento B
    ├── Remera ×1
    ├── tipo: FÍSICO
    ├── entrega: ENVÍO_DOMICILIO
    └── estado: EN_PREPARACIÓN
```

El pedido sigue siendo uno solo.

Sin embargo, sus diferentes partes pueden avanzar de manera independiente.

Otro ejemplo:

```text
PEDIDO #126

Entrada ×2
Remera ×1
Gorra ×1
```

Puede generar:

```text
Cumplimiento A
└── Entrada ×2
    DIGITAL

Cumplimiento B
├── Remera ×1
└── Gorra ×1
    RETIRO_EN_SHOW
```

Los ítems compatibles pueden agruparse dentro de un mismo cumplimiento cuando resulte conveniente operativamente.

No es obligatorio crear un cumplimiento separado para cada ítem.

---

# 20. Cumplimiento vs. entrega

`Cumplimiento` es un concepto más amplio que `Entrega`.

Una entrada digital necesita ser cumplida, pero no necesita:

* correo;
* domicilio;
* envío físico;
* número de seguimiento postal.

Por ejemplo:

```text
Entrada
↓
Cumplimiento DIGITAL
↓
Entrada emitida
```

Mientras que una remera puede requerir:

```text
Remera
↓
Cumplimiento FÍSICO
↓
Entrega
↓
Envío / retiro
```

Por lo tanto, la entrega debe considerarse como parte o característica del cumplimiento cuando corresponde, no como una propiedad obligatoria de todo pedido.

---

# 21. Métodos de entrega

Para los productos que requieren entrega física pueden existir distintos métodos.

Por ejemplo:

```text
ENVIO_DOMICILIO
RETIRO_EN_SHOW
RETIRO_EN_PUNTO
```

Estos nombres son conceptuales y no constituyen todavía una definición técnica definitiva.

Debe evitarse diseñar el sistema suponiendo que toda entrega física necesariamente significa correo postal.

El retiro sin costo en un show es una modalidad válida de entrega/cumplimiento.

---

# 22. Entrega según el contenido del pedido

Los costos y datos necesarios para la entrega deben determinarse según los cumplimientos que realmente lo requieran.

Ejemplo:

```text
Pedido
├── Entrada ×2
└── Remera ×1
```

La entrada:

```text
DIGITAL
```

no requiere costo de envío ni domicilio.

La remera:

```text
FÍSICA
```

puede requerir:

```text
ENVÍO_DOMICILIO
```

y por lo tanto:

* domicilio;
* costo de envío;
* información de seguimiento, cuando corresponda.

Si el pedido contiene solamente entradas:

```text
Pedido
└── Entrada ×2
```

no debe solicitarse una dirección de envío.

---

# 23. Seguimiento

El seguimiento corresponde principalmente a los cumplimientos físicos que impliquen un envío.

Puede incluir conceptualmente:

* transportista;
* número de seguimiento;
* fecha de despacho;
* estado del envío;
* fecha de entrega.

Una entrada digital no necesita seguimiento de transporte.

Un retiro en un show tampoco necesita necesariamente un número de seguimiento postal, aunque puede tener información de disponibilidad y retiro.

---

# 24. Estado del cumplimiento

Cada cumplimiento puede tener su propio estado.

Por ejemplo, un producto físico:

```text
PENDIENTE
↓
ABASTECIENDO
↓
EN_PREPARACIÓN
↓
LISTO
↓
DESPACHADO
↓
EN_TRÁNSITO
↓
ENTREGADO
```

Para retiro:

```text
PENDIENTE
↓
EN_PREPARACIÓN
↓
DISPONIBLE_PARA_RETIRO
↓
RETIRADO
```

Para entradas:

```text
PENDIENTE
↓
EMITIDA
```

Los estados exactos se definirán durante el diseño técnico.

Lo importante en esta etapa es que **cada cumplimiento pueda avanzar independientemente**.

---

# 25. Estado general del pedido

El pedido tiene un estado general que representa su situación global.

Los estados del `Pedido` están definidos en la sección 8.3:

```text
PEND_VERIF
PEND_PAGO
PROCESANDO_PAGO
PAGADO
NO_PAGADO
```

más el estado excepcional `PAGADO_STOCK_NO_AFECTADO` (sección 8.10).

No agregar otros estados de `Pedido`.

El estado general del pedido no reemplaza los estados individuales de:

* ítems;
* cumplimientos;
* pagos;
* cancelaciones;
* devoluciones.

Por ejemplo:

```text
PEDIDO #125
estado general: PAGADO

├── Cumplimiento A
│   └── (estado propio del cumplimiento)
│
└── Cumplimiento B
    └── (estado propio del cumplimiento)
```

Los estados de los ítems y de los cumplimientos todavía no se establecen.

---

# 26. Cancelaciones

Las cancelaciones deben existir como operaciones posteriores asociadas al pedido y/o a sus ítems.

El pedido original no se modifica.

Una cancelación puede afectar:

* todo el pedido;
* uno o varios ítems;
* una parte de la cantidad de un ítem.

Ejemplo:

```text
Pedido
└── Entrada ×2

Cancelación
└── Entrada ×1
```

El pedido original continúa registrando que originalmente se compraron dos entradas.

---

# 27. Origen de la cancelación

Debe distinguirse el origen de la cancelación.

No es necesario crear dos tipos de entidades diferentes.

Debe existir una operación `Cancelación` con un atributo conceptual `origen`.

```text
CANCELACIÓN
├── origen: COMPRADOR
└── origen: VENDEDOR/SISTEMA
```

### Cancelación solicitada por el comprador

Ejemplo:

```text
Comprador se arrepiente
        ↓
Solicitud de cancelación
```

El sistema deberá determinar si la cancelación es posible según:

* estado del ítem;
* estado del cumplimiento;
* políticas comerciales;
* situación del abastecimiento;
* otras condiciones que se definan posteriormente.

### Cancelación iniciada por el vendedor/sistema

Ejemplos:

```text
Show suspendido
```

o:

```text
Producto no disponible
```

o:

```text
Producto que no puede ser abastecido
```

También puede afectar a uno o varios ítems.

---

# 28. Arrepentimiento del comprador

El arrepentimiento es un **motivo de una cancelación solicitada por el comprador**, no una entidad equivalente a la cancelación.

Conceptualmente:

```text
ARREPENTIMIENTO
      ↓
Solicitud de cancelación
      ↓
¿Puede cancelarse?
      ↓
CANCELACIÓN
      ↓
¿Corresponde reintegro?
```

La posibilidad de cancelar puede depender del estado del ítem o cumplimiento.

Por ejemplo, un producto bajo demanda puede ser cancelable mientras todavía no comenzó su confección, pero eventualmente dejar de serlo cuando se inició una etapa irreversible de abastecimiento.

Las reglas concretas no se definen todavía.

---

# 29. Estado del ítem y posibilidad de cancelación

No se pretende crear una lista independiente y compleja de estados como:

```text
CANCELABLE_POR_COMPRADOR
NO_CANCELABLE_POR_COMPRADOR
CANCELABLE_POR_VENDEDOR
```

La posibilidad de cancelar debe determinarse principalmente a partir del estado real del ítem/cumplimiento y de las políticas correspondientes.

Por ejemplo:

```text
Item
└── EN_CONFECCION
```

podría no permitir una cancelación por arrepentimiento del comprador.

En cambio:

```text
Item
└── CONFIRMADO
```

podría todavía permitirla.

Los estados exactos y las reglas se definirán cuando se implemente esta funcionalidad.

---

# 30. Cancelación y reintegro

Una cancelación **no implica necesariamente automáticamente un reintegro**, aunque puede generarlo.

La relación conceptual es:

```text
Cancelación
      ↓
¿Corresponde devolver dinero?
      ↓
Reintegro
```

La decisión dependerá de:

* motivo;
* origen;
* estado del pedido/ítem;
* política aplicable;
* situación del pago.

Por ejemplo:

```text
Show suspendido
      ↓
Cancelación
      ↓
Reintegro
```

---

# 31. Devoluciones

La devolución es conceptualmente diferente de una cancelación.

Se utiliza principalmente cuando el producto ya fue entregado y posteriormente se presenta una situación que requiere devolverlo.

Ejemplos:

* producto defectuoso;
* producto incorrecto;
* producto dañado;
* otra causa contemplada por las políticas de devolución.

Conceptualmente:

```text
Producto entregado
      ↓
Problema
      ↓
Devolución
```

Una devolución no implica necesariamente cancelar la compra.

Puede resolverse, por ejemplo, mediante:

```text
Devolución
├── Reemplazo
├── Reparación
└── Reintegro
```

según el caso.

---

# 32. Diferencia entre cancelación, devolución y reintegro

Estos conceptos deben mantenerse separados.

### Cancelación

Algo que estaba previsto/comprado deja de continuar.

### Devolución

Un producto ya entregado vuelve al vendedor o se inicia un proceso de devolución.

### Reintegro

Se devuelve dinero al comprador.

Por lo tanto, pueden existir diferentes relaciones:

```text
Arrepentimiento
      ↓
Cancelación
      ↓
Reintegro
```

o:

```text
Producto defectuoso
      ↓
Devolución
      ↓
Reemplazo
```

o:

```text
Producto defectuoso
      ↓
Devolución
      ↓
Reintegro
```

o:

```text
Show suspendido
      ↓
Cancelación
      ↓
Reintegro
```

No todas las devoluciones generan cancelación y no todas las cancelaciones generan necesariamente reintegro.

---

# 33. Operaciones posteriores al pedido

El modelo conceptual posterior a la confirmación del pedido puede representarse como:

```text
PEDIDO
│
├── ITEMS
│
├── PAGOS
│
├── CUMPLIMIENTOS
│
├── CANCELACIONES
│
├── DEVOLUCIONES
│
└── REINTEGROS
```

Estas operaciones deben mantener relación con los ítems afectados.

Esto permite que un pedido tenga diferentes situaciones simultáneamente.

Ejemplo:

```text
PEDIDO #125

Items:
├── Entrada ×2
└── Remera ×1

Cumplimientos:
├── Entrada ×2 → EMITIDA
└── Remera ×1  → EN_CONFECCION

Cancelaciones:
└── ninguna

Devoluciones:
└── ninguna

Reintegros:
└── ninguno
```

Más adelante podría convertirse en:

```text
PEDIDO #125

Items originales:
├── Entrada ×2
└── Remera ×1

Cancelaciones:
└── Remera ×1

Reintegros:
└── $25.000
```

sin alterar el pedido original.

---

# 34. Separación conceptual

El diseño debe mantener separadas estas responsabilidades:

```text
PRODUCTO
¿Qué se vende?

DISPONIBILIDAD
¿Se puede vender?

ABASTECIMIENTO
¿Cómo se obtiene?

PEDIDO
¿Qué compró el cliente?

PEDIDOITEM
¿Qué producto y cantidad compró? (Línea del Pedido, `PedidoItem` / tabla `pedidos_items`; no define la política de stock.)

CUMPLIMIENTO
¿Cómo se resuelve una parte del pedido?

ENTREGA
¿Cómo recibe físicamente/digitalmente esa parte cuando corresponde?

SEGUIMIENTO
¿Cómo se sigue una entrega física?

CANCELACIÓN
¿Qué parte deja de continuar y por qué?

DEVOLUCIÓN
¿Qué producto entregado vuelve o se reclama?

REINTEGRO
¿Qué dinero se devuelve?
```

Esta separación evita que un concepto termine absorbiendo responsabilidades de otros.

---

# 35. Principios de diseño

El diseño debe respetar los siguientes principios:

1. **Producto genérico**  
No limitar el modelo a merchandising.
2. **Entradas como productos especializados**  
Pero con una experiencia de descubrimiento específica desde los shows.
3. **Carrito único**  
Un pedido puede combinar entradas y productos.
4. **Checkout sin cuenta obligatoria**  
El comprador puede comprar como visitante.
5. **Email y pago separados**  
Verificar el email no significa que el pago esté aprobado.
6. **Pedido inmutable después de confirmado**  
Las situaciones posteriores se registran mediante operaciones asociadas.
7. **Ítems independientes**  
Una operación posterior puede afectar solo una parte del pedido.
8. **Cumplimientos múltiples**  
Un pedido puede requerir diferentes formas de cumplimiento.
9. **Entrega no obligatoria para todo pedido**  
Las entradas digitales no requieren envío físico.
10. **Política de stock y disponibilidad**  
La política de stock pertenece a `Producto`; la disponibilidad se calcula en caliente como `stock físico − cantidades de PedidoItem de Pedidos activos` (`PEND_VERIF`, `PEND_PAGO`, `PROCESANDO_PAGO`), sin `Reserva`, sin `stock_comprometido` y sin tablas de compromisos en el MVP.
11. **Abastecimiento como concepto futuro**  
La venta bajo demanda pertenece a una evolución futura y no condiciona el MVP.
12. **Cancelación y devolución diferenciadas**  
No representan necesariamente la misma situación.
13. **Origen de cancelación diferenciado**  
Debe distinguirse si la cancelación fue solicitada por el comprador o iniciada por el vendedor/sistema.
14. **Reintegro independiente**  
Una cancelación o devolución puede generar o no un reintegro.
15. **Trazabilidad**  
El sistema debe conservar el pedido original y registrar las operaciones posteriores.
16. **Flexibilidad futura**  
Las decisiones actuales no deben impedir incorporar nuevas modalidades de producto, abastecimiento, cumplimiento o entrega.
17. **Frontera de pago aislada**  
El proveedor de pagos queda oculto detrás de un servicio que traduce sus estados al dominio del e-commerce.
18. **Verificación de email reutilizable**  
La verificación se implementa mediante un mecanismo genérico y agnóstico de contexto (sin campo `contexto` ni equivalentes), preparado para compras (consumidor del MVP) y para otros consumidores futuros (newsletter, registro de cuentas, recuperación de contraseña) en una evolución POST-MVP.
19. **Carrito exclusivamente frontend**  
El carrito es una lista de intención de compra en `sessionStorage`; el backend no lo persiste y no existe `checkout_id`. El carrito no reserva stock y el proceso de compra continúa aunque el carrito cambie o desaparezca.
20. **La validación comercial siempre se repite; la verificación de email no**  
Toda validación de carrito/`Pedido` vuelve a comprobar stock, precio, disponibilidad y reglas comerciales; la verificación de email se realiza una vez por proceso de identidad y no se repite si ya fue verificada.
21. **Un único Pedido recuperable, sólo desde un Pedido verificado**  
La recuperación sólo puede ofrecerse desde un Pedido actual verificado en `PEND_PAGO`; entre `PEND_VERIF` y `PEND_PAGO` existe como máximo una operación anterior recuperable por comprador; `PROCESANDO_PAGO` queda fuera de esa regla y continúa independientemente.
22. **El retorno del navegador no es definitivo**  
El resultado del pago se confirma mediante la validación del backend (`PaymentValidationService`), no mediante el retorno del navegador.
23. **El Service determina el resultado; el Controller lo traduce a HTTP/JSON**  
Las reglas de negocio viven en los Services (y los Repository sólo persisten, sin reglas comerciales); un error técnico del proveedor no se convierte automáticamente en `RECHAZADO`.
24. **Transacciones e idempotencia en el backend**  
Toda operación crítica (creación de `Pedido` + `PedidoItem`, pagos, afectación de stock + resultado + transición) se ejecuta en transacción y su idempotencia está garantizada por el backend/la base de datos, no por el comportamiento del proveedor.
25. **Recuperación y corrección son mecanismos distintos**  
La recuperación busca una operación anterior asociada al mismo email desde un Pedido verificado; la corrección responde a que el Pedido actual no puede continuar por reglas comerciales. No deben mezclarse.
26. **Los escenarios excepcionales del pago se registran como casos de revisión**  
`CasoRevision` tiene únicamente los tipos `PAGADO_STOCK_NO_AFECTADO` y `PAGO_PROCESANDO_EXCEDIDO`, con estados `PENDIENTE` y `RESUELTO`; no se crean entidades ni estados nuevos. La revisión no congela el `Pedido`: al llegar un resultado definitivo se registra, se resuelve el caso pendiente cuando corresponde y continúa la lógica comercial ya definida.
27. **La lógica comercial vive en `PedidoService`, no en la notificación ni en la confirmación del proveedor**  
El mecanismo de confirmación (notificación del proveedor cuando exista, o la validación contra el proveedor en el MVP) sólo informa del cambio, consulta al proveedor y normaliza el resultado; el estado comercial del `Pedido` lo decide `PedidoService`.

---

# 36. Decisiones pendientes

Permanecen sin definición y se resolverán durante las etapas de diseño técnico e implementación correspondientes:

* ~~API definitiva para los endpoints restantes: detalle de endpoints, códigos HTTP y formato de respuestas/errores para `POST /api/pedidos/{id}/pago` y para `POST /api/pedidos/{id}/cancelar`~~ **RESUELTO el 30/09/2026:** detalle implementado y verificado para ambos (el comportamiento de ambos quedó cerrado a nivel funcional, ver sección 8.6; ver `ECOMMERCE_TECHNICAL_DESIGN.md`, secciones 17.2 y 19.4); ~~sigue pendiente únicamente el detalle de `POST /api/pedidos/{id}/pago` **del bloque de pagos** (`proveedor_id`, creación de `Pago` y transición a `PROCESANDO_PAGO`), cuando exista `DummyPaymentProvider`~~ **RESUELTO el 30/09/2026** con la implementación del bloque de pagos (`ECOMMERCE_TECHNICAL_DESIGN.md`, sección 21);
* ~~detalle de implementación de `DummyPaymentProvider`~~ **RESUELTO el 30/09/2026** (el contrato `PaymentProvider`, la normalización y las reglas de negocio ya estaban cerrados): el simulador recibe un resultado opcional `simulacion` del request de `iniciar_pago` (`APROBADO` por defecto o `RECHAZADO` — hasta el 05/10/2026 también `EN_PROCESO`; desde esa fecha el conjunto admitido son sólo esos dos valores y `EN_PROCESO` se rechaza con `400 simulacion inválida` —), emite `referencia_proveedor = DUMMY-{pagoId}` y un resultado fuera del conjunto lanza `PaymentProviderException` (ver sección 13.2 y `ECOMMERCE_TECHNICAL_DESIGN.md`, secciones 12.2, 12.7 y 21);
* integración de pagos real con Mercado Pago (`MercadoPagoProvider`, modalidad de checkout y webhook): **POST-MVP** (la modalidad de checkout es un detalle de implementación, no una decisión de diseño; ver sección 13.2);
* estados definitivos de los ítems;
* estados definitivos de los cumplimientos;
* reglas exactas de cancelación;
* plazos de cancelación;
* políticas comerciales de arrepentimiento;
* políticas de devolución;
* reglas de reintegro;
* costos concretos de envío;
* transportistas;
* proveedores;
* sistema de tracking;
* facturación;
* impuestos;
* mecanismos técnicos de autenticación;
* **parametrización del sistema (comportamiento ya cerrado como parametrizable; no es un pendiente de diseño):** duración del primer período del `Pedido` (**valor inicial cerrado: 10 minutos** y **valor operativo actual: 40 minutos** desde el 03/10/2026, ajuste operativo no definitivo; parametrizable y de fácil ajuste; se calcula desde `pedidos.created_at` sin columna `expires_at` nueva), duración del segundo período del `Pedido`, `PAGO_MAX_INTENTOS` y `PAGO_MAX_ESPERA_CONCILIACION`; los valores se cargan en configuración;
* ~~mecanismo técnico de concurrencia, transacciones y locks para la **afectación de stock** con pago aprobado~~ **RESUELTO el 30/09/2026:** ya estaba implementado para la verificación de disponibilidad en la creación del `Pedido` (transacción de MariaDB con `SELECT ... FOR UPDATE` de `productos` en `POST /api/pedidos`) y se implementó también para la aprobación del pago (verificación de todas las cantidades antes de descontar, descuento condicional verificado por `rowCount` y `ROLLBACK` ante fallos, en la segunda transacción del flujo); ver secciones 8.9, 8.13 y `ECOMMERCE_TECHNICAL_DESIGN.md`, secciones 10.3, 17.1 y 19.6;
* resolución de los casos de revisión `PAGADO_STOCK_NO_AFECTADO` (fuera del MVP);
* resolución administrativa de los casos de revisión `PAGO_PROCESANDO_EXCEDIDO` que nunca reciben un resultado definitivo (fuera del MVP; cuando llega un resultado definitivo el caso se resuelve según la lógica ya definida; ver sección 13.8);
* administración MVP;
* configuración de producción;
* ~~detalle técnico de idempotencia y trazabilidad de las interacciones con el proveedor~~ **RESUELTO el 30/09/2026** (en el MVP: interacciones con `DummyPaymentProvider`: referencia `DUMMY-{pagoId}`, un único `PaymentValidationResult` por `Pago`, resultados definitivos no sobrescritos, sin doble descuento de stock, deduplicación de casos de revisión a nivel de aplicación; ver secciones 12.6 y 19.6 de `ECOMMERCE_TECHNICAL_DESIGN.md`); el webhook de Mercado Pago es **POST-MVP** junto con la integración real;
* detalle técnico de la validación comercial al iniciar checkout ya resuelto e implementado para `POST /api/pedidos` (las reglas de negocio estaban cerradas, ver secciones 8.6, 11.2 y 11.3); la corrección de carrito ya está resuelta e implementada como aceptación con sustitución vía `aceptar_correccion` (ver sección 8.6); sigue pendiente el detalle técnico de la **recuperación de pedidos pendientes**;
* contenido y plantilla del email post-pago de verificación de la transacción;
* asunto, plantilla y URL del email de verificación del checkout (lo envía el consumidor — `PedidoService`; el mecanismo no define su contenido);
* autenticación/verificación específica de las notificaciones webhook de Mercado Pago (**fuera del MVP** como definición funcional; queda documentada como detalle de implementación/provider-specific para una etapa posterior; no se definen firmas, headers, secretos, algoritmos ni mecanismos de autenticación; ver sección 13.7);
* liquidación / conciliación con comisiones, impuestos/cargos del proveedor e importe neto liquidado (fuera del MVP; ver sección 13.1.2).

Decisiones ya cerradas hasta el 28/09/2026 (no son pendientes):

* alcance del MVP cerrado: merchandising, música y productos generales de la tienda; **fuera del MVP**: entradas/tickets, fulfillment avanzado, envíos complejos, retiros y puntos de entrega, cuentas de usuario, carrito persistente entre dispositivos, múltiples carritos recuperables, promociones complejas, devoluciones/reembolsos, historial de precios, proveedores/sourcing, reportes y administración avanzados, múltiples proveedores de pago, newsletter como funcionalidad del e-commerce, recuperación sofisticada de carritos abandonados y comisiones/fees con liquidación y conciliación;
* producto genérico, sin campo `tipo_producto`; `show_id` contemplado a futuro, sin habilitar venta de entradas en el MVP;
* carrito exclusivamente frontend en `sessionStorage` (lista de intención de compra; el backend NO lo persiste; sin `checkout_id`); el carrito no reserva stock y el proceso de compra continúa aunque el carrito cambie o desaparezca;
* validación comercial al iniciar checkout (existencia, producto activo, cantidades, disponibilidad, precio y demás reglas comerciales); si es válida, se crea el `Pedido` en `PEND_VERIF` antes de la verificación de email y antes del pago; **regla general**: todo carrito recibido desde el frontend es no confiable y siempre se valida contra el estado actual del backend (creación, corrección e inicio del pago), reutilizando la misma lógica y sin tomar una validación anterior como garantía;
* corrección de carrito con `Pedido` existente: ante diferencias, el backend devuelve el carrito corregido propuesto + precio y disponibilidad actualizados del **catálogo completo activo** + motivos; detectar diferencias **NO** pasa el `Pedido` a `NO_PAGADO`: mientras decide conserva su estado y sigue afectando la disponibilidad; el usuario puede aceptar, modificar/rearmar el carrito o cancelar (sin distinguir "original" de "corregido": cada carrito recibido es la intención actual); al aceptar se repite la validación comercial completa y, si es válida, **en la misma operación/transacción** el `Pedido` anterior queda `NO_PAGADO` (sin modificar sus `PedidoItem`) y se crea un nuevo `Pedido` que nace con el MISMO estado que tenía el original antes de la corrección (`PEND_VERIF` o `PEND_PAGO`); si vuelven las diferencias se devuelve una nueva corrección sin sustituir el `Pedido`; un reemplazante `PEND_PAGO` debe volver a pasar por la tercera validación antes de iniciar el pago; el ciclo puede repetirse hasta que la operación sea válida o el comprador abandone/cancele; la única validación que no se repite es la de email ya verificada **(implementado el 30/09/2026 como `POST /api/pedidos/{id}/pago` con `accion: "iniciar_pago"` — tercera validación + creación del `Pago` y transición a `PROCESANDO_PAGO` cuando todo es válido — y `accion: "aceptar_correccion"` — sustitución en una transacción; ver `ECOMMERCE_TECHNICAL_DESIGN.md`, secciones 17.2, 19.4 y 21)**;
* cancelación voluntaria del checkout: `POST /api/pedidos/{id}/cancelar` para descartar/cancelar un `Pedido` activo en `PEND_VERIF`/`PEND_PAGO` → `NO_PAGADO` inmediato con liberación de disponibilidad y `PedidoItem` intactos; sirve también para rechazar una corrección; si el usuario sólo abandona sin cancelar, aplica el vencimiento normal del primer período (sin timeout adicional); **no** confundir con las cancelaciones post-venta, que siguen fuera del MVP; **implementado y verificado el 30/09/2026** (`200 cancelado`, `404 pedido_inexistente`, `409 pedido_vencido`, `409 pedido_no_activo`, `400 pedido_id inválido`, `405` con `Allow`);
* arquitectura de capas del backend conceptual (Routes/Controller/Service/Repository/MariaDB + Config + Model, sin saltos arbitrarios de capas); Services del MVP cerrados: `PedidoService`, `ProductoService`, `EmailVerificationService`, `PaymentValidationService` (sin `PagoService`, `StockService`, `AvailabilityService`, `ShowService`); Repositories del MVP cerrados: `ProductoRepository`, `PedidoRepository`, `PagoRepository`, `EmailVerificationRepository` (sin `ShowRepository`);
* la disponibilidad no se persiste: se calcula puntualmente con `ProductoRepository` y `PedidoRepository`, coordinado por `PedidoService`; `Producto` no conoce `Pedido`;
* identidad: introducir el email NO identifica al comprador; la identificación ocurre solo con el email verificado (`PEND_VERIF` = creado + email ingresado sin verificar; `PEND_PAGO` = comprador identificado);
* verificación de email obligatoria para todo pedido en el MVP, una sola vez por proceso de identidad, mediante un mecanismo genérico agnóstico de contexto (sin campo `contexto` ni equivalentes; consumidor del MVP: la compra; consumidores futuros POST-MVP: newsletter, registro de cuentas y recuperación de contraseña), con cooldown parametrizado para el reenvío;
* estructura física de `emails_verificaciones` **cerrada** (sección 11.1): `id`, `email`, `token_hash`, `expires_at`, `verified_at`, `created_at`, `last_sent_at`; PK `id`; índice no único `idx_emails_verificaciones_email (email)`; sin `estado_id` (el estado se deriva de los datos); sin `pedido_id`/`contexto`/`tipo`/`uso`/`origen`; `email` NO es UNIQUE (cada `Pedido` crea su propio registro); registros completados conservados (no se eliminan); relación unidireccional `pedidos.email_verificacion_id → emails_verificaciones.id`; estructura e índice ya existentes en la base de datos actual, más `last_sent_at` (única ampliación de esquema, aplicada el 29/09/2026);
* responsabilidades del servicio de verificación **cerradas** (sección 11.1): crea registros, genera tokens nuevos (aleatorios, 256 bits) y guarda sólo su hash SHA-256, controla expiración (referencia: 7 días) y cooldown (referencia: 15 minutos, sobre `last_sent_at`), rota el token en cada reenvío permitido (el anterior queda inválido; lógica interna: el consumidor no elige la operación), valida, registra `verified_at` y devuelve el resultado;
* separación de responsabilidades **cerrada**: el mecanismo no envía emails (cada consumidor envía su propio email; en el MVP `PedidoService` envía el email de verificación del checkout); en la confirmación, el mecanismo valida y registra `verified_at` y el consumidor decide la acción de negocio, sin callbacks ni eventos;
* ecosistema de tres proyectos (sección 1.1): `D:\Incancelables Web` (frontend), `D:\Incancelables Backend` (backend histórico Apps Script, referencia únicamente) y `D:\Incancelables E-Commerce` (backend PHP + MariaDB); durante el MVP el newsletter sigue en Apps Script → Google Sheets, el e-commerce opera en PHP → MariaDB y **no existen integraciones entre ambos ecosistemas**; la migración del newsletter a PHP/MariaDB es **POST-MVP**;
* el backend histórico (`D:\Incancelables Backend`, Apps Script) fue auditado y queda como referencia conceptual de la lógica de verificación de email (ver sección 11); **no se migra su modelo de datos** (`Pendientes`, `Comunidad`, `TOKEN_BAJA`, datos de campañas, etc.) ni su backend a PHP;
* recuperación de pedidos pendientes: solamente desde un Pedido actual verificado y en `PEND_PAGO` (el email ingresado inicialmente no alcanza); como máximo **UNA** operación anterior recuperable entre `PEND_VERIF` y `PEND_PAGO`; `PROCESANDO_PAGO` queda fuera de esa regla y continúa independientemente; casos A (anterior `PEND_VERIF → PEND_PAGO`, hereda la verificación, actual → `NO_PAGADO`), B (anterior permanece `PEND_PAGO`, actual → `NO_PAGADO`) y C (continúa el actual, anterior → `NO_PAGADO`);
* en el MVP no existen cuentas de usuario: compra como invitado; sistema unificado de cuentas con roles `CLIENTE`/`ADMIN` como evolución futura;
* `Pago` sin estado propio: campos conceptuales `id`, `pedido_id`, `importe`, `created_at`; NO contiene estado del pago, proveedor, comisión, neto recibido ni referencia del proveedor; el resultado de la validación vive en `PaymentValidationResult` (1:1 con `Pago`, `pago_id NOT NULL`, `UNIQUE(pago_id)`, FK desde `PaymentValidationResult.pago_id → Pago.id`, sin FK circular desde `Pago`), con campos `id`, `pago_id`, `estado_id`, `proveedor_id`, `referencia_proveedor`, `importe`, `created_at`; la entidad conceptual `PaymentValidationResult` se materializa en la tabla **`validaciones_pagos`** y el catálogo de estados pasa a denominarse **`validaciones_pagos_estados`** (nombres de tablas cerrados; se conserva el nombre conceptual `PaymentValidationResult`);
* catálogo de proveedores (`proveedores`: `id`, `codigo`) con las filas `MERCADOPAGO` y `DUMMY` (identificación por `codigo`, sin IDs numéricos fijos); en el MVP las validaciones referencian `DUMMY` (`DummyPaymentProvider`) y `MERCADOPAGO` queda reservado para `MercadoPagoProvider` (integración real futura); preparado para proveedores futuros; `PaymentValidationResult.proveedor_id` lo referencia;
* importes separados: total comercial del `Pedido` (`Σ(cantidad × precio_unitario)`, sin `importe_total`), `Pago.importe` = importe que nuestro sistema solicita cobrar (= total comercial en el MVP, sin descuentos) y `PaymentValidationResult.importe` = importe informado/validado por el proveedor (auditoría); comisiones/fees/neto fuera del MVP (futura liquidación/conciliación);
* disponibilidad calculada en caliente como `stock físico − cantidades de PedidoItem de Pedidos activos`; en el MVP **NO** existe entidad `Reserva`, **NO** existe campo `stock_comprometido` y **NO** existe tabla de "reservas" o "compromisos"; sin `permite_venta_sin_stock` en el MVP;
* estados del `Pedido` definidos: `PEND_VERIF`, `PEND_PAGO`, `PROCESANDO_PAGO`, `PAGADO`, `NO_PAGADO`, más el excepcional `PAGADO_STOCK_NO_AFECTADO`; sin otros estados;
* flujo normal `PEND_VERIF → PEND_PAGO → PROCESANDO_PAGO → PAGADO`; terminales normales `PAGADO` y `NO_PAGADO`;
* afectan disponibilidad: `PEND_VERIF`, `PEND_PAGO`, `PROCESANDO_PAGO`; no afectan: `PAGADO`, `NO_PAGADO`;
* tres momentos de comprobación (mostrar productos, iniciar checkout, iniciar pago); en el segundo y el tercero el backend realiza la validación comercial completa, que incluye la disponibilidad; el carrito no modifica la disponibilidad global;
* primer período desde la creación del `Pedido` hasta iniciar el pago; duración inicial **10 minutos** (valor operativo actual en DEV/MVP: **40 minutos**, ajuste operativo no definitivo del 03/10/2026), parametrizable y de fácil ajuste, calculada desde `pedidos.created_at` (sin columna `expires_at` nueva; el backend con MariaDB es la autoridad y el frontend puede recibir `expires_at` como cuenta regresiva informativa; sigue corriendo mientras el usuario decide una corrección); vencido → `NO_PAGADO` y nuevo checkout para recomprar; segundo período desde `PROCESANDO_PAGO`; vencido sin resolución → permanece en `PROCESANDO_PAGO` y genera el caso de revisión `PAGO_PROCESANDO_EXCEDIDO` (`PENDIENTE`, sin duplicados), sin estado `PAGO_EN_CONCILIACION` y **sin cuenta regresiva expuesta al cliente** (sólo se informa que se está confirmando el estado del pago);
* resolución de los casos de revisión `PAGADO_STOCK_NO_AFECTADO` y resolución administrativa de los `PAGO_PROCESANDO_EXCEDIDO` que nunca reciben un resultado definitivo, fuera del MVP;
* entradas y productos asociados a shows fuera del MVP;
* Mercado Pago como único medio de pago concebido del MVP, con arquitectura de pagos `PaymentValidationService → PaymentProvider → DummyPaymentProvider` (única capa de negocio autorizada: `PaymentValidationService`; `PaymentProvider` es el contrato del adaptador; `DummyPaymentProvider` es su implementación **simulada** en el MVP y debe ejercitar `APROBADO` y `RECHAZADO` (los dos únicos valores de `simulacion` desde el 05/10/2026; `EN_PROCESO` queda excluido del simulador y reservado para la evolución futura, ver `ECOMMERCE_TECHNICAL_DESIGN.md`, sección 21); identidad de proveedor en el catálogo: **`DUMMY`** — no `MERCADOPAGO`); la integración real con Mercado Pago (`MercadoPagoProvider`, identidad `MERCADOPAGO`, modalidad de checkout — Checkout Pro vs API/Orders — y webhook `POST /api/webhooks/mercadopago`) queda **POST-MVP / extensión opcional**, sobre el mismo contrato `PaymentProvider` y sin modificar la lógica de negocio; en el MVP el usuario atraviesa un paso de pago simulado (no hay checkout real de MP); el retorno del navegador NO es fuente definitiva de verdad (la confirma el backend mediante validación contra el proveedor, sin polling permanente); la lógica comercial la decide `PedidoService`, no la notificación/confirmación del proveedor; credenciales sólo en backend (el simulador no usa credenciales reales);
* resultados de validación normalizados por `PaymentValidationService` y persistidos en `PaymentValidationResult`: estados exactos `PENDIENTE`, `EN_PROCESO`, `APROBADO`, `RECHAZADO`, `CANCELADO` (sin conjunto paralelo); `APROBADO` requiere validación efectiva contra el proveedor (Pedido → `PAGADO` si el stock fue afectado), `RECHAZADO` = rechazo definitivo validado (nuevo intento si corresponde, o `NO_PAGADO` al máximo de intentos) y resultados no definitivos (`PENDIENTE`, `EN_PROCESO`, `DESCONOCIDO`, imposibilidad técnica) que permanecen en `PROCESANDO_PAGO` sin convertirse automáticamente en `RECHAZADO` ni liberar disponibilidad;
* email post-pago: al pasar a `PAGADO` se informa inmediatamente al usuario y se envía un email con los datos de verificación de la transacción (contenido y plantilla a definir en diseño técnico/UX);
* relación `Pedido` 1:N `PedidoItem`, con `pedido_id`, `producto_id`, `cantidad` y `precio_unitario` (precio histórico) en cada item; subtotal del item = `cantidad × precio_unitario`;
* `Pedido` sin `importe_total`: el total se calcula dinámicamente como `Σ(cantidad × precio_unitario)` a partir de sus items, sin duplicar el dato;
* relación `Pedido` 1:N `Pago`, con `importe` propio en cada intento para auditoría y detección de diferencias contra el total calculado del pedido; sin campo persistente `numero_pago` / `numero_intento` (el orden se determina dinámicamente por cronología); `PAGO_MAX_INTENTOS` cuenta el primer intento como intento número 1;
* estados de `PaymentValidationResult` (no de `Pago`): `PENDIENTE`, `EN_PROCESO`, `APROBADO`, `RECHAZADO`, `CANCELADO`; al iniciar la validación se crea el `Pago`, luego su único `PaymentValidationResult` (inicialmente en estado no definitivo, p. ej. `EN_PROCESO`) y recién entonces se interactúa con el proveedor;
* el checkout es un proceso de negocio: **NO** existe entidad `Checkout` ni `checkout_id`; el `Pedido` es la entidad persistente que representa la operación desde que comienza el checkout;
* endpoints conceptuales del MVP: `GET /api/productos`, `POST /api/email-verificaciones`, `POST /api/email-verificaciones/validar`, `POST /api/email-verificaciones/reenvio`, `POST /api/pedidos`, `POST /api/pedidos/{id}/pago`, `POST /api/pedidos/{id}/cancelar` (cancelación voluntaria del checkout, ver sección 8.6); `POST /api/pedidos` fluye Route → Controller → `PedidoService` → `PedidoRepository` → Database; **implementados y verificados (29/09/2026):** `GET /api/productos`, `POST /api/pedidos`, `POST /api/email-verificaciones/validar` y `POST /api/email-verificaciones/reenvio` (la solicitud de verificación no existe como endpoint propio: está integrada en `POST /api/pedidos`, después de crear el `Pedido`); **implementados y verificados (30/09/2026):** `POST /api/pedidos/{id}/cancelar` y `POST /api/pedidos/{id}/pago` (bloque de vencimiento/cancelación/inicio de pago: `iniciar_pago`/`aceptar_correccion` con vencimiento perezoso; **y bloque de pagos**: `proveedor_id`, `items[]`, `simulacion`, creación de `Pago` + `PaymentValidationResult`, transición a `PROCESANDO_PAGO` y aplicación del resultado vía `PaymentValidationService → DummyPaymentProvider`; rutas con `{id}` soportadas por el router); `POST /api/webhooks/mercadopago` queda **POST-MVP** (junto con `MercadoPagoProvider` y la integración real); sin endpoints de carrito persistente, stock, disponibilidad, reservas ni `checkout_id`;
* errores de negocio (producto inexistente/inactivo, disponibilidad insuficiente, cambio de precio, Pedido no recuperable, email no verificado, máximo de intentos alcanzado, pago definitivamente rechazado) y errores técnicos (DB no disponible, excepción, timeout, error de comunicación con el proveedor): el Service determina el resultado y el Controller lo convierte en HTTP/JSON; un error técnico del proveedor NO se convierte automáticamente en `RECHAZADO`;
* toda operación que pueda dejar datos inconsistentes si falla parcialmente se ejecuta en transacción (creación de `Pedido` + `PedidoItem`, operaciones críticas de pago, afectación de stock + persistencia del resultado + transición del `Pedido`); el Repository persiste y el Service coordina la operación transaccional, sin reglas comerciales dentro del Repository;
* idempotencia garantizada por el backend/DB: una notificación/confirmación repetida (o un webhook duplicado cuando exista) o una repetición de la misma operación NO descuenta stock nuevamente, NO crea otro `Pago` para la misma operación, NO genera otro `APROBADO`, NO vuelve a aplicar una transición ya aplicada ni produce efectos comerciales duplicados; regla: máximo un `PaymentValidationResult` `APROBADO` por `Pedido`; aplica igual con `DummyPaymentProvider` que con un proveedor real;
* `POST /api/pedidos/{id}/pago` recibe `proveedor_id` y `items[]` (el carrito completo vuelve a enviarse al inicio del pago) y opcionalmente `simulacion`; el backend NO confía en esos valores y los valida siempre (comparación `items[]` contra los items persistidos + tercera validación comercial); **implementación actual (30/09/2026):** el bloque de pagos está implementado — `iniciar_pago` crea el `Pago` con su `PaymentValidationResult` y transiciona a `PROCESANDO_PAGO`, y la segunda fase persiste y aplica el resultado de `DummyPaymentProvider` (ver `ECOMMERCE_TECHNICAL_DESIGN.md`, secciones 17.2 y 21);
* email verificado reutilizable: trazabilidad via `Pedido.email_verificacion_id`, sin booleano `email_verificado` en el `Pedido`;
* descuento de stock en el MVP: `Producto.stock = Producto.stock − PedidoItem.cantidad` dentro de una transacción, sin historial de movimientos de stock;
* casos de revisión (`CasoRevision`): se relaciona con el **`Pago`** (no con el `Pedido`); campos `id`, `pago_id`, `tipo_id`, `estado_id`, `created_at`, `updated_at`; **sin** campo `descripcion` y **sin** `UNIQUE(pago_id, tipo_id)` (los duplicados se evitan a nivel de aplicación); catálogos `casos_revision_tipos` y `casos_revision_estados` con los campos `orden` y `activo`; tipos exactos `PAGADO_STOCK_NO_AFECTADO` y `PAGO_PROCESANDO_EXCEDIDO`; estados exactos `PENDIENTE` y `RESUELTO`; se crean siempre como `PENDIENTE`; no se agregan tipos, entidades ni estados nuevos; la revisión no congela la lógica del `Pedido` (ver sección 13.8);
* `PROCESANDO_PAGO` vencido: el `Pedido` permanece en `PROCESANDO_PAGO` y se genera `PAGO_PROCESANDO_EXCEDIDO` sin duplicados para el mismo episodio; un `APROBADO` posterior pasa el `Pedido` a `PAGADO`, afecta el stock y resuelve el caso (`RESUELTO`); un `RECHAZADO` posterior aplica la lógica ya definida (`PEND_PAGO` si quedan intentos parametrizados, o `NO_PAGADO` al máximo) y resuelve el caso;
* `APROBADO` con inconsistencias (importe diferente, `Pedido` incompatible o dos `Pago` distintos aprobados del mismo `Pedido`): se registra el resultado, no se afecta stock, no se pasa el `Pedido` a `PAGADO`, no se modifica indebidamente el estado comercial y se genera el caso de revisión correspondiente; solamente una aprobación produce efecto comercial;
* `RECHAZADO` no revierte un `Pedido` que ya está `PAGADO` y no crea automáticamente otro intento (el nuevo intento sólo existe cuando el comprador inicia un nuevo pago);
* autenticación/verificación específica de las notificaciones webhook de Mercado Pago: **fuera del MVP** como definición funcional (detalle de implementación/provider-specific para una etapa posterior);

---

# 37. Relación con el roadmap

El diseño conceptual del e-commerce forma parte del trabajo previo a la implementación de la nueva etapa de e-commerce e infraestructura.

El orden previsto es:

```text
Diseño funcional
      ↓
Diseño técnico
      ↓
Implementación
      ↓
Pruebas
      ↓
Estabilización
      ↓
Auditoría técnica
```

La documentación de este archivo debe actualizarse cuando se tomen nuevas decisiones funcionales relevantes.

Este documento **no implica que las funcionalidades descriptas estén implementadas**.

---

## Estado actual

El diseño conceptual forma la base del e-commerce.

El proyecto se encuentra en la **Etapa 2 — Diseño técnico del MVP** con **implementación incremental** iniciada, definida en `ECOMMERCE_ROADMAP.md`. La arquitectura técnica acordada se mantiene en `ECOMMERCE_TECHNICAL_DESIGN.md`.

**Bloques implementados y verificados (backend, por fecha):**

1. **29/09/2026:** `GET /api/productos`;
2. **29/09/2026:** `POST /api/pedidos` (inicio del checkout: primera ejecución de la validación comercial, sin `Pedido` previo; validación dentro de transacción de MariaDB con bloqueo `SELECT ... FOR UPDATE` de `productos`; `precio_unitario` obligatorio por item; precio persistido siempre desde la base de datos);
3. **29/09/2026:** verificación de email (mecanismo genérico `EmailVerificationService` + endpoints `POST /api/email-verificaciones/validar` y `/reenvio`; solicitud integrada en `POST /api/pedidos` después del `COMMIT`; token aleatorio de 256 bits guardado sólo como SHA-256; expiración 7 días y cooldown 15 minutos sobre `last_sent_at`; rotación de token en cada reenvío; transición `PEND_VERIF → PEND_PAGO` a cargo de `PedidoService` sin nueva comprobación de stock (N:1 desde el 30/09/2026: todos los `Pedido` asociados — vigentes → `PEND_PAGO`, vencidos → `NO_PAGADO` —, respuesta `pedidos[]`); único cambio de esquema: columna `last_sent_at` en `emails_verificaciones`);
4. **30/09/2026:** rutas con `{id}` (parámetros de segmento y `405` con `Allow`), vencimiento general del primer período (aplicación perezosa con `NOW()` de MariaDB; **valor operativo actual: 40 minutos**, parametrizable y **no definitivo** — ver sección 8.5), cancelación voluntaria (`POST /api/pedidos/{id}/cancelar`) y `POST /api/pedidos/{id}/pago` con `iniciar_pago`/`aceptar_correccion` (tercera validación + corrección de carrito como aceptación con sustitución en una transacción);
5. **30/09/2026:** bloque de pagos con `DummyPaymentProvider` (`PaymentValidationService → PaymentProvider → DummyPaymentProvider`, creación de `Pago` + `PaymentValidationResult`, transición `PEND_PAGO → PROCESANDO_PAGO`, aplicación de `APROBADO`/`RECHAZADO`/`EN_PROCESO` con afectación de stock y casos de revisión; **`EN_PROCESO` quedó excluido del simulador el 05/10/2026**, ver la decisión registrada al final de esta sección);
6. **03/10/2026:** canal de email `log` de DEV/MVP (`EmailSender`/`LogEmailSender` en `src/mail/`, `src/config/email.php` y composition root en `public/index.php`; ver sección 11.1);
7. **05/10/2026:** URL destino de la verificación de checkout parametrizada — `src/config/frontend.php` (`url_verificacion_checkout`) cargado y validado en el composition root `public/index.php`, que inyecta el string en `PedidoService`; el servicio ya no tiene la URL hardcodeada y arma el enlace con `?flow=checkout&token=` + `urlencode($token)`; para el MVP es configuración directa, sin selector DEV/PROD (ver `ECOMMERCE_TECHNICAL_DESIGN.md`, sección 21).

**Frontend:** los bloques listados son exclusivamente del **backend** (`D:\Incancelables E-Commerce`). El Store y el carrito (frontend en `sessionStorage`, cuenta regresiva) corresponden a `D:\Incancelables Web` y no forman parte de la implementación backend de este proyecto (ver `ECOMMERCE_ROADMAP.md`, sección 2.1).

Arquitectura en uso: `Route → Controller → Service → Repository → MariaDB`, con el SQL únicamente en Repositories; `ProductoService` no calcula disponibilidad y `PedidoService` coordina la disponibilidad y la validación comercial. La disponibilidad continúa definida como `max(0, stock físico − cantidades de PedidoItem de Pedidos en estados comprometedores)` — `PEND_VERIF`, `PEND_PAGO`, `PROCESANDO_PAGO` —, calculada en caliente y no persistida; `PAGADO` y `NO_PAGADO` no comprometen disponibilidad. No existen (ni deben documentarse como parte del MVP) `Reserva`, `stock_comprometido`, `AvailabilityService`, `StockService`, carrito persistido en backend ni `checkout_id`.

Según la auditoría de estado real del 28/09/2026 (solo lectura): el entorno DEV está operativo y la base de datos ya materializa las decisiones de modelo de este documento. No se tomó ninguna decisión de diseño nueva durante esa auditoría ni durante la implementación de los bloques posteriores: se respetaron las decisiones cerradas en este documento y en `ECOMMERCE_TECHNICAL_DESIGN.md` (las adaptaciones documentadas son la rotación del token de verificación en cada reenvío permitido, en lugar de reutilizar el vigente, y la ampliación de `emails_verificaciones` con `last_sent_at` para el cooldown; el ajuste operativo del primer período a **40 minutos** del 03/10/2026 es un cambio de valor de parámetro **no definitivo**, sin alterar la decisión de parametrización — ver sección 8.5). La corrección de carrito (aceptación con sustitución), la cancelación voluntaria, el vencimiento del primer período y los pagos con `DummyPaymentProvider` están implementados y verificados desde el 30/09/2026; siguen sin implementarse, en el backend, la **recuperación de pedidos pendientes** y la **administración**; del bloque de verificación de email, el canal de email `log` de DEV (decisión del 02/10/2026, ver sección 11.1) quedó **implementado y verificado el 03/10/2026**, y quedan pendientes el canal real de envío del email (proveedor real de producción) y la URL/asunto/plantilla definitivos (ver `ECOMMERCE_ROADMAP.md`, sección 2.1).

**Decisión registrada el 02/10/2026 (sólo actualización documental):** canales de email por entorno — **DEV** entrega por **canal `log`** (token real, sólo hash persistido, enlace real registrado en log para las pruebas, entrega considerada exitosa **sin** transporte SMTP y por lo tanto con registro de `last_sent_at`; infraestructura de desarrollo que no reemplaza el envío real) y **MVP/producción** con **canal real de envío** obligatorio antes de dar por terminado el proyecto (el proyecto **no** puede cerrarse si la verificación de email depende de leer manualmente un log). La distinción entre canales se resuelve **mediante configuración**, sin modificar la lógica de negocio de verificación (ver `ECOMMERCE_TECHNICAL_DESIGN.md`, secciones 5, 6, 15, 15.4 y 20). **Implementado y verificado el 03/10/2026:** contrato `EmailSender` + `LogEmailSender` en `src/mail/`, `src/config/email.php` (`channel = log`) y selección/inyección en `public/index.php` (composition root); el canal `log` registra el email compuesto en `error_log`, considera la entrega exitosa y registra `last_sent_at`, habilitando reenvío, rotación, cooldown, `200 reenviado` y `429 cooldown_activo`; el canal **real** sigue pendiente como requisito para dar por terminado el MVP.

**Decisión registrada el 05/10/2026:** `EN_PROCESO` queda **fuera del simulador del MVP** — el parámetro `simulacion` de `POST /api/pedidos/{id}/pago` admite únicamente `APROBADO` (por defecto) y `RECHAZADO`, y `EN_PROCESO` se rechaza con `400 {"error":"simulacion inválida"}`. Los estados `EN_PROCESO`/`PROCESANDO_PAGO` **se conservan en el modelo** (catálogo `validaciones_pagos_estados`, reglas de resultados no definitivos de las secciones 8.8 y 13.6-13.8) para una **evolución futura** con resolución asincrónica: webhook, pagos pendientes y conciliación, todos **POST-MVP**. En consecuencia, **la confirmación de resultados `EN_PROCESO` no es un pendiente obligatorio del MVP** y el flujo ejecutable del MVP no puede dejar un `Pedido` en `PROCESANDO_PAGO` sin mecanismo de resolución. Detalle técnico y verificación: `ECOMMERCE_TECHNICAL_DESIGN.md`, sección 21 (decisión del 05/10/2026).

Las decisiones tomadas en este documento sirven como base para el diseño técnico y la implementación, evitando comenzar la implementación antes de haber definido suficientemente el comportamiento esperado del sistema.

