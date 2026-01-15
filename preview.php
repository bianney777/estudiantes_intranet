<?php
require_once __DIR__ . '/includes/config.php';

$identifier = trim((string)($_GET['user'] ?? ''));
if ($identifier === '') {
    die('Usuario no especificado');
}

$siteSlugParam = isset($_GET['site']) ? sanitizeSlug((string)$_GET['site']) : '';
$pageRoute = isset($_GET['page']) ? sanitizePageRoute((string)$_GET['page']) : 'index';
if ($pageRoute === '') {
    $pageRoute = 'index';
}

// Resolver código (carpeta) a partir de código o email
$db = getDB();
$stmt = $db->prepare('SELECT id, codigo FROM estudiantes WHERE codigo = :id OR email = :id LIMIT 1');
$stmt->execute([':id' => $identifier]);
$est = $stmt->fetch();

$estudianteId = $est ? (int)$est['id'] : null;

// Elegir un nombre de carpeta válido: primero preferimos el que exista en /students/.
// Esto evita casos donde el código en BD no coincide con la carpeta ya creada.
$idFolder = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$identifier);
$dbFolder = $est ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$est['codigo']) : '';

$username = $idFolder;
if ($username === '' && $dbFolder !== '') {
    $username = $dbFolder;
}

if ($idFolder !== '' && is_dir(STUDENTS_DIR . $idFolder . '/')) {
    $username = $idFolder;
} elseif ($dbFolder !== '' && is_dir(STUDENTS_DIR . $dbFolder . '/')) {
    $username = $dbFolder;
}

$useLegacy = false;
$rutaSitio = '';
$activeSiteId = null;
$activeSlug = '';

if ($username === '') {
    die('Usuario inválido');
}

