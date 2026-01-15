<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/teacher_auth.php';

ensureTeacherLoggedIn();
$db = getDB();

$flash = null;
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $codigo = trim((string)($_POST['codigo'] ?? ''));
    $nombre = trim((string)($_POST['nombre'] ?? ''));
    $apellido = trim((string)($_POST['apellido'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($codigo === '' || $nombre === '' || $apellido === '' || $email === '' || $password === '') {
        $error = 'Completa código, nombre, apellido, email y contraseña.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email inválido.';
    } elseif (strlen($password) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres.';
    } else {
        try {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $db->prepare('INSERT INTO profesores (codigo, nombre, apellido, email, password, activo) VALUES (?, ?, ?, ?, ?, 1)');
            $stmt->execute([$codigo, $nombre, $apellido, $email, $hash]);
            $flash = 'Maestro creado correctamente.';
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'UNIQUE') !== false && (stripos($msg, 'profesores.codigo') !== false || stripos($msg, 'codigo') !== false)) {
                $error = 'Ese código ya está registrado.';
            } elseif (stripos($msg, 'UNIQUE') !== false && (stripos($msg, 'profesores.email') !== false || stripos($msg, 'email') !== false)) {
                $error = 'Ese email ya está registrado.';
            } else {
                $error = 'Error al crear maestro: ' . $msg;
            }
        }
    }
}

$teachers = [];
try {
    $teachers = $db->query('SELECT id, codigo, nombre, apellido, email, fecha_registro, ultimo_login, activo FROM profesores ORDER BY id DESC LIMIT 50')->fetchAll();
} catch (Throwable $e) {
    $teachers = [];
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Crear maestro</title>
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
    .topbar .inner{max-width:1100px;margin:0 auto;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px}
    .brand{color:white;text-decoration:none;font-weight:900;letter-spacing:-.4px;display:flex;align-items:center;gap:10px}
    .brand .dot{width:34px;height:34px;border-radius:12px;background:rgba(255,255,255,.14);display:grid;place-items:center}
    .nav{display:flex;gap:10px;flex-wrap:wrap}
    .pill{color:rgba(255,255,255,.92);text-decoration:none;font-weight:700;font-size:14px;padding:10px 12px;border-radius:999px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.08)}
    .pill:hover{background:rgba(255,255,255,.14)}

    .wrap{max-width:1100px;margin:0 auto;padding:18px 16px 40px}
    h1{margin:0;color:white;letter-spacing:-.6px}
    .sub{margin:6px 0 0;color:rgba(255,255,255,.82)}

    .grid{display:grid;grid-template-columns: 1fr 1.25fr;gap:12px;margin-top:14px}
    @media(max-width:980px){.grid{grid-template-columns:1fr}}

    .card{background:var(--card);border:1px solid var(--border);border-radius:18px;box-shadow:0 20px 60px rgba(0,0,0,.15);overflow:hidden}
    .pad{padding:14px}

    .form{display:grid;gap:10px}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    @media(max-width:520px){.row{grid-template-columns:1fr}}
    .label{font-size:13px;font-weight:800}
    .input{width:100%;padding:12px 12px;border-radius:14px;border:1px solid rgba(17,24,39,.18)}
    .btn{display:inline-flex;align-items:center;gap:8px;font-weight:900;font-size:13px;padding:10px 12px;border-radius:999px;border:none;cursor:pointer;color:white;background:linear-gradient(135deg,var(--primary),var(--secondary))}

    .alert{border-radius:16px;padding:12px;border:1px solid rgba(176,0,32,.25);background:rgba(176,0,32,.08);color:#b00020;font-weight:700;margin-bottom:10px}
    .ok{border-color:rgba(16,185,129,.25);background:rgba(16,185,129,.10);color:#065f46}

    table{width:100%;border-collapse:collapse}
    th,td{padding:10px 10px;border-top:1px solid rgba(17,24,39,.10);text-align:left;font-size:13px}
    th{background:rgba(17,24,39,.03);font-weight:900}
    .muted{color:var(--muted);font-weight:650}
  </style>
</head>
<body>
  <div class="topbar">
    <div class="inner">
      <a class="brand" href="teacher-dashboard.php"><span class="dot"><i class="fa-solid fa-chalkboard-user"></i></span> Docente</a>
      <div class="nav">
        <a class="pill" href="teacher-dashboard.php"><i class="fa-solid fa-arrow-left"></i> Volver</a>
        <a class="pill" href="teacher-logout.php"><i class="fa-solid fa-right-from-bracket"></i> Salir</a>
      </div>
    </div>
  </div>

  <div class="wrap">
    <h1>Crear maestro</h1>
    <p class="sub">Crea cuentas docentes nuevas para revisar y calificar proyectos.</p>

    <div class="grid">
      <div class="card">
        <div class="pad">
          <?php if ($error): ?><div class="alert" role="alert"><?php echo h($error); ?></div><?php endif; ?>
          <?php if ($flash): ?><div class="alert ok" role="status"><?php echo h($flash); ?></div><?php endif; ?>

          <form class="form" method="post" action="">
            <div class="row">
              <div>
                <label class="label" for="codigo">Código</label>
                <input class="input" id="codigo" name="codigo" value="<?php echo h($_POST['codigo'] ?? ''); ?>" required>
              </div>
              <div>
                <label class="label" for="email">Email</label>
                <input class="input" id="email" name="email" type="email" value="<?php echo h($_POST['email'] ?? ''); ?>" required>
              </div>
            </div>

            <div class="row">
              <div>
                <label class="label" for="nombre">Nombre</label>
                <input class="input" id="nombre" name="nombre" value="<?php echo h($_POST['nombre'] ?? ''); ?>" required>
              </div>
              <div>
                <label class="label" for="apellido">Apellido</label>
                <input class="input" id="apellido" name="apellido" value="<?php echo h($_POST['apellido'] ?? ''); ?>" required>
              </div>
            </div>

            <div>
              <label class="label" for="password">Contraseña</label>
              <input class="input" id="password" name="password" type="password" minlength="6" required>
            </div>

            <button class="btn" type="submit"><i class="fa-solid fa-user-plus"></i> Crear maestro</button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="pad">
          <h3 style="margin:0 0 10px;">Maestros recientes</h3>
          <?php if (!$teachers): ?>
            <div class="muted">No hay maestros para mostrar.</div>
          <?php else: ?>
            <div style="overflow:auto;">
              <table>
                <thead>
                  <tr>
                    <th>Código</th>
                    <th>Nombre</th>
                    <th>Email</th>
                    <th class="muted">Último login</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($teachers as $t): ?>
                    <tr>
                      <td><b><?php echo h($t['codigo'] ?? ''); ?></b></td>
                      <td><?php echo h(($t['apellido'] ?? '') . ', ' . ($t['nombre'] ?? '')); ?></td>
                      <td><?php echo h($t['email'] ?? ''); ?></td>
                      <td class="muted"><?php echo h($t['ultimo_login'] ?? '—'); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
