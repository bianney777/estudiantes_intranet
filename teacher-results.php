<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/teacher_auth.php';

ensureTeacherLoggedIn();
$teacher = teacherUser();

$db = getDB();

$gradoFilter = trim((string)($_GET['grado'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));

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
$conds = [];
if ($gradoFilter !== '') {
    $conds[] = 'e.grado = :grado';
    $params[':grado'] = $gradoFilter;
}
if ($q !== '') {
    $conds[] = '(e.codigo LIKE :q OR e.nombre LIKE :q OR e.apellido LIKE :q OR e.email LIKE :q OR sw.nombre_sitio LIKE :q OR sw.url_personalizada LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
$where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';

$sql = "
SELECT
  e.id AS estudiante_id,
  e.codigo,
  e.nombre,
  e.apellido,
  e.email,
  e.carrera,
  e.grado,
  e.semestre,
  sw.id AS sitio_id,
  sw.nombre_sitio,
  sw.url_personalizada,
  sw.estado,
  sw.visitas,
  sw.ultima_actualizacion,
  (SELECT COUNT(1) FROM paginas p WHERE p.sitio_id = sw.id) AS paginas_count,
  (SELECT AVG(c.nota) FROM calificaciones c WHERE c.sitio_id = sw.id) AS avg_nota,
  (SELECT COUNT(1) FROM calificaciones c WHERE c.sitio_id = sw.id) AS grades_count
FROM estudiantes e
LEFT JOIN sitios_web sw ON sw.estudiante_id = e.id
{$where}
ORDER BY e.grado ASC, e.apellido ASC, e.nombre ASC, sw.ultima_actualizacion DESC
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Agrupar por estudiante
$students = [];
foreach ($rows as $r) {
    $studentId = (int)($r['estudiante_id'] ?? 0);
    if ($studentId <= 0) {
        continue;
    }

    if (!isset($students[$studentId])) {
        $students[$studentId] = [
            'id' => $studentId,
            'codigo' => (string)($r['codigo'] ?? ''),
            'nombre' => (string)($r['nombre'] ?? ''),
            'apellido' => (string)($r['apellido'] ?? ''),
            'email' => (string)($r['email'] ?? ''),
            'carrera' => (string)($r['carrera'] ?? ''),
            'grado' => (string)($r['grado'] ?? ''),
            'semestre' => $r['semestre'] ?? null,
            'sites' => [],
        ];
    }

    if (!empty($r['sitio_id'])) {
        $students[$studentId]['sites'][] = [
            'id' => (int)($r['sitio_id'] ?? 0),
            'nombre' => (string)($r['nombre_sitio'] ?? ''),
            'slug' => (string)($r['url_personalizada'] ?? ''),
            'estado' => (string)($r['estado'] ?? ''),
            'visitas' => (int)($r['visitas'] ?? 0),
            'updated' => (string)($r['ultima_actualizacion'] ?? ''),
            'paginas' => (int)($r['paginas_count'] ?? 0),
            'avg_nota' => $r['avg_nota'],
            'grades_count' => (int)($r['grades_count'] ?? 0),
        ];
    }
}

// KPIs + agregados
$totalStudents = count($students);
$totalWorks = 0; // trabajos = sitios
$totalGradedWorks = 0;
$sumWorkAvg = 0.0;
$countWorkAvg = 0;

foreach ($students as &$s) {
    $works = count($s['sites']);
    $gradedWorks = 0;
    $sumAvg = 0.0;
    $countAvg = 0;

    foreach ($s['sites'] as $site) {
        $totalWorks++;
        $avg = $site['avg_nota'];
        $hasGrade = $avg !== null && $site['grades_count'] > 0;
        if ($hasGrade) {
            $gradedWorks++;
            $sumAvg += (float)$avg;
            $countAvg++;

            $sumWorkAvg += (float)$avg;
            $countWorkAvg++;
        }
    }

    $s['works_count'] = $works;
    $s['graded_works_count'] = $gradedWorks;
    $s['avg_trabajos'] = $countAvg > 0 ? ($sumAvg / $countAvg) : null;

    $totalGradedWorks += $gradedWorks;
}
unset($s);

$globalAvgWorks = $countWorkAvg > 0 ? ($sumWorkAvg / $countWorkAvg) : null;

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$titleGrade = $gradoFilter !== '' ? (' · Grado ' . $gradoFilter) : '';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Resultados<?php echo h($titleGrade); ?> - Docente</title>
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
    .pill{color:rgba(255,255,255,.92);text-decoration:none;font-weight:800;font-size:14px;padding:10px 12px;border-radius:999px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.08)}
    .pill:hover{background:rgba(255,255,255,.14)}

    .wrap{max-width:1300px;margin:0 auto;padding:18px 16px 40px}
    .header{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap}
    h1{margin:0;color:white;letter-spacing:-.6px}
    .sub{margin:6px 0 0;color:rgba(255,255,255,.82)}

    .cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:18px 0}
    @media(max-width:980px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:560px){.cards{grid-template-columns:1fr}}

    .card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:14px 14px;box-shadow:0 20px 60px rgba(0,0,0,.15)}
    .kpi{display:flex;align-items:center;justify-content:space-between;gap:10px}
    .kpi .n{font-weight:900;font-size:22px}
    .kpi .l{color:var(--muted);font-weight:800}

    .filters{display:flex;gap:10px;flex-wrap:wrap;margin:14px 0}
    .filters input{flex:1;min-width:220px;padding:12px 12px;border-radius:14px;border:1px solid rgba(17,24,39,.18)}
    .filters select{min-width:220px;padding:12px 12px;border-radius:14px;border:1px solid rgba(17,24,39,.18)}
    .filters button{padding:12px 14px;border-radius:14px;border:none;cursor:pointer;font-weight:900;color:white;background:linear-gradient(135deg,var(--primary),var(--secondary))}
    .filters a{padding:12px 14px;border-radius:14px;border:1px solid rgba(17,24,39,.18);text-decoration:none;color:var(--text);font-weight:900;background:white}

    .table{background:rgba(255,255,255,.92);border:1px solid var(--border);border-radius:18px;overflow:hidden}
    .thead{display:grid;grid-template-columns: 2fr 1fr 1fr 1fr 1fr 140px;gap:10px;padding:12px 14px;background:rgba(17,24,39,.04);font-weight:900}
    .row{display:grid;grid-template-columns: 2fr 1fr 1fr 1fr 1fr 140px;gap:10px;padding:12px 14px;border-top:1px solid rgba(17,24,39,.08);align-items:center}
    @media(max-width:980px){
      .thead,.row{grid-template-columns:1fr 1fr 1fr;}
      .hide-sm{display:none}
    }

    .name{font-weight:900}
    .meta{color:var(--muted);font-weight:750;font-size:12px;margin-top:2px}
    .chip{display:inline-flex;align-items:center;gap:8px;font-weight:900;font-size:12px;padding:7px 10px;border-radius:999px;border:1px solid rgba(17,24,39,.14);background:white}
    .chip.ok{border-color:rgba(34,197,94,.25);background:rgba(34,197,94,.10);color:#065f46}
    .chip.warn{border-color:rgba(245,158,11,.25);background:rgba(245,158,11,.12);color:#92400e}
    .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;font-weight:900;font-size:13px;padding:10px 12px;border-radius:999px;border:1px solid rgba(17,24,39,.14);background:white;color:var(--text)}
    .btn.primary{border:none;color:white;background:linear-gradient(135deg,var(--primary),var(--secondary))}

    details{margin:10px 0 0}
    .sites{margin-top:8px;border:1px solid rgba(17,24,39,.10);border-radius:14px;overflow:hidden;background:white}
    .site{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:10px 12px;border-top:1px solid rgba(17,24,39,.08)}
    .site:first-child{border-top:none}
    .site-title{font-weight:900}
    .site-small{color:var(--muted);font-size:12px;font-weight:750}
    .site-actions{display:flex;gap:8px;flex-wrap:wrap}

    .empty{color:rgba(255,255,255,.85);margin-top:18px}
    .footer-credit{margin-top:18px;text-align:center;color:rgba(255,255,255,.82);font-weight:800;font-size:12px}
  </style>
</head>
<body>
  <div class="topbar">
    <div class="inner">
      <a class="brand" href="teacher-dashboard.php"><span class="dot"><i class="fa-solid fa-chalkboard-user"></i></span> Docente</a>
      <div class="nav">
        <a class="pill" href="teacher-dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
        <a class="pill" href="teacher-review.php" style="display:none"></a>
        <a class="pill" href="teacher-logout.php"><i class="fa-solid fa-right-from-bracket"></i> Salir</a>
      </div>
    </div>
  </div>

  <div class="wrap">
    <div class="header">
      <div>
        <h1>Resultados por grado</h1>
        <p class="sub">Filtra por grado y revisa trabajos (sitios) y calificaciones.</p>
      </div>
      <div style="color:rgba(255,255,255,.86);font-weight:900;">Docente: <?php echo h(($teacher['apellido'] ?? '') . ' ' . ($teacher['nombre'] ?? '')); ?></div>
    </div>

    <div class="cards">
      <div class="card"><div class="kpi"><div><div class="n"><?php echo (int)$totalStudents; ?></div><div class="l">Alumnos</div></div><div style="opacity:.65;font-size:22px">👥</div></div></div>
      <div class="card"><div class="kpi"><div><div class="n"><?php echo (int)$totalWorks; ?></div><div class="l">Trabajos (sitios)</div></div><div style="opacity:.65;font-size:22px">🧩</div></div></div>
      <div class="card"><div class="kpi"><div><div class="n"><?php echo (int)$totalGradedWorks; ?></div><div class="l">Trabajos calificados</div></div><div style="opacity:.65;font-size:22px">⭐</div></div></div>
      <div class="card"><div class="kpi"><div><div class="n"><?php echo ($globalAvgWorks !== null) ? h(number_format((float)$globalAvgWorks, 1)) : '—'; ?></div><div class="l">Promedio (trabajos)</div></div><div style="opacity:.65;font-size:22px">📊</div></div></div>
    </div>

    <form class="filters" method="get" action="">
      <input name="q" placeholder="Buscar alumno, email, sitio o slug..." value="<?php echo h($q); ?>">
      <select name="grado">
        <option value="" <?php echo ($gradoFilter === '') ? 'selected' : ''; ?>>Todos los grados</option>
        <?php foreach ($gradeOptions as $g): ?>
          <option value="<?php echo h($g); ?>" <?php echo ($gradoFilter === (string)$g) ? 'selected' : ''; ?>><?php echo h($g); ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit"><i class="fa-solid fa-filter"></i> Filtrar</button>
      <a href="teacher-results.php"><i class="fa-solid fa-rotate"></i> Limpiar</a>
    </form>

    <?php if (!$students): ?>
      <div class="empty">No hay estudiantes para mostrar.</div>
    <?php else: ?>
      <div class="table" role="table" aria-label="Resultados por alumno">
        <div class="thead" role="row">
          <div>Alumno</div>
          <div class="hide-sm">Grado</div>
          <div>Trabajos</div>
          <div>Calificados</div>
          <div>Promedio</div>
          <div>Acción</div>
        </div>

        <?php foreach ($students as $s): ?>
          <?php
            $avg = $s['avg_trabajos'];
            $chipClass = $avg === null ? 'warn' : (($avg >= 6.0) ? 'ok' : 'warn');
            $avgLabel = $avg === null ? '—' : number_format((float)$avg, 1);
          ?>
          <div class="row" role="row">
            <div>
              <div class="name"><?php echo h($s['apellido'] . ', ' . $s['nombre']); ?> <span style="color:#6b7280;font-weight:900;">(<?php echo h($s['codigo']); ?>)</span></div>
              <div class="meta"><?php echo h($s['email']); ?><?php if ($s['carrera']): ?> · <?php echo h($s['carrera']); ?><?php endif; ?><?php if (!empty($s['semestre'])): ?> · Sem <?php echo h($s['semestre']); ?><?php endif; ?></div>
            </div>
            <div class="hide-sm"><?php echo h($s['grado'] ?: '—'); ?></div>
            <div><span class="chip"><?php echo (int)$s['works_count']; ?></span></div>
            <div><span class="chip"><?php echo (int)$s['graded_works_count']; ?></span></div>
            <div><span class="chip <?php echo h($chipClass); ?>"><?php echo h($avgLabel); ?>/10</span></div>
            <div>
              <?php if (!empty($s['sites'])): ?>
                <details>
                  <summary class="btn"><i class="fa-solid fa-list"></i> Ver</summary>
                  <div class="sites">
                    <?php foreach ($s['sites'] as $site): ?>
                      <?php
                        $siteTitle = (string)($site['nombre'] ?: $site['slug']);
                        $siteAvg = $site['avg_nota'] !== null ? number_format((float)$site['avg_nota'], 1) : null;
                        $siteBadge = $siteAvg === null ? '—' : ($siteAvg . '/10');
                      ?>
                      <div class="site">
                        <div>
                          <div class="site-title"><?php echo h($siteTitle); ?></div>
                          <div class="site-small">Slug: <b><?php echo h($site['slug']); ?></b> · Páginas: <?php echo (int)$site['paginas']; ?> · Estado: <?php echo h($site['estado'] ?: '—'); ?> · Promedio: <b><?php echo h($siteBadge); ?></b></div>
                        </div>
                        <div class="site-actions">
                          <?php if ($site['slug'] !== ''): ?>
                            <a class="btn" href="s/<?php echo rawurlencode($s['codigo']); ?>/<?php echo rawurlencode($site['slug']); ?>" target="_blank"><i class="fa-solid fa-eye"></i> Ver</a>
                          <?php endif; ?>
                          <a class="btn primary" href="teacher-review.php?site_id=<?php echo (int)$site['id']; ?>"><i class="fa-solid fa-star"></i> Evaluar</a>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </details>
              <?php else: ?>
                <span class="meta">Sin trabajos</span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="footer-credit">Elaborado por Biamney</div>
  </div>
</body>
</html>
