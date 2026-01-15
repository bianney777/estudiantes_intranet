<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$user = $_SESSION['user'];

$db = getDB();
$username = (string)($user['username'] ?? $user['codigo'] ?? '');
$username = preg_replace('/[^a-zA-Z0-9_-]/', '', $username);

$requestedSiteId = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;
$requestedPage = isset($_GET['page']) ? sanitizePageRoute((string)$_GET['page']) : 'index';
if ($requestedPage === '') {
    $requestedPage = 'index';
}

// Sitios del estudiante (para selector)
$stmt = $db->prepare('SELECT id, nombre_sitio, url_personalizada, ultima_actualizacion FROM sitios_web WHERE estudiante_id = ? ORDER BY ultima_actualizacion DESC, id DESC');
$stmt->execute([(int)$user['id']]);
$allSites = $stmt->fetchAll();

$activeSite = null;
$activeSiteId = 0;
$activeSlug = '';
$activeSitePath = '';
$useLegacy = false;

if ($requestedSiteId > 0) {
    $stmt = $db->prepare('SELECT * FROM sitios_web WHERE id = ? AND estudiante_id = ? LIMIT 1');
    $stmt->execute([$requestedSiteId, (int)$user['id']]);
    $activeSite = $stmt->fetch();
}

if (!$activeSite && !empty($allSites)) {
    // por defecto: último sitio del estudiante
    $activeSite = $allSites[0];
}

if ($activeSite) {
    $activeSiteId = (int)($activeSite['id'] ?? 0);
    $activeSlug = (string)($activeSite['url_personalizada'] ?? '');
    if ($activeSlug !== '' && $username !== '') {
        $activeSitePath = studentSitePath($username, $activeSlug);
        ensureStudentSiteFolders($activeSitePath);
    }
}

if (!$activeSite || $activeSitePath === '') {
    // Compatibilidad: modo legacy (1 sitio en students/{user}/)
    $useLegacy = true;
    $activeSitePath = STUDENTS_DIR . $username . '/';
    if (!file_exists($activeSitePath)) {
        crearCarpetaEstudiante($username);
    }
    if (!file_exists($activeSitePath . 'css/')) mkdir($activeSitePath . 'css/', 0777, true);
    if (!file_exists($activeSitePath . 'js/')) mkdir($activeSitePath . 'js/', 0777, true);
}

