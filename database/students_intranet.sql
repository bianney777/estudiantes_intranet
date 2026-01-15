-- students_intranet.sql (SQLite)
-- Esquema base para students_intranet.db

PRAGMA foreign_keys = ON;

-- Tabla de estudiantes
CREATE TABLE IF NOT EXISTS estudiantes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    codigo VARCHAR(20) UNIQUE NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    apellido VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    carrera VARCHAR(50),
    grado VARCHAR(30),
    semestre INTEGER,
    avatar VARCHAR(255),
    fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
    ultimo_login DATETIME,
    activo BOOLEAN DEFAULT 1
);

-- Tabla de sitios web
CREATE TABLE IF NOT EXISTS sitios_web (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    estudiante_id INTEGER,
    nombre_sitio VARCHAR(100),
    descripcion TEXT,
    url_personalizada VARCHAR(50) UNIQUE,
    plantilla VARCHAR(50),
    estado VARCHAR(20) DEFAULT 'borrador', -- borrador, publicado, archivado
    visitas INTEGER DEFAULT 0,
    ultima_actualizacion DATETIME DEFAULT CURRENT_TIMESTAMP,
    fecha_publicacion DATETIME,
    FOREIGN KEY (estudiante_id) REFERENCES estudiantes(id)
);

-- Tabla de páginas del sitio
CREATE TABLE IF NOT EXISTS paginas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sitio_id INTEGER,
    titulo VARCHAR(100),
    ruta VARCHAR(50), -- about, contact, portfolio, etc.
    contenido_html TEXT,
    contenido_css TEXT,
    contenido_js TEXT,
    orden INTEGER DEFAULT 0,
    visible BOOLEAN DEFAULT 1,
    FOREIGN KEY (sitio_id) REFERENCES sitios_web(id)
);

-- Tabla de recursos (imágenes, archivos)
CREATE TABLE IF NOT EXISTS recursos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    estudiante_id INTEGER,
    nombre VARCHAR(255),
    tipo VARCHAR(50),
    ruta VARCHAR(500),
    tamanio INTEGER,
    fecha_subida DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (estudiante_id) REFERENCES estudiantes(id)
);

-- Tabla de comentarios en sitios
CREATE TABLE IF NOT EXISTS comentarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sitio_id INTEGER,
    estudiante_id INTEGER,
    contenido TEXT,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sitio_id) REFERENCES sitios_web(id),
    FOREIGN KEY (estudiante_id) REFERENCES estudiantes(id)
);

-- Tabla de calificaciones
CREATE TABLE IF NOT EXISTS calificaciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sitio_id INTEGER,
    profesor_id INTEGER,
    nota DECIMAL(3,1),
    comentario TEXT,
    criterios TEXT, -- JSON con criterios evaluados
    fecha_calificacion DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sitio_id) REFERENCES sitios_web(id)
);

-- Índices útiles
CREATE INDEX IF NOT EXISTS idx_sitios_estudiante ON sitios_web(estudiante_id);
CREATE INDEX IF NOT EXISTS idx_estudiantes_grado ON estudiantes(grado);
CREATE INDEX IF NOT EXISTS idx_paginas_sitio ON paginas(sitio_id);
CREATE INDEX IF NOT EXISTS idx_recursos_estudiante ON recursos(estudiante_id);
CREATE INDEX IF NOT EXISTS idx_comentarios_sitio ON comentarios(sitio_id);
CREATE INDEX IF NOT EXISTS idx_calificaciones_sitio ON calificaciones(sitio_id);
