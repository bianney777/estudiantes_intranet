<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/teacher_auth.php';

ensureTeacherLoggedIn();
$teacher = teacherUser();

$db = getDB();
$siteId = (int)($_GET['site_id'] ?? 0);
if ($siteId <= 0) {
    http_response_code(400);
    die('site_id inválido');
}

$stmt = $db->prepare(
    'SELECT sw.*, e.codigo AS estudiante_codigo, e.nombre AS estudiante_nombre, e.apellido AS estudiante_apellido, e.email AS estudiante_email '
    . 'FROM sitios_web sw JOIN estudiantes e ON e.id = sw.estudiante_id '
    . 'WHERE sw.id = ? LIMIT 1'
);
$stmt->execute([$siteId]);
$site = $stmt->fetch();
if (!$site) {
    http_response_code(404);
    die('Sitio no encontrado');
}

$studentCode = (string)$site['estudiante_codigo'];
$slug = (string)($site['url_personalizada'] ?? '');
$previewUrl = 's/' . rawurlencode($studentCode) . '/' . rawurlencode($slug);

// Post: comentario docente o calificación
$flash = null;
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'comment') {
            $content = trim((string)($_POST['contenido'] ?? ''));
            if ($content === '') {
                $error = 'Escribe un comentario.';
            } else {
                // comentarios: profesor_id (agregado por migración) y estudiante_id NULL
                $ins = $db->prepare('INSERT INTO comentarios (sitio_id, estudiante_id, profesor_id, contenido, fecha) VALUES (?, NULL, ?, ?, datetime("now"))');
                $ins->execute([$siteId, (int)$teacher['id'], $content]);
                $flash = 'Comentario publicado.';
            }
        } elseif ($action === 'grade') {
            $notaRaw = trim((string)($_POST['nota'] ?? ''));
            $comentario = trim((string)($_POST['comentario'] ?? ''));

            if ($notaRaw === '' || !is_numeric($notaRaw)) {
                $error = 'Ingresa una nota válida.';
            } else {
                $nota = (float)$notaRaw;
                if ($nota < 0 || $nota > 10) {
                    $error = 'La nota debe estar entre 0 y 10.';
                } else {
                    // Si ya calificó este profesor este sitio, actualiza; si no, inserta.
                    $sel = $db->prepare('SELECT id FROM calificaciones WHERE sitio_id = ? AND profesor_id = ? LIMIT 1');
                    $sel->execute([$siteId, (int)$teacher['id']]);
                    $existing = $sel->fetch();
                    if ($existing) {
                        $upd = $db->prepare('UPDATE calificaciones SET nota = ?, comentario = ?, fecha_calificacion = datetime("now") WHERE id = ?');
                        $upd->execute([$nota, ($comentario !== '' ? $comentario : null), (int)$existing['id']]);
                    } else {
                        $ins = $db->prepare('INSERT INTO calificaciones (sitio_id, profesor_id, nota, comentario, criterios, fecha_calificacion) VALUES (?, ?, ?, ?, NULL, datetime("now"))');
                        $ins->execute([$siteId, (int)$teacher['id'], $nota, ($comentario !== '' ? $comentario : null)]);
                    }
                    $flash = 'Calificación guardada.';
                }
            }
        }
    } catch (Throwable $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Datos para UI
$stmt = $db->prepare(
    'SELECT c.id, c.contenido, c.fecha, c.estudiante_id, c.profesor_id, '
    . 'e.codigo AS est_codigo, e.nombre AS est_nombre, e.apellido AS est_apellido '
    . 'FROM comentarios c '
    . 'LEFT JOIN estudiantes e ON e.id = c.estudiante_id '
    . 'WHERE c.sitio_id = ? '
    . 'ORDER BY c.fecha DESC, c.id DESC'
);
$stmt->execute([$siteId]);
$comments = $stmt->fetchAll();

$stmt = $db->prepare('SELECT * FROM calificaciones WHERE sitio_id = ? AND profesor_id = ? LIMIT 1');
$stmt->execute([$siteId, (int)$teacher['id']]);
$myGrade = $stmt->fetch();

$stmt = $db->prepare(
    'SELECT AVG(nota) AS avg_nota, COUNT(1) AS total '
    . 'FROM calificaciones WHERE sitio_id = ?'
);
$stmt->execute([$siteId]);
$gradeStats = $stmt->fetch() ?: ['avg_nota' => null, 'total' => 0];

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Evaluar sitio</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root{--primary:#667eea;--secondary:#764ba2;--bg:#0b1026;--card:rgba(255,255,255,.95);--text:#111827;--muted:#6b7280;--border:rgba(17,24,39,.10)}
    *{box-sizing:border-box}
    body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:var(--text);
      background:radial-gradient(1200px 700px at 15% 10%, rgba(102,126,234,.35), transparent 55%),
                 radial-gradient(900px 600px at 85% 20%, rgba(118,75,162,.30), transparent 55%),
                 linear-gradient(135deg, var(--bg), #1a1f3a);
      min-height:100vh;
    }
    .topbar{position:sticky;top:0;z-index:10;background:rgba(10,14,30,.45);backdrop-filter:blur(14px);border-bottom:1px solid rgba(255,255,255,.12)}
    .topbar .inner{max-width:1300px;margin:0 auto;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px}
    .brand{color:white;text-decoration:none;font-weight:900;letter-spacing:-.4px;display:flex;align-items:center;gap:10px}
    .brand .dot{width:34px;height:34px;border-radius:12px;background:rgba(255,255,255,.14);display:grid;place-items:center}
    .nav{display:flex;gap:10px;flex-wrap:wrap}
    .pill{color:rgba(255,255,255,.92);text-decoration:none;font-weight:700;font-size:14px;padding:10px 12px;border-radius:999px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.08)}
    .pill:hover{background:rgba(255,255,255,.14)}

    .wrap{max-width:1300px;margin:0 auto;padding:18px 16px 40px}
    h1{margin:0;color:white;letter-spacing:-.6px}
    .sub{margin:6px 0 0;color:rgba(255,255,255,.82)}

    .grid{display:grid;grid-template-columns: 1.5fr .95fr;gap:12px;margin-top:14px}
    @media(max-width:980px){.grid{grid-template-columns:1fr}}

    .card{background:var(--card);border:1px solid var(--border);border-radius:18px;box-shadow:0 20px 60px rgba(0,0,0,.15);overflow:hidden}
    .card .pad{padding:14px}

    .iframe-wrap{height:78vh;min-height:520px;background:#fff}
    iframe{width:100%;height:100%;border:0}

    .kpis{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
    .kpi{border:1px solid rgba(17,24,39,.10);border-radius:16px;padding:12px;background:white}
    .kpi .n{font-weight:900;font-size:18px}
    .kpi .l{color:var(--muted);font-weight:700;font-size:13px}

    .form{display:grid;gap:10px}
    .label{font-size:13px;font-weight:800}
    .input, textarea{width:100%;padding:12px 12px;border-radius:14px;border:1px solid rgba(17,24,39,.18)}
    textarea{min-height:90px;resize:vertical}
    .btn{display:inline-flex;align-items:center;gap:8px;text-decoration:none;font-weight:900;font-size:13px;padding:10px 12px;border-radius:999px;border:1px solid rgba(17,24,39,.14);background:white;color:var(--text);cursor:pointer}
    .btn.primary{border:none;color:white;background:linear-gradient(135deg,var(--primary),var(--secondary))}

    .alert{border-radius:16px;padding:12px;border:1px solid rgba(176,0,32,.25);background:rgba(176,0,32,.08);color:#b00020;font-weight:700}
    .ok{border-color:rgba(16,185,129,.25);background:rgba(16,185,129,.10);color:#065f46}

    .comment{border-top:1px solid rgba(17,24,39,.10);padding:12px 14px}
    .comment .meta{color:var(--muted);font-size:12px;font-weight:700;margin-bottom:6px}
    .comment .who{font-weight:900;color:#111827}
  </style>
</head>
<body>
  <div class="topbar">
    <div class="inner">
      <a class="brand" href="teacher-dashboard.php"><span class="dot"><i class="fa-solid fa-chalkboard-user"></i></span> Docente</a>
      <div class="nav">
        <a class="pill" href="<?php echo h($previewUrl); ?>" target="_blank"><i class="fa-solid fa-eye"></i> Abrir</a>
        <a class="pill" href="teacher-dashboard.php"><i class="fa-solid fa-arrow-left"></i> Volver</a>
        <a class="pill" href="teacher-logout.php"><i class="fa-solid fa-right-from-bracket"></i> Salir</a>
      </div>
    </div>
  </div>

  <div class="wrap">
    <h1>Evaluar: <?php echo h(($site['nombre_sitio'] ?? '') ?: ($site['url_personalizada'] ?? '')); ?></h1>
    <p class="sub">Estudiante: <?php echo h($site['estudiante_apellido'] . ', ' . $site['estudiante_nombre']); ?> (<?php echo h($studentCode); ?>) · Slug: <b><?php echo h($slug); ?></b></p>

    <?php if ($error): ?><div class="alert" role="alert" style="margin-top:12px;"><?php echo h($error); ?></div><?php endif; ?>
    <?php if ($flash): ?><div class="alert ok" role="status" style="margin-top:12px;"><?php echo h($flash); ?></div><?php endif; ?>

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
              <div class="n"><?php echo h((string)($myGrade['nota'] ?? '—')); ?></div>
              <div class="l">Mi nota</div>
            </div>
          </div>

          <h3 style="margin:14px 0 10px;">Calificación</h3>
          <form class="form" method="post" action="">
            <input type="hidden" name="action" value="grade">
            <div>
              <label class="label" for="nota">Nota (0 a 10)</label>
              <input class="input" id="nota" name="nota" type="number" step="0.5" min="0" max="10" value="<?php echo h($myGrade['nota'] ?? ''); ?>" required>
            </div>
            <div>
              <label class="label" for="comentario">Comentario (opcional)</label>
              <textarea id="comentario" name="comentario" placeholder="Observaciones generales..."><?php echo h($myGrade['comentario'] ?? ''); ?></textarea>
            </div>
            <button class="btn primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
          </form>

          <h3 style="margin:16px 0 10px;">Comentarios</h3>
          <form class="form" method="post" action="">
            <input type="hidden" name="action" value="comment">
            <div>
              <label class="label" for="contenido">Nuevo comentario</label>
              <textarea id="contenido" name="contenido" placeholder="Escribe un comentario para el estudiante..."></textarea>
            </div>
            <button class="btn" type="submit"><i class="fa-solid fa-paper-plane"></i> Publicar</button>
          </form>
        </div>

        <?php if ($comments): ?>
          <?php foreach ($comments as $c):
            $isTeacher = !empty($c['profesor_id']);
            $who = $isTeacher
              ? 'Docente'
              : ((string)($c['est_apellido'] ?? '') !== '' ? ($c['est_apellido'] . ', ' . $c['est_nombre']) : 'Estudiante');
          ?>
            <div class="comment">
              <div class="meta"><span class="who"><?php echo h($who); ?></span> · <?php echo h($c['fecha'] ?? ''); ?></div>
              <div><?php echo nl2br(h($c['contenido'] ?? '')); ?></div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="comment"><div class="meta">Aún no hay comentarios.</div></div>
        <?php endif; ?>

      </div>
    </div>
  </div>
</body>
</html>
