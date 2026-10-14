# Incancelables E-Commerce

**Trabajo Práctico Integrador N.º 1 — UPSO**  
**Modalidad:** entrega individual  
**Tecnologías principales:** PHP 8.2, Apache, MySQL/MariaDB, JavaScript, HTML y CSS  
**Fecha de entrega:** 20 de octubre de 2026

## 1. Presentación del proyecto

**Incancelables E-Commerce** es una aplicación web de comercio electrónico desarrollada para gestionar la compra de productos físicos. La solución separa el **frontend**, que ofrece la experiencia de compra, del **backend**, que expone una API HTTP con respuestas JSON y concentra las reglas de negocio, la persistencia y la comunicación con servicios externos.

El objetivo del MVP es permitir que una persona compre **sin crear una cuenta**, desde la consulta del catálogo hasta la confirmación del pedido. El recorrido implementado comprende:

1. Consultar el catálogo y la disponibilidad de productos.
2. Agregar productos al carrito y modificar cantidades.
3. Iniciar el checkout y registrar un pedido.
4. Verificar la dirección de correo electrónico mediante un enlace enviado al comprador.
5. Recuperar el pedido verificado y continuar la compra.
6. Validar nuevamente las condiciones del pedido y, si corresponde, permitir su corrección antes del pago.
7. Ejecutar un **pago simulado** (aprobado o rechazado).
8. Actualizar el estado del pedido y, cuando corresponde, descontar el stock físico.

El módulo evaluado del frontend es **Tienda**. El sitio web más amplio de Incancelables contiene otras funcionalidades que no forman parte del alcance principal de este trabajo práctico.

### Accesos

| Recurso | Enlace |
| --- | --- |
| Tienda desplegada (frontend académico) | https://xb3nderx.github.io/incancelables-frontend-academico/pages/tienda.html |
| Repositorio del frontend académico | https://github.com/xb3nderx/incancelables-frontend-academico |
| Repositorio del backend académico | https://github.com/xb3nderx/incancelables-ecommerce-academico |
| API desplegada — catálogo | https://incancelables-ecommerce-academico-production.up.railway.app/api/productos |

**Nota:** el pago es intencionalmente simulado; no se realizan cobros ni transacciones monetarias reales.

## 2. Arquitectura y organización

La aplicación sigue una arquitectura cliente-servidor, con responsabilidades separadas:

```text
Navegador
  └── Frontend: HTML + CSS + JavaScript (GitHub Pages)
        └── HTTP / JSON
              └── API REST: PHP 8.2 + Apache (Railway)
                    ├── Rutas y controladores
                    ├── Servicios y reglas de negocio
                    ├── Repositorios / PDO
                    │     └── MySQL (Railway)
                    ├── Transporte de correo
                    │     ├── Resend (producción)
                    │     └── Log (desarrollo)
                    └── Proveedor de pago
                          └── DUMMY (simulación del MVP)
```

El backend está organizado por responsabilidades: **rutas**, **controladores**, **servicios**, **repositorios**, **configuración** y **transporte de correo**. Los controladores reciben solicitudes HTTP; los servicios aplican reglas del negocio; los repositorios ejecutan operaciones de persistencia mediante PDO y consultas preparadas.

La lógica de pago se encuentra desacoplada mediante una abstracción de proveedor. En el MVP se utiliza un proveedor `DUMMY`; esto permite contemplar una integración futura con una pasarela real sin presentar dicha integración como implementada.

La verificación de correo también distingue entre la generación y validación de tokens, la composición de mensajes y el transporte de email. En producción el transporte utiliza **Resend**; en desarrollo puede utilizar un canal de registro (`log`).

### Modelo funcional y persistencia

El modelo contempla, entre otras entidades, **Producto**, **Pedido**, **PedidoItem**, **Pago** y **EmailVerification**. Los ítems del pedido conservan los datos relevantes de la operación, mientras que el stock físico se actualiza únicamente cuando se confirma un pago aprobado. En el frontend, la disponibilidad visible para agregar nuevas unidades se calcula descontando de la disponibilidad informada por la API las cantidades que el comprador ya incorporó al carrito. Este cálculo mejora la experiencia de compra, pero no sustituye las validaciones realizadas por el backend.

