<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

$user = $_SESSION['user'];
$username = $user['username'] ?? '';
$username = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$username);

if ($username === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Usuario inválido']);
    exit;
}

$siteId = isset($_POST['site_id']) ? (int)$_POST['site_id'] : 0;
$pageRoute = isset($_POST['page']) ? sanitizePageRoute((string)$_POST['page']) : 'index';
if ($pageRoute === '') {
    $pageRoute = 'index';
}

$rutaSitio = STUDENTS_DIR . $username . '/';
$pageFile = ($pageRoute === 'index') ? 'index.html' : ($pageRoute . '.html');

$db = getDB();
$activeSlug = null;
$sitePath = null;

if ($siteId > 0) {
    // Validar que el sitio pertenezca al estudiante autenticado
    $stmt = $db->prepare('SELECT id, url_personalizada FROM sitios_web WHERE id = ? AND estudiante_id = ? LIMIT 1');
    $stmt->execute([$siteId, (int)($user['id'] ?? 0)]);
    $site = $stmt->fetch();
    if (!$site) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Sitio no permitido']);
        exit;
    }
    $activeSlug = (string)($site['url_personalizada'] ?? '');
    if ($activeSlug === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Sitio inválido']);
        exit;
    }

    $sitePath = studentSitePath($username, $activeSlug);
    ensureStudentSiteFolders($sitePath);
} else {
    // Legacy (1 sitio por alumno)
    if (!file_exists($rutaSitio)) {
        crearCarpetaEstudiante($username);
    }
    if (!file_exists($rutaSitio . 'css/')) mkdir($rutaSitio . 'css/', 0777, true);
    if (!file_exists($rutaSitio . 'js/')) mkdir($rutaSitio . 'js/', 0777, true);
    $sitePath = $rutaSitio;
}

$html = $_POST['html'] ?? '';
$css = $_POST['css'] ?? '';
$js = $_POST['js'] ?? '';

try {
    file_put_contents($sitePath . $pageFile, (string)$html, LOCK_EX);
    file_put_contents($sitePath . 'css/style.css', (string)$css, LOCK_EX);
    file_put_contents($sitePath . 'js/script.js', (string)$js, LOCK_EX);

    // Compatibilidad: algunos HTML referencian style.css/script.js en la raíz
    file_put_contents($sitePath . 'style.css', (string)$css, LOCK_EX);
    file_put_contents($sitePath . 'script.js', (string)$js, LOCK_EX);

    // Actualizar fecha de modificación (y crear registro si no existe en modo legacy)
    $db->beginTransaction();

    $estudianteId = $user['id'] ?? null;
    if ($estudianteId !== null) {
        if ($siteId > 0) {
            $stmt = $db->prepare("UPDATE sitios_web SET ultima_actualizacion = datetime('now') WHERE id = ? AND estudiante_id = ?");
            $stmt->execute([$siteId, (int)$estudianteId]);
        } else {
            $stmt = $db->prepare("UPDATE sitios_web SET ultima_actualizacion = datetime('now') WHERE estudiante_id = ?");
            $stmt->execute([(int)$estudianteId]);

            if ($stmt->rowCount() === 0) {
                $ins = $db->prepare("INSERT INTO sitios_web (estudiante_id, nombre_sitio, descripcion, url_personalizada, plantilla, estado, ultima_actualizacion) VALUES (?, ?, ?, ?, ?, 'borrador', datetime('now'))");
                $url = strtolower($username);
                $url = preg_replace('/[^a-z0-9-]/', '-', $url);
                $url = preg_replace('/-+/', '-', $url);
                $url = trim($url, '-');
                if ($url === '') {
                    $url = 'sitio-' . (int)$estudianteId;
                }
                $ins->execute([
                    (int)$estudianteId,
                    'Mi sitio',
                    null,
                    $url,
                    'portfolio'
                ]);
                $siteId = (int)$db->lastInsertId();
            }
        }

        // Persistir página en tabla paginas (si existe esquema)
        if ($siteId > 0) {
            $check = $db->prepare('SELECT id FROM paginas WHERE sitio_id = ? AND ruta = ? LIMIT 1');
            $check->execute([(int)$siteId, $pageRoute]);
            $pageRow = $check->fetch();
            if ($pageRow) {
                $upd = $db->prepare("UPDATE paginas SET contenido_html = ?, contenido_css = ?, contenido_js = ? WHERE id = ?");
                $upd->execute([(string)$html, (string)$css, (string)$js, (int)$pageRow['id']]);
            } else {
                $ins = $db->prepare('INSERT INTO paginas (sitio_id, titulo, ruta, contenido_html, contenido_css, contenido_js, orden, visible) VALUES (?, ?, ?, ?, ?, ?, 0, 1)');
                $titulo = ($pageRoute === 'index') ? 'Inicio' : ucfirst(str_replace('-', ' ', $pageRoute));
                $ins->execute([(int)$siteId, $titulo, $pageRoute, (string)$html, (string)$css, (string)$js]);
            }
        }
    }

    $db->commit();

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
