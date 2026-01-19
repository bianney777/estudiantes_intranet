<?php
session_start();
date_default_timezone_set('America/Managua');

// Raíz del proyecto (path absoluto, sin '..')
$__projectRoot = realpath(__DIR__ . '/..');
if ($__projectRoot === false) {
    $__projectRoot = dirname(__DIR__);
}
define('PROJECT_ROOT', $__projectRoot);

// Configuración
define('SITE_NAME', 'Intranet Estudiantil - Publica tu Web');
define('SITE_URL', 'http://localhost/estudiantes_intranet/');

// SQLite
define('DB_PATH', PROJECT_ROOT . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'students_intranet.db');

define('STUDENTS_DIR', PROJECT_ROOT . DIRECTORY_SEPARATOR . 'students' . DIRECTORY_SEPARATOR);
define('TEMPLATES_DIR', PROJECT_ROOT . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR);
define('UPLOAD_DIR', PROJECT_ROOT . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR);

define('STUDENT_SITES_SUBDIR', 'sites');

// Crear directorios si no existen
if (!file_exists(STUDENTS_DIR)) mkdir(STUDENTS_DIR, 0777, true);
if (!file_exists(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0777, true);

// Carreras disponibles
$CARRERAS = [
    'Ingeniería en Sistemas',
    'Diseño Gráfico',
    'Comunicación Social',
    'Administración de Empresas',
    'Derecho',
    'Medicina',
    'Arquitectura',
    'Psicología'
];

// Grados disponibles (puedes ajustar a tu institución)
$GRADOS = [
    '7mo',
    '8vo',
    '9no'
];

// Plantillas disponibles
$PLANTILLAS = [
    'portfolio' => 'Portafolio Personal',
    'blog' => 'Blog Personal',
    'negocio' => 'Sitio de Negocio',
    'cv' => 'Currículum Online',
    'landing' => 'Página de Aterrizaje'
];

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    // Mejorar concurrencia/estabilidad (ej: PC + teléfono a la vez)
    // - busy_timeout: esperar antes de fallar por lock
    // - WAL: reduce bloqueos entre lecturas/escrituras
    try {
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
    } catch (Throwable $e) {
        // Si el entorno no permite cambiar el modo, continuamos con defaults.
    }

    // Auto-inicializar esquema si falta (evita: "no such table: estudiantes")
    ensureDatabaseSchema($pdo);
    // Migraciones ligeras (roles/maestros) sin borrar la BD
    ensureTeacherSchema($pdo);
    // Migración: grado de estudiante
    ensureStudentGradeSchema($pdo);
    // Migración: presencia/última actividad del estudiante
    ensureStudentPresenceSchema($pdo);
    return $pdo;
}