**Gestión de disponibilidad y consistencia:** el carrito no reserva stock físico. Esta decisión de diseño evita bloquear unidades por productos agregados a carritos que podrían abandonarse. El backend valida los productos, sus precios y la disponibilidad al crear el pedido y vuelve a comprobar las condiciones antes de procesar el pago. Se utilizan transacciones de base de datos y bloqueos de registros cuando corresponde para mantener la consistencia de las operaciones concurrentes.

**Corrección del pedido antes del pago:** una vez verificado el correo electrónico, el comprador puede continuar con el pedido asociado a su verificación. Si las condiciones comerciales cambiaron, el sistema informa las diferencias detectadas y permite corregir la selección antes de volver a intentar el pago. El mecanismo de reemplazo de pedidos permite mantener la continuidad de la compra sin aprobar operaciones con condiciones desactualizadas.

**Confirmación del pago y stock:** el stock físico se descuenta únicamente cuando se confirma un pago aprobado. Los pagos rechazados no descuentan unidades. El backend contempla situaciones excepcionales para preservar la consistencia entre el estado del pago, el pedido y el inventario.

### Alcance original, MVP y evolución

La arquitectura fue diseñada para admitir un comercio electrónico extensible, con una separación entre catálogo, pedidos, pagos, verificación de identidad por correo y proveedores externos. Para esta entrega se priorizó un **MVP transaccional de compra**, sin cuentas de usuario ni administración comercial.

**Implementado en el MVP:** catálogo de consulta, carrito, checkout invitado, creación de pedidos, validación de correo, recuperación del pedido, pago simulado, estados de pedido, control y descuento de stock, respuestas de error y despliegue en la nube.

**Fuera del alcance actual / evolución prevista:** panel administrativo y CRUD de productos desde una interfaz de gestión, autenticación de usuarios, pagos reales, notificaciones de pasarelas mediante webhooks, seguimiento logístico y funcionalidades comerciales adicionales. Estas posibilidades forman parte de la proyección del sistema y **no deben interpretarse como funcionalidades entregadas**.

## 3. Cómo ejecutar el proyecto localmente

### Requisitos

- PHP 8.2 con extensiones **PDO MySQL** y **cURL** habilitadas.
- Servidor web Apache con soporte para reescritura de URL (`mod_rewrite`), o una configuración equivalente que dirija las peticiones al punto de entrada del backend.
- MySQL o MariaDB.
- Navegador web y un servidor HTTP para el frontend (por ejemplo, Live Server).
- Acceso a los repositorios académicos.

### Backend

1. Clonar o descargar el repositorio:

   ```bash
   git clone https://github.com/xb3nderx/incancelables-ecommerce-academico.git
   ```

2. Crear una base de datos MySQL/MariaDB y ejecutar el script **`sql/incancelables_ecommerce.sql`**, incluido en el repositorio. Este script contiene la estructura y los datos iniciales necesarios.

3. Configurar las variables de entorno descriptas en la sección 5. No subir contraseñas ni claves de API al repositorio. Si el repositorio incluye un archivo `.env.example`, utilizarlo como referencia para generar un `.env` local.

4. Configurar Apache para servir el directorio **`public/`** del backend como raíz pública. El punto de entrada HTTP es `public/index.php`. Habilitar la reescritura de rutas correspondiente.

5. Iniciar Apache y MySQL/MariaDB. Verificar la instalación consultando:

   ```http
   GET /api/productos
   ```

   La URL completa dependerá del dominio y puerto configurados localmente.

### Frontend

1. Clonar o descargar el repositorio:

   ```bash
   git clone https://github.com/xb3nderx/incancelables-frontend-academico.git
   ```

2. Configurar la URL del backend del e-commerce en **`scripts/api/tiendaConfig.js`** para que apunte al servidor local, si se ejecutará el frontend fuera del despliegue académico.
3. Servir el frontend mediante HTTP y abrir **`pages/tienda.html`**. No se recomienda abrir la página directamente mediante `file://`.
4. Comprobar que el backend permita el origen HTTP desde el cual se está sirviendo el frontend (configuración CORS).

**Correo en local:** para probar el flujo sin enviar emails reales, puede utilizarse el canal `log`. En ese caso, el enlace o token de verificación se consulta en el registro generado por el backend; no se envía un mensaje al buzón del comprador. Para envíos reales se requiere configurar Resend.

**Importante:** la configuración local y la desplegada son diferentes. Las URL públicas, el origen autorizado por CORS, la conexión a base de datos y el canal de correo deben corresponder al entorno donde se ejecuta la aplicación.

