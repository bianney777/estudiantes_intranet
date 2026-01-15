<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
ensureLoggedIn();

$user = $_SESSION['user'];
$db = getDB();

$username = (string)($user['username'] ?? $user['codigo'] ?? '');
$username = preg_replace('/[^a-zA-Z0-9_-]/', '', $username);

// Listar sitios del estudiante
$stmt = $db->prepare('SELECT * FROM sitios_web WHERE estudiante_id = ? ORDER BY ultima_actualizacion DESC, id DESC');
$stmt->execute([(int)$user['id']]);
$sitios = $stmt->fetchAll();

$createMode = isset($_GET['create']) && (string)$_GET['create'] === '1';
$error = null;
$success = null;

// Mapear plantillas del select a carpetas reales
$TEMPLATE_DIR_MAP = [
  'portfolio' => 'portfolio',
  'blog' => 'blog',
  'negocio' => 'business',
];

function uniqueUrlSlug(PDO $db, string $baseSlug): string {
  $slug = $baseSlug;
  $i = 2;
  while (true) {
    $stmt = $db->prepare('SELECT id FROM sitios_web WHERE url_personalizada = ? LIMIT 1');
    $stmt->execute([$slug]);
    $exists = $stmt->fetch();
    if (!$exists) {
      return $slug;
    }
    $slug = $baseSlug . '-' . $i;
    $i++;
  }
}

