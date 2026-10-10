-- =====================================================================
-- Incancelables E-Commerce — Script de instalación académica
-- Base de datos: incancelables_ecommerce
-- Motor: MariaDB 10.4.32 / MySQL compatible — Charset: utf8mb4_unicode_ci
--
-- Origen del contenido: esquema y datos semilla volcados en modo SOLO
-- LECTURA (SHOW CREATE TABLE / SELECT) desde la base local existente.
-- Ningún dato personal, transaccional ni token se incluye aquí.
--
-- El script NO contiene DROP, TRUNCATE, ALTER, UPDATE ni DELETE.
-- Usa CREATE DATABASE IF NOT EXISTS y CREATE TABLE plano: si la base o
-- una tabla ya existen, la ejecución falla de forma controlada sin
-- destruir datos previos.
--
-- Contenido:
--   15 tablas (todas InnoDB, utf8mb4_unicode_ci)
--   Datos semilla únicamente en catálogos + productos (23 registros)
--   Excluidos: pedidos, pedidos_items, pagos, validaciones_pagos,
--              emails_verificaciones, casos_revision, productos_imagenes,
--              shows  (datos transaccionales / vacíos en el origen)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `incancelables_ecommerce`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `incancelables_ecommerce`;

-- =====================================================================
-- CATÁLOGOS SIN DEPENDENCIAS (hojas del grafo de claves foráneas)
-- =====================================================================

