<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
ensureLoggedIn();

$db = getDB();
$user = $_SESSION['user'];
$studentId = (int)($user['id'] ?? 0);
if ($studentId <= 0) {
  header('Location: ' . appBaseUrl() . '/logout.php');
    exit;
}

$siteId = (int)($_GET['site_id'] ?? 0);
if ($siteId <= 0) {
    http_response_code(400);
    die('site_id inválido');
}

// Validar que el sitio pertenece al alumno
$stmt = $db->prepare('SELECT id, estudiante_id, nombre_sitio, url_personalizada, ultima_actualizacion FROM sitios_web WHERE id = ? AND estudiante_id = ? LIMIT 1');
$stmt->execute([$siteId, $studentId]);
$site = $stmt->fetch();
if (!$site) {
    http_response_code(404);
    die('Sitio no encontrado');
}

$username = (string)($user['username'] ?? $user['codigo'] ?? '');
$username = preg_replace('/[^a-zA-Z0-9_-]/', '', $username);
$slug = (string)($site['url_personalizada'] ?? '');
$previewUrl = $slug !== '' ? ('s/' . rawurlencode($username) . '/' . rawurlencode($slug)) : ('s/' . rawurlencode($username));

// Calificaciones (por profesor)
$stmt = $db->prepare(
    'SELECT c.nota, c.comentario, c.fecha_calificacion, c.profesor_id, '
    . 'p.codigo AS prof_codigo, p.nombre AS prof_nombre, p.apellido AS prof_apellido, p.email AS prof_email '
    . 'FROM calificaciones c '
    . 'LEFT JOIN profesores p ON p.id = c.profesor_id '
    . 'WHERE c.sitio_id = ? '
    . 'ORDER BY c.fecha_calificacion DESC, c.id DESC'
);
$stmt->execute([$siteId]);
$grades = $stmt->fetchAll();

$stmt = $db->prepare('SELECT AVG(nota) AS avg_nota, COUNT(1) AS total FROM calificaciones WHERE sitio_id = ?');
$stmt->execute([$siteId]);
$gradeStats = $stmt->fetch() ?: ['avg_nota' => null, 'total' => 0];