// Páginas del sitio activo
$pages = [];
$activePage = $requestedPage;
if (!$useLegacy && $activeSiteId > 0) {
    $stmt = $db->prepare('SELECT id, titulo, ruta, orden, visible FROM paginas WHERE sitio_id = ? ORDER BY orden ASC, id ASC');
    $stmt->execute([$activeSiteId]);
    $pages = $stmt->fetchAll();

    if (empty($pages)) {
        // crear defaults si el sitio existe pero no tiene páginas
        $defaults = [
            ['Inicio', 'index', 0],
            ['Galería', 'galeria', 1],
            ['Misión y Visión', 'mision-vision', 2],
        ];
        foreach ($defaults as $d) {
            [$titulo, $ruta, $orden] = $d;
            $ins = $db->prepare('INSERT INTO paginas (sitio_id, titulo, ruta, contenido_html, contenido_css, contenido_js, orden, visible) VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
            $ins->execute([$activeSiteId, $titulo, $ruta, '', '', '', $orden]);
        }
        $stmt = $db->prepare('SELECT id, titulo, ruta, orden, visible FROM paginas WHERE sitio_id = ? ORDER BY orden ASC, id ASC');
        $stmt->execute([$activeSiteId]);
        $pages = $stmt->fetchAll();
    }

    $knownRoutes = array_map(fn($p) => (string)$p['ruta'], $pages);
    if (!in_array($activePage, $knownRoutes, true)) {
        $activePage = $knownRoutes[0] ?? 'index';
    }
}

$pageFile = ($activePage === 'index') ? 'index.html' : ($activePage . '.html');

// Leer archivos existentes (o DB como fallback)
$html = file_exists($activeSitePath . $pageFile) ? file_get_contents($activeSitePath . $pageFile) : '';
if ($html === '' && !$useLegacy && $activeSiteId > 0) {
    $stmt = $db->prepare('SELECT contenido_html FROM paginas WHERE sitio_id = ? AND ruta = ? LIMIT 1');
    $stmt->execute([$activeSiteId, $activePage]);
    $row = $stmt->fetch();
    if ($row && isset($row['contenido_html'])) {
        $html = (string)$row['contenido_html'];
    }
}

$css = file_exists($activeSitePath . 'css/style.css') ? file_get_contents($activeSitePath . 'css/style.css') : '';
$js = file_exists($activeSitePath . 'js/script.js') ? file_get_contents($activeSitePath . 'js/script.js') : '';

$previewUrl = 's/' . urlencode($username);
if (!$useLegacy && $activeSlug !== '') {
    $previewUrl .= '/' . urlencode($activeSlug);
    if ($activePage !== '' && $activePage !== 'index') {
        $previewUrl .= '/' . urlencode($activePage);
    }
}

// Usar URL absoluta para evitar problemas de rutas relativas bajo /dashboard/*
$previewUrl = '/' . ltrim($previewUrl, '/');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editor Online - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/dracula.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html,
        body {
            height: 100%;
            overflow: hidden;
        }
        
        :root {
            --bg: #0f1115;
            --panel: #171a21;
            --panel-2: #1d212b;
            --border: rgba(255, 255, 255, 0.08);
            --text: rgba(255, 255, 255, 0.92);
            --muted: rgba(255, 255, 255, 0.65);
            --muted2: rgba(255, 255, 255, 0.45);
            --primary: #3b82f6;
            --primary-2: #2563eb;
            --success: #22c55e;
            --danger: #ef4444;
            --warning: #f59e0b;
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
            --radius: 14px;
            color-scheme: dark;
        }

        body {
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        .app {
            height: 100vh;
            height: 100dvh;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 20;
            background: linear-gradient(180deg, rgba(23, 26, 33, 0.98), rgba(23, 26, 33, 0.92));
            border-bottom: 1px solid var(--border);
            padding: 12px 14px;
            backdrop-filter: blur(10px);
        }

        .topbar-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
        }

        .brand {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .brand-left {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .title {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .title h1 {
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 0.2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .title p {
            font-size: 12px;
            color: var(--muted);
            margin-top: 2px;
        }

        .quick-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .content {
            flex: 1;
            min-height: 0;
            display: grid;
            grid-template-columns: 1fr;
        }

        .workspace {
            min-height: 0;
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
            padding: 12px;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            min-height: 0;
            display: flex;
            flex-direction: column;
        }

        .panel-header {
            display: flex;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
            padding: 10px 12px;
            background: var(--panel-2);
            border-bottom: 1px solid var(--border);
        }

        .panel-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--text);
        }

        .panel-subtitle {
            font-size: 12px;
            color: var(--muted);
            margin-top: 2px;
        }

        .panel-title-wrap {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .row {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .hint {
            font-size: 12px;
            color: var(--muted);
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid var(--border);
            color: var(--muted);
            font-size: 12px;
            background: rgba(0, 0, 0, 0.2);
        }

        .pill.success {
            color: rgba(34, 197, 94, 0.95);
            border-color: rgba(34, 197, 94, 0.25);
            background: rgba(34, 197, 94, 0.07);
        }

        .pill.warning {
            color: rgba(245, 158, 11, 0.95);
            border-color: rgba(245, 158, 11, 0.25);
            background: rgba(245, 158, 11, 0.07);
        }

        .pill.danger {
            color: rgba(239, 68, 68, 0.95);
            border-color: rgba(239, 68, 68, 0.25);
            background: rgba(239, 68, 68, 0.07);
        }

        .btn {
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: rgba(255, 255, 255, 0.06);
            color: var(--text);
            cursor: pointer;
            font-family: inherit;
            font-weight: 650;
            font-size: 13px;
            transition: transform 0.02s ease, background 0.2s ease, border-color 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            user-select: none;
        }

        .btn:active {
            transform: translateY(1px);
        }

        .btn:hover {
            background: rgba(255, 255, 255, 0.10);
            border-color: rgba(255, 255, 255, 0.16);
        }

        .btn.primary {
            background: rgba(59, 130, 246, 0.18);
            border-color: rgba(59, 130, 246, 0.40);
        }

        .btn.primary:hover {
            background: rgba(59, 130, 246, 0.25);
        }

        .btn.success {
            background: rgba(34, 197, 94, 0.16);
            border-color: rgba(34, 197, 94, 0.40);
        }

        .btn.success:hover {
            background: rgba(34, 197, 94, 0.23);
        }

        .btn.ghost {
            background: transparent;
        }

        .btn.small {
            padding: 8px 10px;
            border-radius: 10px;
            font-weight: 600;
        }

        .btn[disabled] {
            opacity: 0.55;
            cursor: not-allowed;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 220px;
        }

        .field label {
            font-size: 12px;
            color: var(--muted);
        }

        .select {
            appearance: none;
            -webkit-appearance: none;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 10px 36px 10px 12px;
            color: var(--text);
            font-size: 13px;
            outline: none;
            color-scheme: dark;
        }

        /* Opciones del select (en algunos navegadores no hereda bien el color) */
        .select option {
            background: #0f1115;
            color: rgba(255, 255, 255, 0.92);
        }

        .select:focus {
            border-color: rgba(59, 130, 246, 0.55);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
        }

        .select-wrap {
            position: relative;
        }

        .select-wrap:after {
            content: '▾';
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted2);
            pointer-events: none;
            font-size: 14px;
        }

        .tabs {
            display: flex;
            gap: 8px;
            padding: 8px 10px;
            background: rgba(0, 0, 0, 0.18);
            border-bottom: 1px solid var(--border);
            overflow-x: auto;
        }

        .tab {
            padding: 9px 12px;
            background: transparent;
            color: var(--muted);
            border: 1px solid transparent;
            border-radius: 999px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: inherit;
            font-weight: 650;
            font-size: 13px;
            white-space: nowrap;
        }

        .tab.active {
            color: var(--text);
            background: rgba(59, 130, 246, 0.16);
            border-color: rgba(59, 130, 246, 0.40);
        }

        .tab:hover:not(.active) {
            color: rgba(255, 255, 255, 0.85);
            background: rgba(255, 255, 255, 0.06);
        }
        
        .code-editor {
            flex: 1;
            overflow: hidden;
            min-height: 0;
            position: relative;
        }
        
        .CodeMirror {
            height: 100% !important;
            font-size: 14px;
            line-height: 1.6;
        }

        .code-editor .CodeMirror {
            position: absolute;
            inset: 0;
        }
        
        .preview-frame {
            flex: 1;
            border: none;
            background: white;
            min-height: 0;
        }

        .mobile-switch {
            display: flex;
            gap: 8px;
            padding: 8px;
            background: rgba(0, 0, 0, 0.20);
            border: 1px solid var(--border);
            border-radius: 999px;
            align-items: center;
        }

        .seg {
            border: 1px solid transparent;
            background: transparent;
            color: var(--muted);
            padding: 8px 12px;
            border-radius: 999px;
            cursor: pointer;
            font-weight: 700;
            font-size: 13px;
        }

        .seg.active {
            color: var(--text);
            background: rgba(59, 130, 246, 0.16);
            border-color: rgba(59, 130, 246, 0.40);
        }

        .help {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
            background: rgba(0, 0, 0, 0.12);
            color: var(--muted);
            font-size: 13px;
        }

        .help strong {
            color: rgba(255, 255, 255, 0.90);
        }

        .help ul {
            margin: 8px 0 0 18px;
        }

        .help li {
            margin: 4px 0;
        }

        /* Desktop: 2 columnas (Editor + Vista previa) */
        @media (min-width: 1000px) {
            .topbar-grid {
                grid-template-columns: 1fr;
            }
            .workspace {
                grid-template-columns: 1fr 1fr;
            }
        }

        /* Móvil: modo conmutador (Editar / Ver) */
        @media (max-width: 999px) {
            [data-view="edit"] .panel.preview { display: none; }
            [data-view="preview"] .panel.editor { display: none; }
        }

        /* Modal */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.55);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 18px;
            z-index: 50;
        }

        .modal {
            width: min(520px, 100%);
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .modal-header {
            padding: 12px 14px;
            background: var(--panel-2);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .modal-header h2 {
            font-size: 14px;
            font-weight: 800;
        }

        .modal-body {
            padding: 14px;
            display: grid;
            gap: 12px;
        }

        .input {
            width: 100%;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: rgba(255, 255, 255, 0.06);
            color: var(--text);
            font-size: 13px;
            outline: none;
        }

        .input:focus {
            border-color: rgba(59, 130, 246, 0.55);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
        }

        .modal-actions {
            padding: 12px 14px;
            border-top: 1px solid var(--border);
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            flex-wrap: wrap;
            background: rgba(0, 0, 0, 0.12);
        }
    </style>
</head>
<body>
    <div class="app" id="app" data-view="edit">
        <header class="topbar">
            <div class="topbar-grid">
                <div class="brand">
                    <div class="brand-left">
                        <a class="btn small ghost" href="dashboard.php" title="Volver al inicio">← Inicio</a>
                        <div class="title">
                            <h1>Editor de tu sitio</h1>
                            <p>1) Elige sitio y página · 2) Edita · 3) Revisa (Vista previa) · 4) Guarda</p>
                        </div>
                    </div>
                    <div class="quick-actions">
                        <a class="btn small" href="my-website.php" title="Ver tus sitios">Mis sitios</a>
                        <a class="btn small" href="my-website.php?create=1" title="Crear un sitio nuevo">Nuevo sitio</a>
                        <span class="pill" id="status">Listo</span>
                    </div>
                </div>

                <div class="row" style="justify-content: space-between;">
                    <div class="row">
                        <?php if (!$useLegacy && !empty($allSites)): ?>
                            <div class="field">
                                <label for="site-select">Sitio (elige cuál estás editando)</label>
                                <div class="select-wrap">
                                    <select class="select" id="site-select">
                                        <?php foreach ($allSites as $s): ?>
                                            <?php $sid = (int)($s['id'] ?? 0); ?>
                                            <option value="<?php echo $sid; ?>" <?php echo ($sid === $activeSiteId) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars((string)($s['nombre_sitio'] ?? 'Sitio')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!$useLegacy && !empty($pages)): ?>
                            <div class="field">
                                <label for="page-select">Página (lo que verá la gente)</label>
                                <div class="select-wrap">
                                    <select class="select" id="page-select">
                                        <?php foreach ($pages as $p): ?>
                                            <?php $route = (string)($p['ruta'] ?? 'index'); ?>
                                            <option value="<?php echo htmlspecialchars($route); ?>" <?php echo ($route === $activePage) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars((string)($p['titulo'] ?? $route)); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <button class="btn" id="btn-new-page" type="button" title="Crear una página nueva">+ Nueva página</button>
                        <?php endif; ?>
                    </div>

                    <div class="row">
                        <div class="mobile-switch" aria-label="Cambiar vista">
                            <button class="seg active" id="seg-edit" type="button">Editar</button>
                            <button class="seg" id="seg-preview" type="button">Ver</button>
                        </div>
                        <button class="btn success" id="btn-save" type="button">Guardar</button>
                        <button class="btn primary" id="btn-refresh" type="button">Actualizar vista previa</button>
                        <button class="btn" id="btn-open" type="button">Abrir sitio</button>
                    </div>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="workspace">
                <section class="panel editor" aria-label="Panel de edición">
                    <div class="panel-header">
                        <div class="panel-title-wrap">
                            <div class="panel-title">Escritura (código)</div>
                            <div class="panel-subtitle">HTML = texto/estructura · CSS = colores/estilo · JS = interacción</div>
                        </div>
                        <div class="row">
                            <span class="hint">Tip: guarda seguido para no perder cambios.</span>
                        </div>
                    </div>

                    <div class="help" id="help">
                        <strong>¿Qué hago aquí?</strong>
                        <ul>
                            <li><strong>HTML</strong>: escribe el contenido (títulos, párrafos, imágenes).</li>
                            <li><strong>CSS</strong>: cambia colores, tamaño, orden (diseño).</li>
                            <li><strong>JS</strong>: cosas que se mueven o responden a clics (opcional).</li>
                        </ul>
                    </div>

                    <div class="tabs" role="tablist" aria-label="Archivos">
                        <button class="tab active" data-target="html" type="button" role="tab" aria-selected="true"><?php echo htmlspecialchars($pageFile); ?></button>
                        <button class="tab" data-target="css" type="button" role="tab" aria-selected="false">style.css</button>
                        <button class="tab" data-target="js" type="button" role="tab" aria-selected="false">script.js</button>
                    </div>

                    <div class="code-editor">
                        <textarea id="html-editor" style="display: none;"><?php echo htmlspecialchars($html); ?></textarea>
                        <textarea id="css-editor" style="display: none;"><?php echo htmlspecialchars($css); ?></textarea>
                        <textarea id="js-editor" style="display: none;"><?php echo htmlspecialchars($js); ?></textarea>
                    </div>
                </section>

                <section class="panel preview" aria-label="Panel de vista previa">
                    <div class="panel-header">
                        <div class="panel-title-wrap">
                            <div class="panel-title">Vista previa</div>
                            <div class="panel-subtitle">Así se verá en el teléfono / computadora</div>
                        </div>
                        <div class="row">
                            <span class="hint">Si no cambia, toca “Actualizar vista previa”.</span>
                        </div>
                    </div>
                    <iframe id="preview-frame" class="preview-frame" title="Vista previa"></iframe>
                </section>
            </div>
        </main>
    </div>

    <div class="modal-backdrop" id="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <div class="modal">
            <div class="modal-header">
                <h2 id="modal-title">Crear nueva página</h2>
                <button class="btn small ghost" type="button" id="modal-close">Cerrar</button>
            </div>
            <div class="modal-body">
                <div class="field" style="min-width: unset;">
                    <label for="new-title">Nombre (ej: Contacto)</label>
                    <input class="input" id="new-title" type="text" placeholder="Ej: Contacto" autocomplete="off" />
                </div>
                <div class="field" style="min-width: unset;">
                    <label for="new-route">Ruta corta (ej: contacto)</label>
                    <input class="input" id="new-route" type="text" placeholder="Ej: contacto" autocomplete="off" />
                    <div class="hint">La ruta es lo que irá en el link. Solo letras, números y guiones.</div>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn" type="button" id="modal-cancel">Cancelar</button>
                <button class="btn primary" type="button" id="modal-create">Crear página</button>
            </div>
        </div>
    </div>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/xml/xml.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/css/css.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/javascript/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/htmlmixed/htmlmixed.min.js"></script>
    
    <script>
        const API_SAVE = <?php echo json_encode('/save-code'); ?>;
        const API_PAGES = <?php echo json_encode('/pages-api'); ?>;

        const APP = document.getElementById('app');
        const statusEl = document.getElementById('status');

        function setStatus(kind, message) {
            statusEl.className = 'pill' + (kind ? (' ' + kind) : '');
            statusEl.textContent = message;
        }

        // Inicializar editores CodeMirror
        const htmlEditor = CodeMirror.fromTextArea(document.getElementById('html-editor'), {
            mode: 'htmlmixed',
            theme: 'dracula',
            lineNumbers: true,
            autoCloseTags: true,
            matchTags: true,
            lineWrapping: true
        });
        
        const cssEditor = CodeMirror.fromTextArea(document.getElementById('css-editor'), {
            mode: 'css',
            theme: 'dracula',
            lineNumbers: true,
            lineWrapping: true
        });
        
        const jsEditor = CodeMirror.fromTextArea(document.getElementById('js-editor'), {
            mode: 'javascript',
            theme: 'dracula',
            lineNumbers: true,
            lineWrapping: true
        });
        
        // Mostrar solo 1 editor a la vez (evita que se “apilen” y muevan la pantalla)
        const htmlWrap = htmlEditor.getWrapperElement();
        const cssWrap = cssEditor.getWrapperElement();
        const jsWrap = jsEditor.getWrapperElement();
        cssWrap.style.display = 'none';
        jsWrap.style.display = 'none';
        let currentEditor = htmlEditor;

        function showEditor(which) {
            htmlWrap.style.display = 'none';
            cssWrap.style.display = 'none';
            jsWrap.style.display = 'none';

            if (which === 'css') {
                cssWrap.style.display = 'block';
                currentEditor = cssEditor;
            } else if (which === 'js') {
                jsWrap.style.display = 'block';
                currentEditor = jsEditor;
            } else {
                htmlWrap.style.display = 'block';
                currentEditor = htmlEditor;
            }

            requestAnimationFrame(() => {
                currentEditor.refresh();
                currentEditor.focus();
            });
        }
        
        // Manejo de pestañas
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                // Remover clase active de todas las pestañas
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                // Agregar clase active a la pestaña clickeada
                this.classList.add('active');
                
                // Mostrar el editor correspondiente
                const target = this.dataset.target;
                showEditor(target);
            });
        });

        window.addEventListener('resize', () => {
            requestAnimationFrame(() => currentEditor.refresh());
        });

        // Modo móvil: Editar / Ver
        const segEdit = document.getElementById('seg-edit');
        const segPreview = document.getElementById('seg-preview');

        function setView(view) {
            APP.setAttribute('data-view', view);
            segEdit.classList.toggle('active', view === 'edit');
            segPreview.classList.toggle('active', view === 'preview');
            requestAnimationFrame(() => currentEditor.refresh());
        }

        segEdit.addEventListener('click', () => setView('edit'));
        segPreview.addEventListener('click', () => {
            setView('preview');
            // En móvil suele ser útil refrescar el frame al entrar
            updatePreview({ quiet: true });
        });
        
        // Generar vista previa
        function generatePreview() {
            const html = htmlEditor.getValue();
            const css = cssEditor.getValue();
            const js = jsEditor.getValue();
            
            const preview = `
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <style>${css}</style>
                </head>
                <body>
                    ${html}
                    <script>${js}<\/script>
                </body>
                </html>
            `;
            
            return preview;
        }
        
        // Actualizar vista previa
        function updatePreview(opts = {}) {
            const preview = generatePreview();
            const frame = document.getElementById('preview-frame');
            const blob = new Blob([preview], {type: 'text/html'});
            frame.src = URL.createObjectURL(blob);

            if (!opts.quiet) {
                setStatus(isDirty ? 'warning' : '', isDirty ? 'Vista previa actualizada (falta guardar)' : 'Vista previa actualizada');
            }
        }
        
        // Guardar código
        async function saveCode(opts = {}) {
            if (opts.onlyIfDirty && !isDirty) return;

            const formData = new FormData();
            formData.append('html', htmlEditor.getValue());
            formData.append('css', cssEditor.getValue());
            formData.append('js', jsEditor.getValue());
            formData.append('site_id', String(<?php echo (int)$activeSiteId; ?>));
            formData.append('page', <?php echo json_encode((string)$activePage); ?>);

            if (!opts.quiet) setStatus('warning', 'Guardando…');

            try {
                const response = await fetch(API_SAVE, { method: 'POST', body: formData });
                const data = await response.json().catch(() => ({}));

                if (response.ok && data && data.success) {
                    isDirty = false;
                    if (!opts.quiet) {
                        setStatus('success', 'Guardado ' + new Date().toLocaleTimeString());
                        setTimeout(() => {
                            setStatus('', 'Listo');
                        }, 1500);
                    } else {
                        setStatus('success', 'Guardado');
                        setTimeout(() => {
                            setStatus('', 'Listo');
                        }, 1200);
                    }
                } else {
                    setStatus('danger', data.error || 'No se pudo guardar');
                }
            } catch (e) {
                setStatus('danger', 'Error de conexión');
            }
        }
        
        // Abrir vista previa completa
        function openFullPreview() {
            window.open(<?php echo json_encode($previewUrl); ?>, '_blank');
        }
        
        let isDirty = false;

        // Auto-guardado cada 30 segundos (solo si hay cambios)
        setInterval(() => saveCode({ quiet: true, onlyIfDirty: true }), 30000);
        
        // Actualizar vista previa automáticamente con debounce
        let previewTimeout;
        htmlEditor.on('change', () => {
            clearTimeout(previewTimeout);
            previewTimeout = setTimeout(updatePreview, 1000);
            isDirty = true;
            setStatus('warning', 'Tienes cambios sin guardar');
        });
        
        cssEditor.on('change', () => {
            clearTimeout(previewTimeout);
            previewTimeout = setTimeout(updatePreview, 1000);
            isDirty = true;
            setStatus('warning', 'Tienes cambios sin guardar');
        });
        
        jsEditor.on('change', () => {
            clearTimeout(previewTimeout);
            previewTimeout = setTimeout(updatePreview, 1000);
            isDirty = true;
            setStatus('warning', 'Tienes cambios sin guardar');
        });

        // Botones principales
        const btnSave = document.getElementById('btn-save');
        const btnRefresh = document.getElementById('btn-refresh');
        const btnOpen = document.getElementById('btn-open');

        btnSave.addEventListener('click', () => saveCode());
        btnRefresh.addEventListener('click', () => updatePreview());
        btnOpen.addEventListener('click', () => openFullPreview());

        const siteSelect = document.getElementById('site-select');
        if (siteSelect) {
            siteSelect.addEventListener('change', (e) => {
                if (isDirty && !confirm('Tienes cambios sin guardar. ¿Cambiar de sitio de todos modos?')) {
                    siteSelect.value = String(<?php echo (int)$activeSiteId; ?>);
                    return;
                }
                const nextId = siteSelect.value;
                window.location.href = 'editor?site_id=' + encodeURIComponent(nextId);
            });
        }

        const pageSelect = document.getElementById('page-select');
        if (pageSelect) {
            pageSelect.addEventListener('change', (e) => {
                if (isDirty && !confirm('Tienes cambios sin guardar. ¿Cambiar de página de todos modos?')) {
                    pageSelect.value = <?php echo json_encode((string)$activePage); ?>;
                    return;
                }
                const nextPage = pageSelect.value;
                const qs = new URLSearchParams();
                if (<?php echo (int)$activeSiteId; ?> > 0) {
                    qs.set('site_id', String(<?php echo (int)$activeSiteId; ?>));
                }
                qs.set('page', nextPage);
                window.location.href = 'editor?' + qs.toString();
            });
        }

        const btnNewPage = document.getElementById('btn-new-page');
        const modalBackdrop = document.getElementById('modal-backdrop');
        const modalClose = document.getElementById('modal-close');
        const modalCancel = document.getElementById('modal-cancel');
        const modalCreate = document.getElementById('modal-create');
        const newTitle = document.getElementById('new-title');
        const newRoute = document.getElementById('new-route');

        function openModal() {
            modalBackdrop.style.display = 'flex';
            newTitle.value = '';
            newRoute.value = '';
            setTimeout(() => newTitle.focus(), 0);
        }

        function closeModal() {
            modalBackdrop.style.display = 'none';
        }

        function slugify(input) {
            return String(input || '')
                .trim()
                .toLowerCase()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-z0-9\s-]/g, '')
                .replace(/\s+/g, '-')
                .replace(/-+/g, '-')
                .replace(/^-|-$/g, '');
        }

        if (btnNewPage) {
            btnNewPage.addEventListener('click', () => {
                if (isDirty && !confirm('Tienes cambios sin guardar. ¿Crear página sin guardar primero?')) {
                    return;
                }
                openModal();
            });
        }

        newTitle.addEventListener('input', () => {
            if (newRoute.value.trim() === '') {
                newRoute.value = slugify(newTitle.value);
            }
        });

        modalClose.addEventListener('click', closeModal);
        modalCancel.addEventListener('click', closeModal);
        modalBackdrop.addEventListener('click', (e) => {
            if (e.target === modalBackdrop) closeModal();
        });

        modalCreate.addEventListener('click', async () => {
            const title = newTitle.value.trim();
            const route = slugify(newRoute.value);

            if (!title) {
                alert('Escribe un nombre para la página.');
                newTitle.focus();
                return;
            }

            const formData = new FormData();
            formData.append('action', 'create_page');
            formData.append('site_id', String(<?php echo (int)$activeSiteId; ?>));
            formData.append('title', title);
            formData.append('route', route);

            modalCreate.disabled = true;
            modalCreate.textContent = 'Creando…';
            try {
                const res = await fetch(API_PAGES, { method: 'POST', body: formData });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data.success) {
                    alert(data.error || 'No se pudo crear la página');
                    return;
                }
                const createdRoute = data.route || route || 'index';
                const qs = new URLSearchParams();
                qs.set('site_id', String(<?php echo (int)$activeSiteId; ?>));
                qs.set('page', createdRoute);
                window.location.href = 'editor?' + qs.toString();
            } catch (e) {
                alert('Error de conexión');
            } finally {
                modalCreate.disabled = false;
                modalCreate.textContent = 'Crear página';
            }
        });

        // Proteger contra pérdida de cambios
        window.addEventListener('beforeunload', (e) => {
            if (!isDirty) return;
            e.preventDefault();
            e.returnValue = '';
        });
        
        // Inicializar
        setStatus('', 'Listo');
        updatePreview({ quiet: true });
    </script>
</body>
</html>