if ($estudianteId) {
    if ($siteSlugParam !== '') {
        // 1) Preferir DB (para poder contar visitas y validar propiedad)
        $stmt = $db->prepare('SELECT id, url_personalizada FROM sitios_web WHERE estudiante_id = ? AND url_personalizada = ? LIMIT 1');
        $stmt->execute([$estudianteId, $siteSlugParam]);
        $site = $stmt->fetch();
        if ($site) {
            $activeSiteId = (int)$site['id'];
            $activeSlug = (string)$site['url_personalizada'];
            $rutaSitio = studentSitePath($username, $activeSlug);
        } else {
            // 2) Fallback: si existe la carpeta aunque no esté en DB, servirla igual.
            try {
                $fallbackPath = studentSitePath($username, $siteSlugParam);
                if (is_dir($fallbackPath)) {
                    $activeSiteId = null;
                    $activeSlug = $siteSlugParam;
                    $rutaSitio = $fallbackPath;
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
    }

    if ($rutaSitio === '') {
        // Por defecto: último sitio
        $stmt = $db->prepare('SELECT id, url_personalizada FROM sitios_web WHERE estudiante_id = ? ORDER BY ultima_actualizacion DESC, id DESC LIMIT 1');
        $stmt->execute([$estudianteId]);
        $site = $stmt->fetch();
        if ($site) {
            $activeSiteId = (int)$site['id'];
            $activeSlug = (string)$site['url_personalizada'];
            if ($activeSlug !== '') {
                $rutaSitio = studentSitePath($username, $activeSlug);
            }
        }
    }
}

if ($rutaSitio === '') {
    // Legacy: students/{user}/
    $useLegacy = true;
    $rutaSitio = STUDENTS_DIR . $username . '/';
}

$pageFile = ($pageRoute === 'index') ? 'index.html' : ($pageRoute . '.html');

// Verificar si el sitio existe
if (!file_exists($rutaSitio . $pageFile)) {
    // Si pidieron una página que no existe, intentar index
    if ($pageFile !== 'index.html' && file_exists($rutaSitio . 'index.html')) {
        $pageFile = 'index.html';
        $pageRoute = 'index';
    } else {
        die('Sitio no encontrado');
    }
}

// Incrementar contador de visitas
if ($estudianteId && $activeSiteId) {
    $stmt = $db->prepare('UPDATE sitios_web SET visitas = visitas + 1 WHERE id = ? AND estudiante_id = ?');
    $stmt->execute([$activeSiteId, $estudianteId]);
}

// Mostrar el sitio
header('Content-Type: text/html; charset=utf-8');

$html = file_get_contents($rutaSitio . $pageFile);
if ($html === false) {
    die('No se pudo leer el sitio');
}

// Asegurar que los recursos relativos (css/js/img) carguen desde la carpeta correcta.
// IMPORTANTE: usar base href ABSOLUTO para que funcione también en rutas bonitas (/s/...).
$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$appBase = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
if ($appBase === '.') {
    $appBase = '';
}

$baseHref = $appBase . '/students/' . rawurlencode($username) . '/';
if (!$useLegacy && $activeSlug !== '') {
    $baseHref = $appBase . '/students/' . rawurlencode($username) . '/sites/' . rawurlencode($activeSlug) . '/';
}

// Si existen assets estándar en el sitio, los usamos como defaults
$defaultCss = file_exists($rutaSitio . 'css/style.css') ? 'css/style.css' : (file_exists($rutaSitio . 'style.css') ? 'style.css' : null);
$defaultJs = file_exists($rutaSitio . 'js/script.js') ? 'js/script.js' : (file_exists($rutaSitio . 'script.js') ? 'script.js' : null);

if (preg_match('/<head\b[^>]*>/i', $html)) {
    // Insertar <base> justo después de <head>
    $html = preg_replace('/(<head\b[^>]*>)/i', '$1' . "\n    <base href=\"" . $baseHref . "\">", $html, 1);
} else {
    // Si no hay <head>, envolver documento mínimo.
    // IMPORTANTE: agregar link/script para que el sitio cargue CSS/JS aunque el alumno haya guardado un fragmento.
    $headAssets = '';
    if ($defaultCss) {
        $headAssets .= "\n  <link rel=\"stylesheet\" href=\"" . $defaultCss . "\">";
    }
    $bodyAssets = '';
    if ($defaultJs) {
        $bodyAssets .= "\n  <script src=\"" . $defaultJs . "\"></script>";
    }
    $html = "<!doctype html><html lang=\"es\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><base href=\"{$baseHref}\">{$headAssets}</head><body>" . $html . "{$bodyAssets}</body></html>";
}

// Si el HTML ya es un documento, asegurar que al menos incluya los assets estándar cuando existan.
if ($defaultCss) {
    $hasCss = preg_match('/\brel\s*=\s*(["\"])stylesheet\1/i', $html)
        && preg_match('/\bhref\s*=\s*(["\"])(?:css\/style\.css|style\.css|styles\.css)\1/i', $html);
    if (!$hasCss && preg_match('/<head\b[^>]*>/i', $html)) {
        // Insertar justo después del <base> si está; si no, después de <head>
        if (preg_match('/<base\b[^>]*>/i', $html)) {
            $html = preg_replace('/(<base\b[^>]*>)/i', '$1' . "\n    <link rel=\"stylesheet\" href=\"" . $defaultCss . "\">", $html, 1);
        } else {
            $html = preg_replace('/(<head\b[^>]*>)/i', '$1' . "\n    <link rel=\"stylesheet\" href=\"" . $defaultCss . "\">", $html, 1);
        }
    }
}

if ($defaultJs) {
    $hasJs = preg_match('/\bsrc\s*=\s*(["\"])(?:js\/script\.js|script\.js|scripts\.js)\1/i', $html);
    if (!$hasJs) {
        if (preg_match('/<\/body>/i', $html)) {
            $html = preg_replace('/<\/body>/i', "    <script src=\"" . $defaultJs . "\"></script>\n</body>", $html, 1);
        } else {
            $html .= "\n<script src=\"" . $defaultJs . "\"></script>";
        }
    }
}

// Si el alumno puso rutas absolutas como /css/style.css, hacerlas relativas
// para que funcionen dentro de students/{user}/
$html = preg_replace_callback(
    '/\b(href|src)\s*=\s*(["\"])\/(css|js|images|img)\//i',
    function ($m) {
        // $m[2] es la comilla de apertura (" o ')
        return $m[1] . '=' . $m[2] . $m[3] . '/';
    },
    $html
);

// Compatibilidad: si el HTML usa style.css / script.js en raíz pero los archivos
// reales están en css/style.css y js/script.js, reescribir.
if (file_exists($rutaSitio . 'css/style.css')) {
    $html = preg_replace('/(<link\b[^>]*href=["\"])style\.css(["\"][^>]*>)/i', '$1css/style.css$2', $html);
}
if (file_exists($rutaSitio . 'js/script.js')) {
    $html = preg_replace('/(<script\b[^>]*src=["\"])script\.js(["\"][^>]*>)/i', '$1js/script.js$2', $html);
}

// Compatibilidad extra: muchos templates usan styles.css / scripts.js
if (file_exists($rutaSitio . 'css/style.css') && !file_exists($rutaSitio . 'styles.css')) {
    $html = preg_replace('/(<link\b[^>]*href=["\"])styles\.css(["\"][^>]*>)/i', '$1css/style.css$2', $html);
}
if (file_exists($rutaSitio . 'js/script.js') && !file_exists($rutaSitio . 'scripts.js')) {
    $html = preg_replace('/(<script\b[^>]*src=["\"])scripts\.js(["\"][^>]*>)/i', '$1js/script.js$2', $html);
}

echo $html;
?>