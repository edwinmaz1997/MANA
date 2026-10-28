-- MANA - Sistema de ventas, créditos y cobros (multiempresa)
-- MySQL 5.7+ / MariaDB 10.3+
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS superadmins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  usuario VARCHAR(60) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS planes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL,
  precio DECIMAL(12,2) NOT NULL DEFAULT 0,
  duracion_dias INT NOT NULL DEFAULT 30,
  max_usuarios INT NOT NULL DEFAULT 0 COMMENT '0 = ilimitado',
  max_clientes INT NOT NULL DEFAULT 0 COMMENT '0 = ilimitado',
  descripcion VARCHAR(255) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL,
  nit VARCHAR(30) NULL,
  telefono VARCHAR(30) NULL,
  email VARCHAR(120) NULL,
  direccion VARCHAR(255) NULL,
  logo VARCHAR(255) NULL,
  ticket_mensaje VARCHAR(255) NULL,
  moneda VARCHAR(5) NOT NULL DEFAULT 'Q',
  dias_recoger INT NOT NULL DEFAULT 90 COMMENT 'Días sin abono para recoger producto',
  plan_id INT UNSIGNED NULL,
  fecha_vencimiento DATE NULL,
  estado ENUM('activa','suspendida') NOT NULL DEFAULT 'activa',
  notas TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS membresia_pagos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT UNSIGNED NOT NULL,
  plan_id INT UNSIGNED NULL,
  monto DECIMAL(12,2) NOT NULL DEFAULT 0,
  fecha_pago DATE NOT NULL,
  desde DATE NOT NULL,
  hasta DATE NOT NULL,
  metodo VARCHAR(40) NULL,
  nota VARCHAR(255) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY (empresa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuarios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(80) NOT NULL,
  apellido VARCHAR(80) NOT NULL DEFAULT '',
  telefono VARCHAR(30) NULL,
  direccion VARCHAR(255) NULL,
  dpi VARCHAR(20) NULL,
  usuario VARCHAR(60) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  rol ENUM('admin','vendedor','cobrador') NOT NULL DEFAULT 'vendedor',
  comision_cobro_pct DECIMAL(5,2) NOT NULL DEFAULT 13.00 COMMENT '% sobre lo cobrado',
  comision_venta_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '% extra sobre ventas',
  salario_base DECIMAL(12,2) NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  ultimo_login DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY (empresa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS areas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  descripcion VARCHAR(255) NULL,
  cobrador_id INT UNSIGNED NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY (empresa_id), KEY (cobrador_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clientes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT UNSIGNED NOT NULL,
  codigo VARCHAR(20) NOT NULL,
  nombre VARCHAR(80) NOT NULL,
  apellido VARCHAR(80) NOT NULL DEFAULT '',
  telefono VARCHAR(30) NULL,
  direccion VARCHAR(255) NULL,
  maps_url VARCHAR(500) NULL,
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  foto_casa VARCHAR(255) NULL,
  dpi VARCHAR(20) NULL,
  area_id INT UNSIGNED NULL,
  notas TEXT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cli_codigo (empresa_id, codigo),
  KEY (area_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categorias (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(80) NOT NULL,
  KEY (empresa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proveedores (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  apellido VARCHAR(80) NOT NULL DEFAULT '',
  telefono VARCHAR(30) NULL,
  nit VARCHAR(30) NULL,
  direccion VARCHAR(255) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY (empresa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS productos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT UNSIGNED NOT NULL,
  codigo VARCHAR(40) NOT NULL,
  nombre VARCHAR(150) NOT NULL,
  categoria_id INT UNSIGNED NULL,
  unidad VARCHAR(30) NOT NULL DEFAULT 'Unidad',
  precio_compra DECIMAL(12,2) NOT NULL DEFAULT 0,
  precio_venta DECIMAL(12,2) NOT NULL DEFAULT 0,
  descuento_comision DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Comisión del vendedor por unidad',
  precio_contado DECIMAL(12,2) NOT NULL DEFAULT 0,
  precio_credito DECIMAL(12,2) NOT NULL DEFAULT 0,
  stock DECIMAL(12,2) NOT NULL DEFAULT 0,
  stock_minimo DECIMAL(12,2) NOT NULL DEFAULT 0,
  foto VARCHAR(255) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_prod_codigo (empresa_id, codigo),
  KEY (categoria_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ventas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT UNSIGNED NOT NULL,
  codigo VARCHAR(20) NOT NULL,
  tipo ENUM('contado','credito') NOT NULL,
  cliente_id INT UNSIGNED NULL,
  vendedor_id INT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  descuento DECIMAL(12,2) NOT NULL DEFAULT 0,
  extra_concepto VARCHAR(150) NULL,
  extra_monto DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  costo_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  comision_vendedor DECIMAL(12,2) NOT NULL DEFAULT 0,
  plazo_meses INT NULL,
  frecuencia ENUM('semanal','quincenal','mensual') NULL,
  enganche_monto DECIMAL(12,2) NOT NULL DEFAULT 0,
  fecha_enganche DATE NULL COMMENT 'NULL = pendiente de enganche',
  fecha_primer_abono DATE NULL,
  num_cuotas INT NULL,
  cuota_monto DECIMAL(12,2) NULL,
  pagado DECIMAL(12,2) NOT NULL DEFAULT 0,
  saldo DECIMAL(12,2) NOT NULL DEFAULT 0,
  ultimo_abono DATE NULL,
  proxima_visita DATE NULL COMMENT 'Fecha en que el cobrador debe regresar',
  estado ENUM('activa','pagada','anulada','recogida') NOT NULL DEFAULT 'activa',
  recoger_producto TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Marcado para recoger',
  fecha_recogido DATE NULL,
  liquidacion_id INT UNSIGNED NULL COMMENT 'Liquidación de comisión de venta',
  notas TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_venta_codigo (empresa_id, codigo),
  KEY (cliente_id), KEY (vendedor_id), KEY (fecha), KEY (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS venta_detalle (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  venta_id INT UNSIGNED NOT NULL,
  producto_id INT UNSIGNED NULL,
  descripcion VARCHAR(200) NOT NULL,
  cantidad DECIMAL(12,2) NOT NULL DEFAULT 1,
  precio_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
  costo_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
  comision_unitaria DECIMAL(12,2) NOT NULL DEFAULT 0,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  KEY (venta_id), KEY (producto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cuotas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  venta_id INT UNSIGNED NOT NULL,
  numero INT NOT NULL,
  fecha_vencimiento DATE NOT NULL,
  monto DECIMAL(12,2) NOT NULL,
  pagado DECIMAL(12,2) NOT NULL DEFAULT 0,
  estado ENUM('pendiente','parcial','pagada') NOT NULL DEFAULT 'pendiente',
  KEY (venta_id), KEY (fecha_vencimiento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS abonos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT UNSIGNED NOT NULL,
  venta_id INT UNSIGNED NOT NULL,
  cliente_id INT UNSIGNED NULL,
  usuario_id INT UNSIGNED NOT NULL COMMENT 'Quien recibió el dinero',
  fecha DATETIME NOT NULL,
  monto DECIMAL(12,2) NOT NULL,
  tipo ENUM('contado','enganche','abono') NOT NULL DEFAULT 'abono',
  metodo VARCHAR(30) NOT NULL DEFAULT 'efectivo',
  nota VARCHAR(255) NULL,
  comision_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  comision_monto DECIMAL(12,2) NOT NULL DEFAULT 0,
  liquidacion_id INT UNSIGNED NULL,
  visita_id INT UNSIGNED NULL,
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  anulado TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY (empresa_id, fecha), KEY (venta_id), KEY (usuario_id), KEY (liquidacion_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS venta_mensajes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  venta_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NULL,
  mensaje TEXT NOT NULL,
  sistema TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY (venta_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rutas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  cobrador_id INT UNSIGNED NOT NULL,
  estado ENUM('abierta','cerrada') NOT NULL DEFAULT 'abierta',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ruta (empresa_id, fecha, cobrador_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ruta_visitas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ruta_id INT UNSIGNED NOT NULL,
  venta_id INT UNSIGNED NOT NULL,
  cliente_id INT UNSIGNED NULL,
  area_id INT UNSIGNED NULL,
  orden INT NOT NULL DEFAULT 0,
  fecha_programada DATE NULL COMMENT 'Fecha de la cuota que motivó la visita',
  atrasada TINYINT(1) NOT NULL DEFAULT 0,
  estado ENUM('pendiente','pago','no_pago','no_encontrado','reprogramada') NOT NULL DEFAULT 'pendiente',
  visitado_at DATETIME NULL,
  nota VARCHAR(255) NULL,
  reprogramar_para DATE NULL,
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  abono_id INT UNSIGNED NULL,
  KEY (ruta_id), KEY (venta_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS compras (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT UNSIGNED NOT NULL,
  proveedor_id INT UNSIGNED NULL,
  fecha DATE NOT NULL,
  numero_factura VARCHAR(50) NULL,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  notas VARCHAR(255) NULL,
  usuario_id INT UNSIGNED NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY (empresa_id, fecha), KEY (proveedor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS compra_detalle (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  compra_id INT UNSIGNED NOT NULL,
  producto_id INT UNSIGNED NOT NULL,
  cantidad DECIMAL(12,2) NOT NULL,
  costo_unitario DECIMAL(12,2) NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL,
  KEY (compra_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gastos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT UNSIGNED NOT NULL,
  categoria VARCHAR(80) NOT NULL DEFAULT 'General',
  descripcion VARCHAR(255) NOT NULL,
  monto DECIMAL(12,2) NOT NULL,
  fecha DATE NOT NULL,
  usuario_id INT UNSIGNED NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY (empresa_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS liquidaciones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  tipo ENUM('cobro','comision') NOT NULL COMMENT 'cobro = entrega de efectivo del cobrador; comision = pago de comisiones/base',
  fecha DATE NOT NULL,
  desde DATE NULL,
  hasta DATE NULL,
  total_cobrado DECIMAL(12,2) NOT NULL DEFAULT 0,
  comision_cobro DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_ventas DECIMAL(12,2) NOT NULL DEFAULT 0,
  comision_venta DECIMAL(12,2) NOT NULL DEFAULT 0,
  salario_base DECIMAL(12,2) NOT NULL DEFAULT 0,
  monto DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Entregado por cobrador o pagado al empleado',
  nota VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY (empresa_id, usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

INSERT IGNORE INTO planes (id, nombre, precio, duracion_dias, max_usuarios, max_clientes, descripcion) VALUES
 (1, 'Básico', 250.00, 30, 3, 300, 'Hasta 3 usuarios y 300 clientes'),
 (2, 'Profesional', 450.00, 30, 10, 0, 'Hasta 10 usuarios, clientes ilimitados'),
 (3, 'Anual Ilimitado', 4500.00, 365, 0, 0, 'Todo ilimitado por un año');