## 4. Endpoints de la API

La API utiliza el prefijo **`/api`** y comunica sus resultados mediante JSON. La siguiente tabla resume las rutas implementadas para el MVP:

| Método | Endpoint | Función |
| --- | --- | --- |
| `GET` | `/api/productos` | Consultar catálogo y disponibilidad. |
| `POST` | `/api/pedidos` | Crear un pedido a partir del checkout. |
| `POST` | `/api/pedidos/{id}/cancelar` | Solicitar la cancelación de un pedido bajo las condiciones admitidas. |
| `POST` | `/api/pedidos/{id}/pago` | Iniciar un intento de pago mediante el proveedor simulado. |
| `POST` | `/api/email-verificaciones/validar` | Validar un token de verificación de correo. |
| `POST` | `/api/email-verificaciones/reenvio` | Solicitar el reenvío de la verificación, sujeto a restricciones. |
| `POST` | `/api/email-verificaciones/pedidos` | Recuperar pedidos asociados a una verificación autorizada. |
| `POST` | `/api/email-verificaciones/pedidos/reemplazo` | Gestionar el reemplazo asociado al flujo de verificación de pedidos. |

Los endpoints `POST` reciben cuerpos JSON con los datos requeridos por cada operación. Las operaciones protegidas utilizan los controles de autorización mediante token previstos por el flujo. El endpoint de reenvío de verificación (`/api/email-verificaciones/reenvio`) constituye una excepción: recibe el identificador del pedido, no exige un token y envía el correo únicamente a la dirección registrada, sujeto a las restricciones de reenvío implementadas.

### Ejemplo de consulta

```bash
curl -i https://incancelables-ecommerce-academico-production.up.railway.app/api/productos
```

### Códigos HTTP y errores

La API utiliza códigos HTTP acordes con el resultado de las operaciones, incluidos casos exitosos, errores de solicitud, conflictos de negocio, recursos o rutas inexistentes y errores internos. Los errores se comunican mediante respuestas JSON controladas.

Por ejemplo, una ruta inexistente devuelve `404 Not Found`:

```json
{"error":"Ruta no encontrada"}
```

**Alcance del catálogo:** la API académica expone la **consulta** de productos, pero no endpoints públicos `POST`, `PUT` y `DELETE` para administrarlos. Esta decisión se explica expresamente en la sección 8 (R1).

## 5. Variables de entorno y configuración

El backend separa la configuración sensible del código fuente. Las variables principales son:

| Variable | Propósito |
| --- | --- |
| `DB_HOST` | Servidor de MySQL/MariaDB. |
| `DB_PORT` | Puerto de la base de datos. |
| `DB_NAME` | Nombre de la base de datos. |
| `DB_USER` | Usuario de conexión. |
| `DB_PASSWORD` | Contraseña de conexión. |
| `EMAIL_CHANNEL` | Transporte de email (`log` o `resend`, según el entorno). |
| `RESEND_API_KEY` | Credencial del servicio Resend, requerida para envíos reales. |
| `RESEND_FROM` | Dirección remitente habilitada en Resend. |

No deben publicarse los valores reales de `DB_PASSWORD` ni `RESEND_API_KEY`. En Railway se configuran como variables del servicio; en desarrollo local se utiliza la configuración de entorno prevista por el backend.

Además de estas variables, la configuración del frontend y del backend debe mantener coherencia entre la **URL de la API**, la **URL de retorno para verificar el correo** y los **orígenes permitidos por CORS**. Estos valores dependen del entorno de ejecución.

## 6. Servicios externos e integraciones

### Resend — integración real del backend

El backend utiliza **Resend** para enviar los correos de verificación necesarios para continuar el checkout. El transporte se encuentra separado de las reglas de generación y validación de tokens. Si falla el envío, el sistema puede informar la situación sin presentar falsamente el correo como enviado.

### Railway — alojamiento y base de datos

Railway aloja el backend PHP/Apache y una instancia de MySQL utilizada por la aplicación académica. La API se publica mediante HTTPS.

### GitHub Pages — publicación del frontend

El frontend académico se publica como sitio estático en GitHub Pages y consume la API desplegada mediante solicitudes HTTP.

### Google Apps Script — integración del sitio ampliado

El sitio Incancelables dispone de funcionalidades adicionales, como newsletter/formularios, vinculadas con **Google Apps Script**. Esta integración pertenece al sitio web ampliado y **no al flujo de Tienda evaluado como MVP**. Por transparencia, no se presenta como una segunda integración externa propia del checkout.

