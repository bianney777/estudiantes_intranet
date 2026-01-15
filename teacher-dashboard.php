<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/teacher_auth.php';

ensureTeacherLoggedIn();
$teacher = teacherUser();

$db = getDB();

$q = trim((string)($_GET['q'] ?? ''));
$gradoFilter = trim((string)($_GET['grado'] ?? ''));

// Lista de grados disponibles para filtro (prioriza catálogo configurado y agrega valores detectados en BD)
$gradeOptions = [];
if (isset($GRADOS) && is_array($GRADOS)) {
  foreach ($GRADOS as $g) {
    $g = trim((string)$g);
    if ($g !== '') {
      $gradeOptions[] = $g;
    }
  }
}
try {
  $rowsGrades = $db->query("SELECT DISTINCT grado FROM estudiantes WHERE grado IS NOT NULL AND TRIM(grado) <> '' ORDER BY grado ASC")->fetchAll();
  foreach ($rowsGrades as $r) {
    $g = trim((string)($r['grado'] ?? ''));
    if ($g === '') {
      continue;
    }
    if (!in_array($g, $gradeOptions, true)) {
      $gradeOptions[] = $g;
    }
  }
} catch (Throwable $e) {
  // ignore
}

$params = [];
$where = '';
$conds = [];
if ($q !== '') {
  $conds[] = '(e.codigo LIKE :q OR e.nombre LIKE :q OR e.apellido LIKE :q OR e.email LIKE :q OR sw.nombre_sitio LIKE :q OR sw.url_personalizada LIKE :q)';
  $params[':q'] = '%' . $q . '%';
}
if ($gradoFilter !== '') {
  $conds[] = 'e.grado = :grado';
  $params[':grado'] = $gradoFilter;
}
if ($conds) {
  $where = 'WHERE ' . implode(' AND ', $conds);
}

$sql = "
SELECT
  e.id AS estudiante_id,
  e.codigo,
  e.nombre,
  e.apellido,
  e.email,
  e.carrera,
  e.grado,
  e.last_seen_at,
  e.semestre,
  sw.id AS sitio_id,
  sw.nombre_sitio,
  sw.url_personalizada,
  sw.visitas,
  sw.ultima_actualizacion,
  (SELECT COUNT(1) FROM paginas p WHERE p.sitio_id = sw.id) AS paginas_count,
  (SELECT AVG(c.nota) FROM calificaciones c WHERE c.sitio_id = sw.id) AS avg_nota
FROM estudiantes e
LEFT JOIN sitios_web sw ON sw.estudiante_id = e.id
{$where}
ORDER BY e.apellido ASC, e.nombre ASC, sw.ultima_actualizacion DESC
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Agrupar por estudiante
$students = [];
foreach ($rows as $r) {
    $sid = (int)$r['estudiante_id'];
    if (!isset($students[$sid])) {
        $students[$sid] = [
            'id' => $sid,
            'codigo' => (string)$r['codigo'],
            'nombre' => (string)$r['nombre'],
            'apellido' => (string)$r['apellido'],
            'email' => (string)$r['email'],
            'carrera' => (string)($r['carrera'] ?? ''),
          'grado' => (string)($r['grado'] ?? ''),
      'last_seen_at' => (string)($r['last_seen_at'] ?? ''),
            'semestre' => $r['semestre'] ?? null,
            'sites' => [],
        ];
    }
    if (!empty($r['sitio_id'])) {
        $students[$sid]['sites'][] = [
            'id' => (int)$r['sitio_id'],
            'nombre' => (string)($r['nombre_sitio'] ?? ''),
            'slug' => (string)($r['url_personalizada'] ?? ''),
            'visitas' => (int)($r['visitas'] ?? 0),
            'updated' => (string)($r['ultima_actualizacion'] ?? ''),
            'paginas' => (int)($r['paginas_count'] ?? 0),
            'avg_nota' => $r['avg_nota'],
        ];
    }
}