// Comentarios del docente (si existe columna profesor_id)
$teacherComments = [];
try {
    if (function_exists('columnExists') && tableExists($db, 'comentarios') && columnExists($db, 'comentarios', 'profesor_id')) {
        $stmt = $db->prepare(
            'SELECT c.contenido, c.fecha, c.profesor_id, '
            . 'p.codigo AS prof_codigo, p.nombre AS prof_nombre, p.apellido AS prof_apellido '
            . 'FROM comentarios c '
            . 'LEFT JOIN profesores p ON p.id = c.profesor_id '
            . 'WHERE c.sitio_id = ? AND c.profesor_id IS NOT NULL '
            . 'ORDER BY c.fecha DESC, c.id DESC'
        );
        $stmt->execute([$siteId]);
        $teacherComments = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    $teacherComments = [];
}

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$title = (string)($site['nombre_sitio'] ?? 'Mi sitio');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Evaluación - <?php echo h($title); ?></title>
  <style>
    :root{--bg:#0b1020;--border:rgba(255,255,255,.10);--text:#e8ecff;--muted:#b5bddc;--primary:#667eea;--primary2:#764ba2;--ok:#2ecc71;--warn:#ffb020}
    *{box-sizing:border-box}
    body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:radial-gradient(1200px 600px at 20% -10%, rgba(102,126,234,.35), transparent),radial-gradient(1200px 600px at 80% 10%, rgba(118,75,162,.35), transparent),var(--bg);color:var(--text)}
    a{color:inherit}
    .wrap{max-width:1200px;margin:0 auto;padding:24px 18px 48px}
    .top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px}
    .title h1{margin:0;font-size:22px}
    .title p{margin:6px 0 0;color:var(--muted);font-size:13px}
    .actions{display:flex;gap:10px;flex-wrap:wrap}
    .btn{border:1px solid var(--border);background:rgba(255,255,255,.06);color:var(--text);padding:10px 14px;border-radius:12px;text-decoration:none;font-weight:700;font-size:14px;display:inline-flex;align-items:center;gap:10px}
    .btn:hover{background:rgba(255,255,255,.10)}
    .btn.primary{border:none;background:linear-gradient(135deg,var(--primary),var(--primary2))}

    .grid{display:grid;grid-template-columns:1.35fr .65fr;gap:14px}
    @media(max-width:980px){.grid{grid-template-columns:1fr}}

    .card{background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03));border:1px solid var(--border);border-radius:18px;overflow:hidden}
    .pad{padding:16px}
    .kpis{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
    .kpi{background:rgba(0,0,0,.22);border:1px solid var(--border);border-radius:16px;padding:12px 14px}
    .kpi .n{font-weight:900;font-size:18px}
    .kpi .l{color:var(--muted);font-weight:700;font-size:13px}

    .list{border-top:1px solid rgba(255,255,255,.10)}
    .item{padding:12px 16px;border-top:1px solid rgba(255,255,255,.10)}
    .meta{color:var(--muted);font-size:12px;font-weight:700}
    .who{color:var(--text);font-weight:900}

    .iframe-wrap{background:#fff}
    iframe{width:100%;height:70vh;min-height:520px;border:0;display:block}

    .empty{color:var(--muted);padding:14px 16px}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <div class="title">
        <h1>Evaluación: <?php echo h($title); ?></h1>
        <p>Revisa las calificaciones y comentarios del docente sobre tu sitio.</p>
      </div>
      <div class="actions">
        <a class="btn" href="my-website.php">← Mis sitios</a>
        <a class="btn" href="dashboard.php">🏠 Dashboard</a>
        <a class="btn primary" href="<?php echo h($previewUrl); ?>" target="_blank">👁️ Ver sitio</a>
      </div>
    </div>

    <div class="grid">
      <div class="card">
        <div class="iframe-wrap">
          <iframe src="<?php echo h($previewUrl); ?>" title="Vista del sitio"></iframe>
        </div>
      </div>

      <div class="card">
        <div class="pad">
          <div class="kpis">
            <div class="kpi">
              <div class="n"><?php echo ($gradeStats['avg_nota'] !== null) ? h(number_format((float)$gradeStats['avg_nota'], 1)) : '—'; ?></div>
              <div class="l">Promedio (<?php echo (int)($gradeStats['total'] ?? 0); ?>)</div>
            </div>
            <div class="kpi">
              <div class="n"><?php echo h((string)($site['ultima_actualizacion'] ?? '—')); ?></div>
              <div class="l">Última actualización</div>
            </div>
          </div>

          <h2 style="margin:14px 0 10px;font-size:16px;">Calificaciones</h2>
          <div class="list">
            <?php if (empty($grades)): ?>
              <div class="empty">Aún no hay calificaciones para este sitio.</div>
            <?php else: ?>
              <?php foreach ($grades as $g):
                $who = trim((string)($g['prof_apellido'] ?? '') . ' ' . (string)($g['prof_nombre'] ?? ''));
                if ($who === '') { $who = 'Docente'; }
              ?>
                <div class="item">
                  <div class="meta"><span class="who"><?php echo h($who); ?></span> · <?php echo h($g['fecha_calificacion'] ?? ''); ?></div>
                  <div style="margin-top:6px;font-weight:900;">Nota: <?php echo h((string)($g['nota'] ?? '—')); ?>/10</div>
                  <?php if (!empty($g['comentario'])): ?>
                    <div style="margin-top:6px;white-space:pre-wrap;line-height:1.45;"><?php echo h((string)$g['comentario']); ?></div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <h2 style="margin:14px 0 10px;font-size:16px;">Comentarios del docente</h2>
          <div class="list">
            <?php if (empty($teacherComments)): ?>
              <div class="empty">Aún no hay comentarios del docente para este sitio.</div>
            <?php else: ?>
              <?php foreach ($teacherComments as $c):
                $who = trim((string)($c['prof_apellido'] ?? '') . ' ' . (string)($c['prof_nombre'] ?? ''));
                if ($who === '') { $who = 'Docente'; }
              ?>
                <div class="item">
                  <div class="meta"><span class="who"><?php echo h($who); ?></span> · <?php echo h($c['fecha'] ?? ''); ?></div>
                  <div style="margin-top:6px;white-space:pre-wrap;line-height:1.45;"><?php echo h((string)($c['contenido'] ?? '')); ?></div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