### Pago DUMMY — simulación, no servicio externo

El pago se procesa mediante un proveedor simulado con resultados **APROBADO** y **RECHAZADO**. Esta modalidad fue acordada con el docente para el alcance académico. No implica conexión con Mercado Pago ni con otra pasarela real, y no debe contabilizarse como integración externa.

## 7. Despliegue

El sistema académico se encuentra publicado en dos servicios independientes:

- **Frontend:** GitHub Pages, desde el repositorio `incancelables-frontend-academico`.
- **Backend:** Railway, a partir del repositorio `incancelables-ecommerce-academico`, utilizando un **Dockerfile** con PHP 8.2 y Apache.
- **Persistencia:** base de datos MySQL en Railway.
- **Correo de producción:** Resend.

El contenedor del backend inicia Apache con la configuración necesaria para utilizar `mpm_prefork`. El servidor expone el directorio `public/` y atiende las rutas de la API.

### Inicialización de la base de datos en Railway

El archivo **`sql/incancelables_ecommerce.sql`** es el script de referencia para reproducir el esquema y los datos iniciales. En el despliegue académico se utilizó su contenido, con la salvedad de **omitir la instrucción de creación de base de datos**, dado que la base ya había sido creada por Railway. No se requirió alterar el modelo de tablas para este motivo.

### Validación del despliegue

Se comprobó el flujo completo de compra en el entorno publicado: consulta de catálogo, carrito, creación de pedido, envío real de correo mediante Resend, verificación, continuación del checkout, aprobación de pago simulado y descuento de stock. También se verificó la respuesta controlada `404` para una ruta inexistente.

## 8. Correspondencia con los requisitos del trabajo práctico (R1–R10)

Esta sección diferencia los requisitos cumplidos, las adaptaciones de alcance y los aspectos que no corresponden a una entrega individual.

| Requisito | Estado y evidencia |
| --- | --- |
| **R1 — CRUD completo** | **Adaptación de alcance aceptada por el docente.** La API implementa operaciones de consulta de productos, creación y recuperación de pedidos, cancelación y procesamiento de pagos. Estas funcionalidades permiten gestionar el ciclo de vida de los pedidos mediante endpoints específicos, con validaciones de negocio, control de estados y persistencia en la base de datos. El diseño responde al alcance del MVP, centrado en el proceso de compra y la integridad de las operaciones. |
| **R2 — HTTP y respuestas** | La API responde con JSON y códigos HTTP según el resultado de la solicitud, incluyendo errores controlados. |
| **R3 — Consumo desde frontend** | La Tienda utiliza JavaScript para consumir la API y presentar resultados y errores al usuario. |
| **R4 — Servicios externos** | **Resend** es una integración real invocada por el backend. **Google Apps Script** corresponde a funcionalidades del sitio ampliado, fuera del alcance de Tienda. El proveedor de pago **DUMMY** fue autorizado como simulación, pero no es una integración externa. Por lo tanto, no se afirma que el módulo Tienda tenga dos servicios externos reales integrados. |
| **R5 — Manejo de fallos externos** | El envío de correo contempla resultados de éxito o fallo; el sistema distingue la creación del pedido de la confirmación del envío y comunica los errores sin afirmar que se envió un mensaje cuando no ocurrió. |
| **R6 — Despliegue en la nube** | Frontend en GitHub Pages y backend/base de datos en Railway, accesibles mediante HTTPS. |
| **R7 — Configuración por entorno** | Conexión a base de datos y transporte de email configurados mediante variables de entorno; credenciales fuera del código versionado. |
| **R8 — Seguridad y robustez** | Consultas preparadas mediante PDO, validaciones en el servidor, controles de autorización por token, CORS restringido, manejo centralizado de excepciones y registro interno de errores. Las respuestas HTTP controladas evitan exponer detalles internos en los casos comprobados. |
| **R9 — Documentación y SQL** | Este README reúne presentación, ejecución local, endpoints, variables, integraciones, despliegue, limitaciones y correspondencia con la consigna. El script reproducible está en `sql/incancelables_ecommerce.sql`. |
| **R10 — Trabajo colaborativo / aportes** | **No aplica:** el trabajo fue desarrollado y presentado individualmente. |