function ensureDatabaseSchema(PDO $pdo): void {
    $row = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='estudiantes'")->fetch();
    if ($row) {
        return;
    }

    $schemaPath = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'students_intranet.sql';
    if (!file_exists($schemaPath)) {
        throw new RuntimeException(
            'Falta el esquema SQLite. No existe: ' . $schemaPath .
            ' (abre /database/init_db.php o restaura students_intranet.sql)'
        );
    }

    $schemaSql = file_get_contents($schemaPath);
    if ($schemaSql === false) {
        throw new RuntimeException('No se pudo leer: ' . $schemaPath);
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec($schemaSql);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function tableExists(PDO $pdo, string $tableName): bool {
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
    $stmt->execute([$tableName]);
    return (bool)$stmt->fetch();
}

function columnExists(PDO $pdo, string $tableName, string $columnName): bool {
    $stmt = $pdo->prepare("PRAGMA table_info(\"" . str_replace('"', '""', $tableName) . "\")");
    $stmt->execute();
    $cols = $stmt->fetchAll();
    foreach ($cols as $c) {
        if (isset($c['name']) && (string)$c['name'] === $columnName) {
            return true;
        }
    }
    return false;
}

function ensureTeacherSchema(PDO $pdo): void {
    // Si ni siquiera existe el esquema base, no hacemos nada aquí.
    if (!tableExists($pdo, 'estudiantes')) {
        return;
    }

    $pdo->beginTransaction();
    try {
        // Tabla de profesores/maestros
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS profesores (\n" .
            "    id INTEGER PRIMARY KEY AUTOINCREMENT,\n" .
            "    codigo VARCHAR(20) UNIQUE NOT NULL,\n" .
            "    nombre VARCHAR(100) NOT NULL,\n" .
            "    apellido VARCHAR(100) NOT NULL,\n" .
            "    email VARCHAR(100) UNIQUE NOT NULL,\n" .
            "    password VARCHAR(255) NOT NULL,\n" .
            "    fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,\n" .
            "    ultimo_login DATETIME,\n" .
            "    activo BOOLEAN DEFAULT 1\n" .
            ");"
        );

        // Comentarios: permitir comentarios de profesor sin romper los de estudiantes.
        // (SQLite no permite agregar FK con ALTER TABLE de forma simple; guardamos el id y listo.)
        if (tableExists($pdo, 'comentarios') && !columnExists($pdo, 'comentarios', 'profesor_id')) {
            $pdo->exec('ALTER TABLE comentarios ADD COLUMN profesor_id INTEGER');
        }

        // Índices útiles
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_profesores_codigo ON profesores(codigo)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_profesores_email ON profesores(email)');
        if (tableExists($pdo, 'comentarios') && columnExists($pdo, 'comentarios', 'profesor_id')) {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comentarios_profesor ON comentarios(profesor_id)');
        }

        // Seed: usuario maestro demo
        $stmt = $pdo->prepare('SELECT id FROM profesores WHERE codigo = ? OR email = ? LIMIT 1');
        $stmt->execute(['maestro', 'maestro@eweblab.local']);
        $exists = $stmt->fetch();
        if (!$exists) {
            $hash = password_hash('maestro123', PASSWORD_BCRYPT);
            $ins = $pdo->prepare('INSERT INTO profesores (codigo, nombre, apellido, email, password, activo) VALUES (?, ?, ?, ?, ?, 1)');
            $ins->execute(['maestro', 'Maestro', 'Demo', 'maestro@eweblab.local', $hash]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function ensureStudentGradeSchema(PDO $pdo): void {
    if (!tableExists($pdo, 'estudiantes')) {
        return;
    }

    if (columnExists($pdo, 'estudiantes', 'grado')) {
        return;
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec('ALTER TABLE estudiantes ADD COLUMN grado VARCHAR(30)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_estudiantes_grado ON estudiantes(grado)');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function ensureStudentPresenceSchema(PDO $pdo): void {
    if (!tableExists($pdo, 'estudiantes')) {
        return;
    }

    if (columnExists($pdo, 'estudiantes', 'last_seen_at')) {
        return;
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec('ALTER TABLE estudiantes ADD COLUMN last_seen_at DATETIME');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_estudiantes_last_seen_at ON estudiantes(last_seen_at)');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function markStudentLastSeen(int $studentId): void {
    if ($studentId <= 0) {
        return;
    }

    // Evitar escribir en BD en cada request (throttle por sesión)
    $now = time();
    $last = (int)($_SESSION['_last_seen_ping'] ?? 0);
    if ($last > 0 && ($now - $last) < 20) {
        return;
    }
    $_SESSION['_last_seen_ping'] = $now;

    try {
        $db = getDB();
        if (!columnExists($db, 'estudiantes', 'last_seen_at')) {
            return;
        }
        // Guardar en hora local para que PHP (timezone America/Managua) lo interprete correctamente.
        $stmt = $db->prepare("UPDATE estudiantes SET last_seen_at = strftime('%Y-%m-%d %H:%M:%S','now','localtime') WHERE id = ?");
        $stmt->execute([(int)$studentId]);
    } catch (Throwable $e) {
        // silencioso: no romper la navegación por un ping
    }
}

// Conectar a SQLite (compatibilidad con código existente)
function getDB(): PDO {
    try {
        return db();
    } catch (PDOException $e) {
        die('Error de conexión: ' . $e->getMessage());
    }
}

// Función para crear carpeta de estudiante
function crearCarpetaEstudiante(string $username): string {
    $ruta = STUDENTS_DIR . $username . '/';
    if (!file_exists($ruta)) {
        mkdir($ruta, 0777, true);
        mkdir($ruta . 'images/', 0777, true);
        mkdir($ruta . 'css/', 0777, true);
        mkdir($ruta . 'js/', 0777, true);
    }
    return $ruta;
}

function sanitizeSlug(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9-]/', '-', $value);
    $value = preg_replace('/-+/', '-', $value);
    $value = trim($value, '-');
    return $value;
}

function sanitizePageRoute(string $value): string {
    // rutas simples tipo: index, about, gallery, mision-vision
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9-]/', '-', $value);
    $value = preg_replace('/-+/', '-', $value);
    $value = trim($value, '-');
    return $value;
}

function studentSitesRoot(string $username): string {
    $username = preg_replace('/[^a-zA-Z0-9_-]/', '', $username);
    $base = crearCarpetaEstudiante($username);
    $root = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . STUDENT_SITES_SUBDIR . DIRECTORY_SEPARATOR;
    if (!file_exists($root)) {
        mkdir($root, 0777, true);
    }
    return $root;
}

function studentSitePath(string $username, string $slug): string {
    $username = preg_replace('/[^a-zA-Z0-9_-]/', '', $username);
    $slug = sanitizeSlug($slug);
    if ($slug === '') {
        throw new RuntimeException('Slug inválido');
    }
    $path = studentSitesRoot($username) . $slug . DIRECTORY_SEPARATOR;
    return $path;
}

function ensureStudentSiteFolders(string $sitePath): void {
    if (!file_exists($sitePath)) {
        mkdir($sitePath, 0777, true);
    }
    if (!file_exists($sitePath . 'css/')) mkdir($sitePath . 'css/', 0777, true);
    if (!file_exists($sitePath . 'js/')) mkdir($sitePath . 'js/', 0777, true);
    if (!file_exists($sitePath . 'images/')) mkdir($sitePath . 'images/', 0777, true);
}

// Fusinción para URL amigable
function generarUrlAmigable(string $nombre): string {
    $url = strtolower($nombre);
    $url = preg_replace('/[^a-z0-9-]/', '-', $url);
    $url = preg_replace('/-+/', '-', $url);
    return trim($url, '-');
}
?>