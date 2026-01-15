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
$username = (string)($user['username'] ?? $user['codigo'] ?? '');
$username = preg_replace('/[^a-zA-Z0-9_-]/', '', $username);

$action = (string)($_POST['action'] ?? '');
if ($action !== 'create_page') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Acción inválida']);
    exit;
}

$siteId = isset($_POST['site_id']) ? (int)$_POST['site_id'] : 0;
if ($siteId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'site_id requerido']);
    exit;
}

$title = trim((string)($_POST['title'] ?? ''));
$routeInput = trim((string)($_POST['route'] ?? ''));

if ($title === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Título requerido']);
    exit;
}

$route = $routeInput !== '' ? sanitizePageRoute($routeInput) : sanitizePageRoute($title);
if ($route === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ruta inválida']);
    exit;
}

if ($route === 'index') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'La ruta "index" está reservada']);
    exit;
}

try {
    $db = getDB();

    // Validar sitio pertenece al estudiante
    $stmt = $db->prepare('SELECT id, url_personalizada FROM sitios_web WHERE id = ? AND estudiante_id = ? LIMIT 1');
    $stmt->execute([$siteId, (int)($user['id'] ?? 0)]);
    $site = $stmt->fetch();
    if (!$site) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Sitio no permitido']);
        exit;
    }

    $slug = (string)($site['url_personalizada'] ?? '');
    if ($slug === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Sitio inválido']);
        exit;
    }

    $sitePath = studentSitePath($username, $slug);
    ensureStudentSiteFolders($sitePath);

    $db->beginTransaction();

    $exists = $db->prepare('SELECT id FROM paginas WHERE sitio_id = ? AND ruta = ? LIMIT 1');
    $exists->execute([$siteId, $route]);
    if ($exists->fetch()) {
        $db->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Ya existe una página con esa ruta']);
        exit;
    }

    $maxOrderStmt = $db->prepare('SELECT COALESCE(MAX(orden), 0) AS max_orden FROM paginas WHERE sitio_id = ?');
    $maxOrderStmt->execute([$siteId]);
    $max = $maxOrderStmt->fetch();
    $nextOrder = (int)($max['max_orden'] ?? 0) + 1;

    $ins = $db->prepare('INSERT INTO paginas (sitio_id, titulo, ruta, contenido_html, contenido_css, contenido_js, orden, visible) VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
    $ins->execute([$siteId, $title, $route, '', '', '', $nextOrder]);

    $updSite = $db->prepare("UPDATE sitios_web SET ultima_actualizacion = datetime('now') WHERE id = ? AND estudiante_id = ?");
    $updSite->execute([$siteId, (int)($user['id'] ?? 0)]);

    $db->commit();

    // Asegurar assets base
    if (!file_exists($sitePath . 'css/style.css')) {
        file_put_contents($sitePath . 'css/style.css', "/* styles */\n", LOCK_EX);
    }
    if (!file_exists($sitePath . 'js/script.js')) {
        file_put_contents($sitePath . 'js/script.js', "// scripts\n", LOCK_EX);
    }

    // Crear archivo HTML de la nueva página si no existe
    $pageFile = $route . '.html';
    if (!file_exists($sitePath . $pageFile)) {
        file_put_contents($sitePath . $pageFile, pageTemplateHtml($title), LOCK_EX);
    }

    // Regenerar navegación en todas las páginas del sitio
    $stmt = $db->prepare('SELECT titulo, ruta FROM paginas WHERE sitio_id = ? AND visible = 1 ORDER BY orden ASC, id ASC');
    $stmt->execute([$siteId]);
    $pages = $stmt->fetchAll();
    regenerateSiteNavigation($sitePath, $pages);

    echo json_encode(['success' => true, 'route' => $route]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function pageTemplateHtml(string $title): string {
    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return "<!doctype html>
<html lang=\"es\">
<head>
  <meta charset=\"utf-8\">
  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">
  <title>{$safeTitle}</title>
  <link rel=\"stylesheet\" href=\"css/style.css\">
</head>
<body>
  <header style=\"padding:18px 16px;border-bottom:1px solid rgba(0,0,0,.08);\">
    <div style=\"max-width:1000px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap\">
      <div style=\"font-weight:900\">Mi Sitio</div>
      <nav data-ewl-nav style=\"display:flex;gap:6px;flex-wrap:wrap\"></nav>
    </div>
  </header>
  <main style=\"max-width:1000px;margin:0 auto;padding:22px 16px\">
    <h1 style=\"margin:0 0 10px\">{$safeTitle}</h1>
    <p style=\"margin:0;color:#334155\">Edita esta página desde el editor.</p>
  </main>
  <script src=\"js/script.js\"></script>
</body>
</html>";
}

function buildNavLinksHtml(array $pages): string {
    $links = '';
    foreach ($pages as $p) {
        $route = (string)($p['ruta'] ?? '');
        if ($route === '') {
            continue;
        }
        $label = (string)($p['titulo'] ?? $route);
        $file = ($route === 'index') ? 'index.html' : ($route . '.html');
        $links .= '<a href="' . htmlspecialchars($file, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="color:inherit;text-decoration:none;padding:10px 12px;border-radius:10px;display:inline-block;">' .
            htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
            '</a>';
    }
    return $links;
}

function regenerateSiteNavigation(string $sitePath, array $pages): void {
    $navHtml = buildNavLinksHtml($pages);
    if ($navHtml === '') {
        return;
    }

    foreach ($pages as $p) {
        $route = (string)($p['ruta'] ?? '');
        if ($route === '') {
            continue;
        }
        $file = ($route === 'index') ? 'index.html' : ($route . '.html');
        $fullPath = $sitePath . $file;
        if (!file_exists($fullPath)) {
            continue;
        }

        $html = file_get_contents($fullPath);
        if ($html === false || $html === '') {
            continue;
        }

        // Limpieza: si quedó el texto literal "\n" (backslash+n) en el HTML, convertirlo
        // a un salto de línea real SOLO cuando aparece justo después de <body>.
        $html = preg_replace('/(<body\b[^>]*>)\\\\n\s*/i', '$1' . "\n", $html, 1);

        // 1) Preferir <nav data-ewl-nav> si existe
        if (preg_match('/<nav\b[^>]*data-ewl-nav[^>]*>.*?<\/nav>/is', $html)) {
            $html2 = preg_replace('/(<nav\b[^>]*data-ewl-nav[^>]*>)(.*?)(<\/nav>)/is', '$1' . $navHtml . '$3', $html, 1);
            if (is_string($html2) && $html2 !== '') {
                file_put_contents($fullPath, $html2, LOCK_EX);
            }
            continue;
        }

        // 2) Si hay cualquier <nav>, reemplazar el primero
        if (preg_match('/<nav\b[^>]*>.*?<\/nav>/is', $html)) {
            $html2 = preg_replace('/(<nav\b[^>]*>)(.*?)(<\/nav>)/is', '$1' . $navHtml . '$3', $html, 1);
            if (is_string($html2) && $html2 !== '') {
                file_put_contents($fullPath, $html2, LOCK_EX);
            }
            continue;
        }

        // 3) Si no hay nav, insertar header simple después de <body>
        $header = '<header style="padding:18px 16px;border-bottom:1px solid rgba(0,0,0,.08);"><div style="max-width:1000px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap"><div style="font-weight:900">Mi Sitio</div><nav data-ewl-nav style="display:flex;gap:6px;flex-wrap:wrap">' . $navHtml . '</nav></div></header>';
        if (preg_match('/<body\b[^>]*>/i', $html)) {
            $html2 = preg_replace('/(<body\b[^>]*>)/i', '$1' . "\n  " . $header, $html, 1);
            if (is_string($html2) && $html2 !== '') {
                file_put_contents($fullPath, $html2, LOCK_EX);
            }
        }
    }
}