-- Catálogo de estados de Pedido. Semilla indispensable: PedidoRepository::idDeEstado()
-- consulta por `codigo` (PEND_VERIF, PEND_PAGO, PROCESANDO_PAGO, PAGADO, NO_PAGADO,
-- PAGADO_STOCK_NO_AFECTADO). Sin estas filas el checkout no puede funcionar.
CREATE TABLE `pedidos_estados` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `codigo` varchar(50) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `orden` int(10) unsigned NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogo de estados de la validación de pago. Semilla indispensable:
-- PagoRepository::idDeEstadoValidacion() consulta por `codigo`.
CREATE TABLE `validaciones_pagos_estados` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `codigo` varchar(50) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `orden` int(10) unsigned NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogo de proveedores de pago. Semilla indispensable: PagoRepository::buscarProveedor()
-- resuelve por id o por `codigo`, y PedidoService exige el código 'DUMMY'.
CREATE TABLE `proveedores` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `codigo` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_proveedores_codigo` (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogo de tipos de caso de revisión. Semilla indispensable:
-- PagoRepository::insertarCasoRevision() / resolverCasosRevision() referencian por `codigo`.
CREATE TABLE `casos_revision_tipos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `codigo` varchar(50) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `orden` int(10) unsigned NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_casos_revision_tipos_codigo` (`codigo`),
  KEY `idx_casos_revision_tipos_codigo` (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogo de estados de caso de revisión. Semilla indispensable:
-- PagoRepository::insertarCasoRevision() usa 'PENDIENTE'; resolverCasosRevision() usa 'RESUELTO'.
CREATE TABLE `casos_revision_estados` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `codigo` varchar(50) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `orden` int(10) unsigned NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_casos_revision_estados_codigo` (`codigo`),
  KEY `idx_casos_revision_estados_codigo` (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogo de estados de Show. Vacío en el origen (evolución futura / fuera del MVP).
-- Se crea vacío para conservar la FK de `shows.estado_id`.
CREATE TABLE `shows_estados` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `codigo` varchar(50) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `orden` int(10) unsigned NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registros de verificación de email. Se crea SIN datos: aquí viven emails de
-- compradores y hashes de token, que este script deliberadamente excluye.
CREATE TABLE `emails_verificaciones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(254) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_emails_verificaciones_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- TABLAS CON DEPENDENCIA SOBRE CATÁLOGOS
-- =====================================================================

-- Shows (fuera del MVP). Se crea vacía; sólo estructura.
CREATE TABLE `shows` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) NOT NULL,
  `fecha` datetime NOT NULL,
  `lugar` varchar(200) NOT NULL,
  `estado_id` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `fk_shows_estado` (`estado_id`),
  CONSTRAINT `fk_shows_estado` FOREIGN KEY (`estado_id`) REFERENCES `shows_estados` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogo de productos. Recibe los 6 productos iniciales del MVP.
-- `show_id` es NULL en todos (los shows están fuera del MVP).
CREATE TABLE `productos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `precio` decimal(10,2) NOT NULL,
  `stock` int(10) unsigned NOT NULL DEFAULT 0,
  `show_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `fk_productos_show` (`show_id`),
  CONSTRAINT `fk_productos_show` FOREIGN KEY (`show_id`) REFERENCES `shows` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pedidos. Se crea SIN datos (transaccional: contiene email, nombre y apellido
-- de compradores).
CREATE TABLE `pedidos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(254) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `apellido` varchar(100) NOT NULL,
  `estado_id` int(10) unsigned NOT NULL,
  `email_verificacion_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_pedidos_estado` (`estado_id`),
  KEY `fk_pedidos_email_verificacion` (`email_verificacion_id`),
  CONSTRAINT `fk_pedidos_email_verificacion` FOREIGN KEY (`email_verificacion_id`) REFERENCES `emails_verificaciones` (`id`),
  CONSTRAINT `fk_pedidos_estado` FOREIGN KEY (`estado_id`) REFERENCES `pedidos_estados` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Items de pedido. Se crea SIN datos (transaccional).
CREATE TABLE `pedidos_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `pedido_id` int(10) unsigned NOT NULL,
  `producto_id` int(10) unsigned NOT NULL,
  `cantidad` int(10) unsigned NOT NULL,
  `precio_unitario` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_pedidos_items_pedido` (`pedido_id`),
  KEY `fk_pedidos_items_producto` (`producto_id`),
  CONSTRAINT `fk_pedidos_items_pedido` FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`),
  CONSTRAINT `fk_pedidos_items_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pagos. Se crea SIN datos (transaccional).
CREATE TABLE `pagos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `pedido_id` int(10) unsigned NOT NULL,
  `importe` decimal(10,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pagos_pedido_id` (`pedido_id`),
  CONSTRAINT `fk_pagos_pedido` FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Validaciones de pago (1:1 con pagos, UNIQUE sobre pago_id). Se crea SIN datos.
CREATE TABLE `validaciones_pagos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `pago_id` int(10) unsigned NOT NULL,
  `estado_id` int(10) unsigned NOT NULL,
  `proveedor_id` int(10) unsigned NOT NULL,
  `referencia_proveedor` varchar(255) DEFAULT NULL,
  `importe` decimal(10,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_validaciones_pagos_pago_id` (`pago_id`),
  KEY `idx_validaciones_pagos_estado_id` (`estado_id`),
  KEY `idx_validaciones_pagos_proveedor_id` (`proveedor_id`),
  CONSTRAINT `fk_validaciones_pagos_estado` FOREIGN KEY (`estado_id`) REFERENCES `validaciones_pagos_estados` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_validaciones_pagos_pago` FOREIGN KEY (`pago_id`) REFERENCES `pagos` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_validaciones_pagos_proveedor` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Casos de revisión. Se crea SIN datos (transaccional).
CREATE TABLE `casos_revision` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `pago_id` int(10) unsigned NOT NULL,
  `tipo_id` int(10) unsigned NOT NULL,
  `estado_id` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_casos_revision_pago_id` (`pago_id`),
  KEY `idx_casos_revision_tipo_id` (`tipo_id`),
  KEY `idx_casos_revision_estado_id` (`estado_id`),
  CONSTRAINT `fk_casos_revision_estado` FOREIGN KEY (`estado_id`) REFERENCES `casos_revision_estados` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_casos_revision_pago` FOREIGN KEY (`pago_id`) REFERENCES `pagos` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_casos_revision_tipo` FOREIGN KEY (`tipo_id`) REFERENCES `casos_revision_tipos` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Imágenes de producto. Vacía en el origen (fuera del MVP). Sólo estructura.
CREATE TABLE `productos_imagenes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `producto_id` int(10) unsigned NOT NULL,
  `ruta` varchar(500) NOT NULL,
  `alt` varchar(255) DEFAULT NULL,
  `orden` int(10) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_productos_imagenes_producto_id` (`producto_id`),
  CONSTRAINT `fk_productos_imagenes_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- DATOS SEMILLA
-- =====================================================================

-- pedidos_estados (6 registros): estados del ciclo de vida del Pedido.
INSERT INTO `pedidos_estados` (`id`, `codigo`, `nombre`, `descripcion`, `orden`, `activo`) VALUES
  (1, 'PEND_VERIF', 'Pendiente de verificación', 'El pedido requiere verificar el correo electrónico antes de continuar con el pago.', 1, 1),
  (2, 'PEND_PAGO', 'Pendiente de pago', 'El pedido está listo para iniciar o continuar el proceso de pago.', 2, 1),
  (3, 'PROCESANDO_PAGO', 'Procesando pago', 'El pago fue iniciado y todavía no existe un resultado definitivo.', 3, 1),
  (4, 'PAGADO', 'Pagado', 'El pago fue aprobado y el pedido fue completado correctamente.', 4, 1),
  (5, 'NO_PAGADO', 'No pagado', 'El pedido no tiene un pago aprobado y no puede continuar como pagado.', 5, 1),
  (6, 'PAGADO_STOCK_NO_AFECTADO', 'Pagado - stock no afectado', 'El pago fue aprobado pero no se pudo afectar el stock correctamente y requiere revisión.', 6, 1);

-- validaciones_pagos_estados (5 registros): estados del resultado del proveedor.
INSERT INTO `validaciones_pagos_estados` (`id`, `codigo`, `nombre`, `descripcion`, `orden`, `activo`) VALUES
  (1, 'PENDIENTE', 'Pendiente', 'El pago todavía no fue iniciado o confirmado.', 1, 1),
  (2, 'EN_PROCESO', 'En proceso', 'El pago está siendo procesado por el proveedor.', 2, 1),
  (3, 'APROBADO', 'Aprobado', 'El proveedor confirmó y aprobó el pago.', 3, 1),
  (4, 'RECHAZADO', 'Rechazado', 'El proveedor rechazó el pago.', 4, 1),
  (5, 'CANCELADO', 'Cancelado', 'El pago fue cancelado.', 5, 1);

-- proveedores (2 registros): DUMMY es el único soportado por el MVP;
-- MERCADOPAGO queda reservado para la integración real (POST-MVP).
INSERT INTO `proveedores` (`id`, `codigo`) VALUES
  (1, 'MERCADOPAGO'),
  (2, 'DUMMY');

-- casos_revision_tipos (2 registros): tipos usados por PagoRepository.
INSERT INTO `casos_revision_tipos` (`id`, `codigo`, `nombre`, `descripcion`, `orden`, `activo`) VALUES
  (1, 'PAGO_PROCESANDO_EXCEDIDO', 'Pago procesando excedido', 'El pago permaneció en procesamiento más allá del tiempo máximo permitido.', 1, 1),
  (2, 'PAGADO_STOCK_NO_AFECTADO', 'Pagado - stock no afectado', 'El pago fue aprobado pero no se pudo completar la afectación del stock.', 2, 1);

-- casos_revision_estados (2 registros): estados del ciclo de un caso de revisión.
INSERT INTO `casos_revision_estados` (`id`, `codigo`, `nombre`, `descripcion`, `orden`, `activo`) VALUES
  (1, 'PENDIENTE', 'Pendiente', 'El caso requiere revisión y todavía no fue resuelto.', 1, 1),
  (2, 'RESUELTO', 'Resuelto', 'El caso fue revisado y se completó la acción correspondiente.', 2, 1);

-- productos (6 registros): catálogo inicial del MVP.
-- Los `id` se fijan explícitamente porque el backend y las pruebas referencian
-- productos por id (1..6). `show_id` NULL: los shows están fuera del MVP.
-- `created_at`/`updated_at` se omiten a propósito: se llenan con los DEFAULT.
INSERT INTO `productos` (`id`, `nombre`, `descripcion`, `precio`, `stock`, `show_id`, `activo`) VALUES
  (1, 'Remera Incancelables Negra - S', 'Remera oficial de Incancelables, color negro, talle S.', '25000.00', 10, NULL, 1),
  (2, 'Remera Incancelables Negra - M', 'Remera oficial de Incancelables, color negro, talle M.', '25000.00', 15, NULL, 1),
  (3, 'Remera Incancelables Negra - L', 'Remera oficial de Incancelables, color negro, talle L.', '25000.00', 12, NULL, 1),
  (4, 'Remera Incancelables Negra - XL', 'Remera oficial de Incancelables, color negro, talle XL.', '25000.00', 8, NULL, 1),
  (5, 'CD Incancelables - Álbum de estudio', 'Álbum de estudio de Incancelables en formato CD.', '18000.00', 20, NULL, 1),
  (6, 'Remera Incancelables Blanca - M', 'Remera oficial de Incancelables, color blanco, talle M.', '25000.00', 10, NULL, 1);