Las adaptaciones de **R1** y del **pago simulado** responden al alcance conversado con el docente. En **R4** se declara de manera explícita qué integración forma parte de Tienda y cuál corresponde al sitio más amplio, sin equipararlas artificialmente.

## 9. Limitaciones conocidas

- **Sin administración de productos:** no existe en esta entrega un módulo de alta, modificación o eliminación de productos desde la API pública o una interfaz administrativa.
- **Pagos simulados:** las aprobaciones y rechazos permiten probar el ciclo de compra, pero no procesan dinero real ni incluyen conciliación con una pasarela.
- **Checkout invitado:** no hay registro, inicio de sesión ni historial de compras asociado a cuentas de clientes.
- **Dependencia del correo:** para continuar el flujo habitual es necesario completar la verificación por email; los problemas de entrega pueden demorar la operación.
- **Alcance funcional acotado:** logística, facturación, administración comercial y otras capacidades previstas para una versión productiva completa quedan fuera del MVP.

## 10. Estructura de los repositorios

El repositorio del backend contiene el código de la API, la configuración del entorno, las pruebas y el script SQL de inicialización. La organización funcional incluye:

```text
incancelables-ecommerce-academico/
├── public/                 # Punto de entrada HTTP
├── routes/                 # Definición de endpoints
├── src/
│   ├── config/             # Configuración
│   ├── controllers/        # Adaptación HTTP
│   ├── services/           # Reglas de negocio
│   ├── repositories/       # Acceso y persistencia mediante PDO
│   ├── models/             # Modelos del dominio
│   ├── payments/           # Proveedor de pago simulado
│   └── mail/               # Transporte de email
├── sql/
│   └── incancelables_ecommerce.sql
└── Dockerfile              # Imagen de despliegue académico
```


### Estructura del frontend

El frontend está desarrollado con HTML, CSS y JavaScript, sin utilizar frameworks. Se organiza en páginas, estilos y módulos JavaScript que separan la presentación visual, la gestión del carrito, el proceso de compra y la comunicación con el backend.

La siguiente estructura destaca los archivos principales relacionados con el módulo Tienda:

```text
incancelables-frontend-academico/
├── pages/
│   ├── tienda.html             # Catálogo, carrito y checkout
│   └── resultado.html          # Verificación de email y continuación del pago
├── scripts/
│   ├── tienda.js               # Presentación del catálogo y flujo de compra
│   ├── carrito.js              # Gestión del carrito y sus cantidades
│   ├── resultado.js            # Verificación, recuperación y pago de pedidos
│   └── api/
│       ├── apiConfig.js        # Configuración de la URL del backend del newsletter
│       └── tiendaClient.js     # Comunicación HTTP con la API del e-commerce
└── ...                         # Otros componentes del sitio
```

**Responsabilidades de los componentes de Tienda:**

- **Catálogo (`tienda.html` y `tienda.js`):** presenta los productos obtenidos desde la API, sus precios y disponibilidad, y permite iniciar la selección de artículos.
- **Carrito (`carrito.js`):** administra los productos seleccionados, cantidades y totales. Mantiene el estado del carrito en `sessionStorage` y calcula la disponibilidad visible considerando las unidades ya seleccionadas.
- **Checkout (`tienda.js` y módulos asociados):** reúne los datos necesarios para crear el pedido y comunica al comprador los resultados de las validaciones del backend.
- **Verificación y continuación (`resultado.html` y `resultado.js`):** procesa el enlace de verificación recibido por correo, recupera los pedidos autorizados y permite continuar la compra.
- **Corrección y pago (`resultado.js`):** gestiona las respuestas de validación previas al pago, la corrección de condiciones comerciales y los resultados del proveedor de pago simulado.
- **Cliente de API (`scripts/api/tiendaClient.js`):** centraliza las solicitudes HTTP del módulo Tienda y el tratamiento de las respuestas del backend.

El frontend gestiona la interacción con el comprador, mientras que el backend conserva la responsabilidad de validar precios, disponibilidad, autorización, estados de pedidos y pagos, y operaciones sobre el stock físico.

---

**Resumen:** Incancelables E-Commerce entrega un flujo de compra funcional de extremo a extremo, con frontend y backend desacoplados, persistencia, verificación real por correo, pago simulado y despliegue en la nube. Las funcionalidades no implementadas y las adaptaciones respecto de la consigna se identifican expresamente para permitir una evaluación precisa del alcance real.