function ensureDefaultPages(PDO $db, int $siteId): void {
  $defaults = [
    ['Inicio', 'index', 0],
    ['Galería', 'galeria', 1],
    ['Misión y Visión', 'mision-vision', 2],
  ];
  foreach ($defaults as $d) {
    [$titulo, $ruta, $orden] = $d;
    $check = $db->prepare('SELECT id FROM paginas WHERE sitio_id = ? AND ruta = ? LIMIT 1');
    $check->execute([$siteId, $ruta]);
    if ($check->fetch()) {
      continue;
    }
    $ins = $db->prepare('INSERT INTO paginas (sitio_id, titulo, ruta, contenido_html, contenido_css, contenido_js, orden, visible) VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
    $ins->execute([$siteId, $titulo, $ruta, '', '', '', $orden]);
  }
}

function pageTemplateHtml(string $title, string $route, array $navRoutes): string {
  $nav = '';
  foreach ($navRoutes as $r => $label) {
    $file = ($r === 'index') ? 'index.html' : ($r . '.html');
    $nav .= '<a href="' . htmlspecialchars($file) . '" style="color:inherit;text-decoration:none;padding:10px 12px;border-radius:10px;display:inline-block;">' . htmlspecialchars($label) . '</a>';
  }
  $h1 = $route === 'index' ? 'Bienvenido' : $title;
  return "<!doctype html>
<html lang=\"es\">
<head>
  <meta charset=\"utf-8\">
  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">
  <title>" . htmlspecialchars($title) . "</title>
  <link rel=\"stylesheet\" href=\"css/style.css\">
</head>
<body>
  <header style=\"padding:18px 16px;border-bottom:1px solid rgba(0,0,0,.08);\">
    <div style=\"max-width:1000px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap\">
      <div style=\"font-weight:900\">Mi Sitio</div>
      <nav style=\"display:flex;gap:6px;flex-wrap:wrap\">{$nav}</nav>
    </div>
  </header>
  <main style=\"max-width:1000px;margin:0 auto;padding:22px 16px\">
    <h1 style=\"margin:0 0 10px\">" . htmlspecialchars($h1) . "</h1>
    <p style=\"margin:0;color:#334155\">Edita esta página desde el editor.</p>
  </main>
  <script src=\"js/script.js\"></script>
</body>
</html>";
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'create_site') {
    $nombreSitio = trim((string)($_POST['nombre_sitio'] ?? ''));
    $descripcion = trim((string)($_POST['descripcion'] ?? ''));
    $plantilla = (string)($_POST['plantilla'] ?? 'portfolio');
    $urlBase = trim((string)($_POST['url_personalizada'] ?? ''));

    if ($nombreSitio === '') {
      $error = 'Escribe el nombre del sitio.';
    } else {
      try {
        if ($username === '') {
          throw new RuntimeException('Usuario inválido');
        }

        $slug = $urlBase !== '' ? sanitizeSlug($urlBase) : sanitizeSlug($nombreSitio);
        if ($slug === '') {
          $slug = 'sitio-' . (int)$user['id'];
        }
        $slug = uniqueUrlSlug($db, $slug);

        $db->beginTransaction();
        $ins = $db->prepare("INSERT INTO sitios_web (estudiante_id, nombre_sitio, descripcion, url_personalizada, plantilla, estado, ultima_actualizacion) VALUES (?, ?, ?, ?, ?, 'borrador', datetime('now'))");
        $ins->execute([(int)$user['id'], $nombreSitio, ($descripcion !== '' ? $descripcion : null), $slug, $plantilla]);
        $siteId = (int)$db->lastInsertId();
        ensureDefaultPages($db, $siteId);
        $db->commit();

        // Crear carpeta del sitio y archivos base
        $sitePath = studentSitePath($username, $slug);
        ensureStudentSiteFolders($sitePath);
        if (!file_exists($sitePath . 'css/style.css')) file_put_contents($sitePath . 'css/style.css', "/* styles */\n", LOCK_EX);
        if (!file_exists($sitePath . 'js/script.js')) file_put_contents($sitePath . 'js/script.js', "// scripts\n", LOCK_EX);

        // Crear páginas por defecto en filesystem si no existen
        $navRoutes = [
          'index' => 'Inicio',
          'galeria' => 'Galería',
          'mision-vision' => 'Misión y Visión',
        ];
        if (!file_exists($sitePath . 'index.html')) {
          file_put_contents($sitePath . 'index.html', pageTemplateHtml($nombreSitio, 'index', $navRoutes), LOCK_EX);
        }
        if (!file_exists($sitePath . 'galeria.html')) {
          file_put_contents($sitePath . 'galeria.html', pageTemplateHtml('Galería', 'galeria', $navRoutes), LOCK_EX);
        }
        if (!file_exists($sitePath . 'mision-vision.html')) {
          file_put_contents($sitePath . 'mision-vision.html', pageTemplateHtml('Misión y Visión', 'mision-vision', $navRoutes), LOCK_EX);
        }

        $success = 'Sitio creado. Ya puedes editarlo.';
        $createMode = false;
      } catch (Throwable $e) {
        if ($db->inTransaction()) {
          $db->rollBack();
        }
        $error = 'Error al guardar: ' . $e->getMessage();
      }
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mi Sitio - <?php echo SITE_NAME; ?></title>
  <style>
    :root{
      --bg:#0b1020;
      --card:#111a33;
      --card2:#0f1730;
      --text:#e8ecff;
      --muted:#b5bddc;
      --primary:#667eea;
      --primary2:#764ba2;
      --ok:#28a745;
      --warn:#ffb020;
      --danger:#ff5a6a;
      --border:rgba(255,255,255,.10);
    }
    *{box-sizing:border-box}
    body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:radial-gradient(1200px 600px at 20% -10%, rgba(102,126,234,.35), transparent),radial-gradient(1200px 600px at 80% 10%, rgba(118,75,162,.35), transparent),var(--bg);color:var(--text)}
    a{color:inherit}
    .wrap{max-width:1100px;margin:0 auto;padding:28px 18px 48px}
    .top{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:18px}
    .title{display:flex;flex-direction:column;gap:4px}
    .title h1{font-size:22px;margin:0}
    .title p{margin:0;color:var(--muted);font-size:13px}
    .actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}
    .btn{border:1px solid var(--border);background:rgba(255,255,255,.06);color:var(--text);padding:10px 14px;border-radius:12px;text-decoration:none;font-weight:600;font-size:14px;display:inline-flex;align-items:center;gap:10px;transition:transform .08s ease, background .2s ease}
    .btn:hover{background:rgba(255,255,255,.10)}
    .btn:active{transform:translateY(1px)}
    .btn.primary{border:none;background:linear-gradient(135deg,var(--primary),var(--primary2))}
    .btn.ok{border:none;background:linear-gradient(135deg,#2ecc71,#1aa34a)}
    .grid{display:grid;grid-template-columns:1.2fr .8fr;gap:16px}
    .card{background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03));border:1px solid var(--border);border-radius:18px;padding:18px}
    .card h2{margin:0 0 10px;font-size:16px}
    .muted{color:var(--muted);font-size:13px;line-height:1.5}
    .banner{margin:12px 0 0;padding:12px 14px;border-radius:14px;border:1px solid var(--border)}
    .banner.ok{background:rgba(40,167,69,.12);border-color:rgba(40,167,69,.35)}
    .banner.err{background:rgba(255,90,106,.10);border-color:rgba(255,90,106,.35)}
    .form{display:grid;gap:12px;margin-top:12px}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    label{display:grid;gap:6px;font-size:13px;color:var(--muted)}
    input,textarea,select{width:100%;padding:12px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(10,16,33,.65);color:var(--text);outline:none}
    textarea{min-height:92px;resize:vertical}
    .hint{font-size:12px;color:rgba(181,189,220,.85)}
    .pill{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;border:1px solid var(--border);background:rgba(255,255,255,.05);font-size:12px;color:var(--muted)}
    .stats{display:grid;gap:10px}
    .stat{display:flex;justify-content:space-between;gap:10px;padding:12px 14px;border-radius:14px;background:rgba(0,0,0,.22);border:1px solid var(--border)}
    .stat strong{font-size:13px}
    .stat span{font-size:12px;color:var(--muted)}
    .k{color:var(--muted)}
    .preview-box{border:1px solid var(--border);border-radius:16px;overflow:hidden;background:rgba(0,0,0,.25)}
    .preview-box iframe{width:100%;height:min(520px,70vh);border:0;background:#fff;display:block}
    .small{font-size:12px;color:rgba(181,189,220,.85)}
    @media (max-width: 980px){
      .grid{grid-template-columns:1fr}
      .row{grid-template-columns:1fr}
      .actions{justify-content:flex-start}
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <div class="title">
        <h1>Mi Sitio Web</h1>
        <p>Hola, <?php echo htmlspecialchars($user['nombre'] ?? ''); ?>. Crea, edita y publica tu sitio.</p>
      </div>
      <div class="actions">
        <a class="btn" href="dashboard.php">← Volver</a>
        <a class="btn" href="editor.php">✏️ Editor</a>
        <a class="btn" href="s/<?php echo urlencode($username); ?>" target="_blank">👁️ Preview</a>
        <a class="btn primary" href="my-website.php?create=1">➕ Crear / Configurar</a>
      </div>
    </div>

    <?php if ($success): ?>
      <div class="banner ok"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="banner err"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="grid">
      <div class="card">
        <?php if ($createMode): ?>
          <h2>Crear tu sitio</h2>
          <div class="muted">Elige una plantilla y un nombre. Podrás editar todo desde el editor.</div>

          <form class="form" method="post">
            <input type="hidden" name="action" value="create_site">
            <div class="row">
              <label>
                Nombre del sitio
                <input name="nombre_sitio" placeholder="Mi portafolio" value="<?php echo htmlspecialchars($_POST['nombre_sitio'] ?? ($sitio['nombre_sitio'] ?? '')); ?>">
                <span class="hint">Se mostrará como título principal.</span>
              </label>
              <label>
                Plantilla
                <select name="plantilla">
                  <?php foreach (($PLANTILLAS ?? []) as $k => $label): ?>
                    <option value="<?php echo htmlspecialchars($k); ?>" <?php echo (($_POST['plantilla'] ?? ($sitio['plantilla'] ?? 'portfolio')) === $k) ? 'selected' : ''; ?>>
                      <?php echo htmlspecialchars($label); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <span class="hint">Puedes cambiarla luego.</span>
              </label>
            </div>

            <label>
              Descripción (opcional)
              <textarea name="descripcion" placeholder="Una breve descripción..."><?php echo htmlspecialchars($_POST['descripcion'] ?? ($sitio['descripcion'] ?? '')); ?></textarea>
            </label>

            <label>
              URL personalizada (opcional)
              <input name="url_personalizada" placeholder="mi-sitio" value="<?php echo htmlspecialchars($_POST['url_personalizada'] ?? ($sitio['url_personalizada'] ?? '')); ?>">
              <span class="hint">Si está ocupada, se ajusta automáticamente (ej: mi-sitio-2).</span>
            </label>

            <div class="actions">
              <button class="btn ok" type="submit">🚀 Crear / Guardar</button>
              <a class="btn" href="my-website.php">Cancelar</a>
            </div>
          </form>
        <?php else: ?>
          <h2>Tus sitios</h2>
          <div class="muted">Puedes crear varios sitios y cada sitio puede tener varias páginas.</div>

          <?php if (empty($sitios)): ?>
            <div style="height:10px"></div>
            <div class="muted">Aún no tienes sitios. Crea el primero.</div>
            <div class="actions" style="margin-top:14px;">
              <a class="btn primary" href="my-website.php?create=1">➕ Crear mi primer sitio</a>
            </div>
          <?php else: ?>
            <div class="stats" style="margin-top:12px;">
              <?php foreach ($sitios as $s): ?>
                <?php
                  $slug = (string)($s['url_personalizada'] ?? '');
                  $sid = (int)$s['id'];
                  $state = (string)($s['estado'] ?? 'borrador');
                ?>
                <div class="stat" style="align-items:center;">
                  <div>
                    <div style="font-weight:800;"><?php echo htmlspecialchars((string)$s['nombre_sitio']); ?></div>
                    <div class="small">slug: <?php echo htmlspecialchars($slug); ?> • estado: <?php echo htmlspecialchars($state); ?></div>
                  </div>
                  <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
                    <a class="btn" href="s/<?php echo urlencode($username); ?>/<?php echo urlencode($slug); ?>" target="_blank">👁️ Preview</a>
                    <a class="btn" href="student-evaluation.php?site_id=<?php echo (int)$sid; ?>">⭐ Evaluación</a>
                    <a class="btn ok" href="editor.php?site_id=<?php echo $sid; ?>">✏️ Editar</a>
                    <a class="btn" href="s/<?php echo urlencode($username); ?>/<?php echo urlencode($slug); ?>" target="_blank">🌐 Abrir sitio</a>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <div class="card">
        <h2>Atajos</h2>
        <div class="muted">Todo lo importante en un clic.</div>
        <div class="stats" style="margin-top:12px;">
          <a class="stat" style="text-decoration:none" href="editor.php"><strong>✏️ Editor</strong><span>HTML/CSS/JS</span></a>
          <a class="stat" style="text-decoration:none" href="preview.php?user=<?php echo urlencode($user['username']); ?>" target="_blank"><strong>👁️ Preview</strong><span>Abrir en pestaña</span></a>
          <a class="stat" style="text-decoration:none" href="gallery.php"><strong>🖼️ Galería</strong><span>Explorar proyectos</span></a>
          <a class="stat" style="text-decoration:none" href="profile.php"><strong>👤 Perfil</strong><span>Tu información</span></a>
        </div>
      </div>
    </div>
  </div>
</body>
</html>