$totalStudents = count($students);
$totalSites = 0;
foreach ($students as $s) {
    $totalSites += count($s['sites']);
}

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function presenceBadge(?string $lastSeenAt): array {
  $lastSeenAt = trim((string)$lastSeenAt);
  if ($lastSeenAt === '') {
    return ['class' => 'off', 'label' => 'Desconectado'];
  }

  // Interpretar como hora local (config.php fija America/Managua).
  $ts = strtotime($lastSeenAt);
  if ($ts === false) {
    return ['class' => 'off', 'label' => 'Desconectado'];
  }

  $diff = time() - $ts;

  // Si por algún motivo quedó guardado en UTC y parece "futuro", reintentar como UTC.
  if ($diff < -60) {
    $tsUtc = strtotime($lastSeenAt . ' UTC');
    if ($tsUtc !== false) {
      $diff = time() - $tsUtc;
    }
  }

  if ($diff < 0) {
    $diff = 0;
  }

  // Conectado si hubo actividad en los últimos 10 minutos
  if ($diff <= 600) {
    return ['class' => 'on', 'label' => 'Conectado ahora'];
  }

  $mins = (int)floor($diff / 60);
  if ($mins < 60) {
    return ['class' => 'off', 'label' => 'Hace ' . $mins . ' min'];
  }
  $hours = (int)floor($mins / 60);
  return ['class' => 'off', 'label' => 'Hace ' . $hours . ' h'];
}

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard docente</title>
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
    .topbar .inner{max-width:1200px;margin:0 auto;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px}
    .brand{color:white;text-decoration:none;font-weight:900;letter-spacing:-.4px;display:flex;align-items:center;gap:10px}
    .brand .dot{width:34px;height:34px;border-radius:12px;background:rgba(255,255,255,.14);display:grid;place-items:center}
    .nav{display:flex;gap:10px;flex-wrap:wrap}
    .pill{color:rgba(255,255,255,.92);text-decoration:none;font-weight:700;font-size:14px;padding:10px 12px;border-radius:999px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.08)}
    .pill:hover{background:rgba(255,255,255,.14)}

    .wrap{max-width:1200px;margin:0 auto;padding:20px 16px 40px}
    .header{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap}
    h1{margin:0;color:white;letter-spacing:-.6px}
    .sub{margin:6px 0 0;color:rgba(255,255,255,.82)}

    .cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:18px 0}
    @media(max-width:720px){.cards{grid-template-columns:1fr}}
    .card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:14px 14px;box-shadow:0 20px 60px rgba(0,0,0,.15)}
    .kpi{display:flex;align-items:center;justify-content:space-between;gap:10px}
    .kpi .n{font-weight:900;font-size:22px}
    .kpi .l{color:var(--muted);font-weight:700}

    .search{display:flex;gap:10px;flex-wrap:wrap;margin:14px 0}
    .search input{flex:1;min-width:220px;padding:12px 12px;border-radius:14px;border:1px solid rgba(17,24,39,.18)}
    .search button{padding:12px 14px;border-radius:14px;border:none;cursor:pointer;font-weight:800;color:white;background:linear-gradient(135deg,var(--primary),var(--secondary))}
    .search a{padding:12px 14px;border-radius:14px;border:1px solid rgba(17,24,39,.18);text-decoration:none;color:var(--text);font-weight:800;background:white}

    .student{background:rgba(255,255,255,.92);border:1px solid var(--border);border-radius:18px;margin-top:12px;overflow:hidden}
    .student-head{padding:14px 14px;display:flex;align-items:flex-start;justify-content:space-between;gap:10px;flex-wrap:wrap}
    .student-name{font-weight:900}
    .student-meta{color:var(--muted);font-weight:650;font-size:13px}
    .presence{display:inline-flex;align-items:center;gap:8px;font-weight:900;font-size:12px;padding:8px 10px;border-radius:999px;border:1px solid rgba(17,24,39,.14);background:white}
    .presence .dot{width:10px;height:10px;border-radius:999px;background:#9ca3af;box-shadow:0 0 0 4px rgba(156,163,175,.16)}
    .presence.on{border-color:rgba(34,197,94,.25);background:rgba(34,197,94,.08);color:#065f46}
    .presence.on .dot{background:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,.18)}
    .presence.off{border-color:rgba(107,114,128,.20);background:rgba(107,114,128,.08);color:#374151}

    .sites{border-top:1px solid rgba(17,24,39,.08)}
    .site{padding:12px 14px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
    .site + .site{border-top:1px solid rgba(17,24,39,.08)}
    .site-title{font-weight:900}
    .site-small{color:var(--muted);font-size:13px;font-weight:650}
    .actions{display:flex;gap:8px;flex-wrap:wrap}
    .btn{display:inline-flex;align-items:center;gap:8px;text-decoration:none;font-weight:900;font-size:13px;padding:10px 12px;border-radius:999px;border:1px solid rgba(17,24,39,.14);background:white;color:var(--text)}
    .btn.primary{border:none;color:white;background:linear-gradient(135deg,var(--primary),var(--secondary))}
    .btn:hover{filter:brightness(1.02)}

    .footer-credit{margin-top:18px;text-align:center;color:rgba(255,255,255,.82);font-weight:800;font-size:12px}

    .empty{color:rgba(255,255,255,.85);margin-top:18px}
  </style>
</head>
<body>
  <div class="topbar">
    <div class="inner">
      <a class="brand" href="teacher-dashboard.php"><span class="dot"><i class="fa-solid fa-chalkboard-user"></i></span> Docente</a>
      <div class="nav">
        <a class="pill" href="teacher-create.php"><i class="fa-solid fa-user-plus"></i> Crear maestro</a>
        <a class="pill" href="index.php" target="_blank"><i class="fa-solid fa-house"></i> Inicio</a>
        <a class="pill" href="teacher-logout.php"><i class="fa-solid fa-right-from-bracket"></i> Salir</a>
      </div>
    </div>
  </div>

  <div class="wrap">
    <div class="header">
      <div>
        <h1>Dashboard docente</h1>
        <p class="sub">Hola, <?php echo h(($teacher['nombre'] ?? '') . ' ' . ($teacher['apellido'] ?? '')); ?>. Revisa proyectos, comenta y califica.</p>
      </div>
    </div>

    <div class="cards">
      <div class="card"><div class="kpi"><div><div class="n"><?php echo (int)$totalStudents; ?></div><div class="l">Estudiantes</div></div><i class="fa-solid fa-user-graduate" style="color:#667eea"></i></div></div>
      <div class="card"><div class="kpi"><div><div class="n"><?php echo (int)$totalSites; ?></div><div class="l">Sitios</div></div><i class="fa-solid fa-globe" style="color:#764ba2"></i></div></div>
    </div>

    <form class="search" method="get" action="">
      <input name="q" placeholder="Buscar por estudiante, email, sitio o slug..." value="<?php echo h($q); ?>">
      <select name="grado" style="max-width:220px;">
        <option value="" <?php echo ($gradoFilter === '') ? 'selected' : ''; ?>>Todos los grados</option>
        <?php foreach ($gradeOptions as $g): ?>
          <option value="<?php echo h($g); ?>" <?php echo ($gradoFilter === (string)$g) ? 'selected' : ''; ?>><?php echo h($g); ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
      <a href="teacher-dashboard.php"><i class="fa-solid fa-rotate"></i> Limpiar</a>
    </form>

    <?php if (!$students): ?>
      <div class="empty">No hay estudiantes para mostrar.</div>
    <?php else: ?>
      <?php foreach ($students as $s): ?>
        <?php $presence = presenceBadge($s['last_seen_at'] ?? ''); ?>
        <section class="student">
          <div class="student-head">
            <div>
              <div class="student-name"><?php echo h($s['apellido'] . ', ' . $s['nombre']); ?> <span style="color:#6b7280;font-weight:800;">(<?php echo h($s['codigo']); ?>)</span></div>
              <div class="student-meta"><?php echo h($s['email']); ?><?php if ($s['grado']): ?> · Grado <?php echo h($s['grado']); ?><?php endif; ?><?php if ($s['carrera']): ?> · <?php echo h($s['carrera']); ?><?php endif; ?><?php if (!empty($s['semestre'])): ?> · Sem <?php echo h($s['semestre']); ?><?php endif; ?></div>
            </div>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:flex-end">
              <span class="presence <?php echo h($presence['class']); ?>"><span class="dot" aria-hidden="true"></span><?php echo h($presence['label']); ?></span>
              <div class="student-meta">Sitios: <?php echo count($s['sites']); ?></div>
            </div>
          </div>

          <div class="sites">
            <?php if (!$s['sites']): ?>
              <div class="site"><div class="site-small">Sin sitios creados aún.</div></div>
            <?php else: ?>
              <?php foreach ($s['sites'] as $site):
                $slug = $site['slug'];
                $preview = 's/' . rawurlencode($s['codigo']) . '/' . rawurlencode($slug);
              ?>
                <div class="site">
                  <div>
                    <div class="site-title"><?php echo h($site['nombre'] ?: $slug); ?></div>
                    <div class="site-small">Slug: <b><?php echo h($slug); ?></b> · Páginas: <?php echo (int)$site['paginas']; ?> · Visitas: <?php echo (int)$site['visitas']; ?><?php if ($site['avg_nota'] !== null): ?> · Promedio: <?php echo h(number_format((float)$site['avg_nota'], 1)); ?><?php endif; ?></div>
                  </div>
                  <div class="actions">
                    <a class="btn" href="<?php echo h($preview); ?>" target="_blank"><i class="fa-solid fa-eye"></i> Ver</a>
                    <a class="btn primary" href="teacher-review.php?site_id=<?php echo (int)$site['id']; ?>"><i class="fa-solid fa-star"></i> Evaluar</a>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </section>
      <?php endforeach; ?>
    <?php endif; ?>

    <div class="footer-credit">Elaborado por Biamney</div>
  </div>
</body>
</html>
