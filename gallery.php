<?php
require_once __DIR__ . '/includes/config.php';

$db = null;
try {
  $db = getDB();
} catch (Throwable $e) {
  $db = null;
}

$sites = [];
if ($db instanceof PDO) {
  $stmt = $db->query(
    "SELECT sw.id, sw.nombre_sitio, sw.url_personalizada, sw.visitas, sw.ultima_actualizacion,
            e.codigo, e.nombre, e.apellido, e.carrera
     FROM sitios_web sw
     JOIN estudiantes e ON e.id = sw.estudiante_id
     ORDER BY sw.ultima_actualizacion DESC, sw.id DESC"
  );
  $sites = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function monthsSince(?string $iso): string {
  if (!$iso) return '';
  try {
    $dt = new DateTime($iso);
    $now = new DateTime('now');
    $diff = $dt->diff($now);
    $months = ((int)$diff->y * 12) + (int)$diff->m;
    if ($months <= 0) return 'reciente';
    return $months . ' mes' . ($months === 1 ? '' : 'es');
  } catch (Throwable $e) {
    return '';
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Galería - <?php echo SITE_NAME; ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root{
      --bg:#0b1020;
      --card:#111a33;
      --text:#e8ecff;
      --muted:#b5bddc;
      --primary:#667eea;
      --primary2:#764ba2;
      --border:rgba(255,255,255,.10);
    }
    *{box-sizing:border-box}
    body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:radial-gradient(1200px 600px at 20% -10%, rgba(102,126,234,.35), transparent),radial-gradient(1200px 600px at 80% 10%, rgba(118,75,162,.35), transparent),var(--bg);color:var(--text)}
    a{color:inherit}
    .wrap{max-width:1150px;margin:0 auto;padding:28px 18px 48px}
    .top{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px}
    h1{margin:0;font-size:22px}
    .muted{color:var(--muted);font-size:13px}
    .btn{border:1px solid var(--border);background:rgba(255,255,255,.06);color:var(--text);padding:10px 14px;border-radius:12px;text-decoration:none;font-weight:600;font-size:14px;display:inline-flex;align-items:center;gap:10px;transition:transform .08s ease, background .2s ease}
    .btn:hover{background:rgba(255,255,255,.10)}
    .btn:active{transform:translateY(1px)}
    .grid{display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:14px;margin-top:14px}
    .card{border:1px solid var(--border);background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03));border-radius:18px;overflow:hidden;text-decoration:none}
    .thumb{height:130px;background:linear-gradient(135deg, rgba(102,126,234,.35), rgba(118,75,162,.25));display:flex;align-items:center;justify-content:center}
    .thumb i{font-size:36px;opacity:.95}
    .info{padding:14px}
    .title{font-weight:900}
    .meta{display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;font-size:12px;color:rgba(181,189,220,.9)}
    .pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;border:1px solid var(--border);background:rgba(255,255,255,.05)}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <div>
        <h1>Galería de sitios</h1>
        <div class="muted">Explora los sitios creados por estudiantes.</div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <a class="btn" href="index.php"><i class="fas fa-house"></i> Inicio</a>
        <a class="btn" href="login.php"><i class="fas fa-right-to-bracket"></i> Entrar</a>
      </div>
    </div>

    <?php if (empty($sites)): ?>
      <div class="muted" style="margin-top:16px;">Aún no hay sitios para mostrar.</div>
    <?php else: ?>
      <div class="grid">
        <?php foreach ($sites as $s): ?>
          <?php
            $codigo = (string)($s['codigo'] ?? '');
            $slug = (string)($s['url_personalizada'] ?? '');
            $link = 's/' . rawurlencode($codigo);
            if ($slug !== '') {
              $link .= '/' . rawurlencode($slug);
            }
            $studentName = trim((string)($s['nombre'] ?? '') . ' ' . (string)($s['apellido'] ?? ''));
            if ($studentName === '') $studentName = $codigo;
          ?>
          <a class="card" href="<?php echo htmlspecialchars($link); ?>" target="_blank">
            <div class="thumb"><i class="fas fa-laptop-code"></i></div>
            <div class="info">
              <div class="title"><?php echo htmlspecialchars((string)($s['nombre_sitio'] ?? 'Sitio')); ?></div>
              <div class="muted" style="margin-top:4px;"><?php echo htmlspecialchars($studentName); ?> • <?php echo htmlspecialchars((string)($s['carrera'] ?? '')); ?></div>
              <div class="meta">
                <span class="pill"><i class="fas fa-eye"></i> <?php echo number_format((int)($s['visitas'] ?? 0)); ?></span>
                <span class="pill"><i class="far fa-calendar"></i> <?php echo htmlspecialchars(monthsSince((string)($s['ultima_actualizacion'] ?? ''))); ?></span>
                <?php if ($slug !== ''): ?>
                  <span class="pill"><i class="fas fa-link"></i> <?php echo htmlspecialchars($slug); ?></span>
                <?php endif; ?>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>