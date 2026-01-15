<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
ensureLoggedIn();

$db = getDB();
$sessionUser = $_SESSION['user'];
$studentId = (int)($sessionUser['id'] ?? 0);
if ($studentId <= 0) {
    header('Location: logout.php');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

$error = null;
$success = null;

$AVATAR_PRESETS = [
  'assets/images/avatars/anime-01.svg' => 'Anime 01',
  'assets/images/avatars/anime-02.svg' => 'Anime 02',
  'assets/images/avatars/anime-03.svg' => 'Anime 03',
  'assets/images/avatars/anime-04.svg' => 'Anime 04',
];

function refreshSessionUser(PDO $db, int $studentId): void {
  $stmt = $db->prepare('SELECT id, codigo, nombre, apellido, email, carrera, semestre, grado, avatar FROM estudiantes WHERE id = ? LIMIT 1');
    $stmt->execute([$studentId]);
    $s = $stmt->fetch();
    if (!$s) {
        unset($_SESSION['user']);
        return;
    }

    $_SESSION['user'] = [
        'id' => (int)$s['id'],
        'codigo' => (string)$s['codigo'],
        'username' => (string)$s['codigo'],
        'nombre' => (string)$s['nombre'],
        'apellido' => (string)$s['apellido'],
        'email' => (string)$s['email'],
        'carrera' => $s['carrera'] ?? null,
        'semestre' => $s['semestre'] ?? null,
      'grado' => $s['grado'] ?? null,
        'avatar' => $s['avatar'] ?? null,
    ];
}

// Procesar acciones
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $postedCsrf = (string)($_POST['csrf'] ?? '');
    if (!hash_equals($csrf, $postedCsrf)) {
        http_response_code(400);
        $error = 'Solicitud inválida (CSRF). Recarga la página.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'update_profile') {
                $nombre = trim((string)($_POST['nombre'] ?? ''));
                $apellido = trim((string)($_POST['apellido'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));
                $carrera = trim((string)($_POST['carrera'] ?? ''));
                $semestreRaw = (string)($_POST['semestre'] ?? '');
                $semestre = $semestreRaw !== '' ? (int)$semestreRaw : null;
              $grado = trim((string)($_POST['grado'] ?? ''));

                if ($nombre === '' || $apellido === '' || $email === '') {
                    throw new RuntimeException('Nombre, apellido y email son obligatorios.');
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Email inválido.');
                }
                if ($semestre !== null && ($semestre < 1 || $semestre > 20)) {
                    throw new RuntimeException('Semestre inválido (1-20).');
                }

                // Validar carrera si fue enviada
                if ($carrera !== '' && !in_array($carrera, $CARRERAS ?? [], true)) {
                    throw new RuntimeException('Carrera inválida.');
                }

                // Validar grado si fue enviado
                if ($grado !== '' && !in_array($grado, $GRADOS ?? [], true)) {
                  throw new RuntimeException('Grado inválido.');
                }

                $stmt = $db->prepare('UPDATE estudiantes SET nombre = ?, apellido = ?, email = ?, carrera = ?, semestre = ?, grado = ? WHERE id = ?');
                $stmt->execute([
                    $nombre,
                    $apellido,
                    $email,
                    ($carrera !== '' ? $carrera : null),
                    $semestre,
                  ($grado !== '' ? $grado : null),
                  $studentId
                ]);
                refreshSessionUser($db, $studentId);
                $success = 'Perfil actualizado.';
            } elseif ($action === 'change_password') {
                $current = (string)($_POST['current_password'] ?? '');
                $new = (string)($_POST['new_password'] ?? '');
                $confirm = (string)($_POST['confirm_password'] ?? '');

                if ($current === '' || $new === '' || $confirm === '') {
                    throw new RuntimeException('Completa todos los campos de contraseña.');
                }
                if ($new !== $confirm) {
                    throw new RuntimeException('La nueva contraseña no coincide.');
                }
                if (strlen($new) < 6) {
                    throw new RuntimeException('La nueva contraseña debe tener al menos 6 caracteres.');
                }

                $stmt = $db->prepare('SELECT password FROM estudiantes WHERE id = ? LIMIT 1');
                $stmt->execute([$studentId]);
                $row = $stmt->fetch();
                if (!$row || empty($row['password']) || !password_verify($current, $row['password'])) {
                    throw new RuntimeException('Tu contraseña actual no es correcta.');
                }

                $hash = password_hash($new, PASSWORD_BCRYPT);
                $upd = $db->prepare('UPDATE estudiantes SET password = ? WHERE id = ?');
                $upd->execute([$hash, $studentId]);
                $success = 'Contraseña actualizada.';
            } elseif ($action === 'upload_avatar') {
                if (!isset($_FILES['avatar']) || !is_array($_FILES['avatar'])) {
                    throw new RuntimeException('No se recibió archivo.');
                }

                $file = $_FILES['avatar'];
                if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Error al subir el archivo.');
                }

                $maxBytes = 3 * 1024 * 1024; // 3MB
                if (($file['size'] ?? 0) > $maxBytes) {
                    throw new RuntimeException('El avatar debe pesar máximo 3MB.');
                }

                $tmp = (string)$file['tmp_name'];
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($tmp);
                $allowed = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    'image/gif' => 'gif',
                ];
                if (!isset($allowed[$mime])) {
                    throw new RuntimeException('Formato no permitido. Usa JPG, PNG, WEBP o GIF.');
                }

                $imgInfo = @getimagesize($tmp);
                if ($imgInfo === false) {
                    throw new RuntimeException('El archivo no parece una imagen válida.');
                }

                $avatarsDir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR;
                if (!file_exists($avatarsDir)) {
                    mkdir($avatarsDir, 0777, true);
                }

                $ext = $allowed[$mime];
                $filename = 'avatar_' . $studentId . '_' . time() . '.' . $ext;
                $targetAbs = $avatarsDir . $filename;
                if (!move_uploaded_file($tmp, $targetAbs)) {
                    throw new RuntimeException('No se pudo guardar el avatar.');
                }

                // Guardar ruta relativa para usar en HTML
                $relative = 'uploads/avatars/' . $filename;
                $stmt = $db->prepare('UPDATE estudiantes SET avatar = ? WHERE id = ?');
                $stmt->execute([$relative, $studentId]);
                refreshSessionUser($db, $studentId);
                $success = 'Avatar actualizado.';
              } elseif ($action === 'select_avatar_preset') {
                $preset = (string)($_POST['preset'] ?? '');
                if ($preset === '' || !array_key_exists($preset, $AVATAR_PRESETS)) {
                  throw new RuntimeException('Selecciona un avatar válido.');
                }
                $stmt = $db->prepare('UPDATE estudiantes SET avatar = ? WHERE id = ?');
                $stmt->execute([$preset, $studentId]);
                refreshSessionUser($db, $studentId);
                $success = 'Avatar seleccionado.';
            }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            // Manejar UNIQUE(email)
            if (stripos($msg, 'UNIQUE') !== false && stripos($msg, 'estudiantes.email') !== false) {
                $error = 'Ese email ya está registrado.';
            } else {
                $error = $msg;
            }
        }
    }
}

// Cargar datos actuales
$stmt = $db->prepare('SELECT id, codigo, nombre, apellido, email, carrera, semestre, grado, avatar, fecha_registro, ultimo_login FROM estudiantes WHERE id = ? LIMIT 1');
$stmt->execute([$studentId]);
$student = $stmt->fetch();
if (!$student) {
    header('Location: logout.php');
    exit;
}

$avatarUrl = !empty($student['avatar']) ? (string)$student['avatar'] : '';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Perfil - <?php echo SITE_NAME; ?></title>
  <style>
    :root{
      --bg:#0b1020;
      --border:rgba(255,255,255,.10);
      --text:#e8ecff;
      --muted:#b5bddc;
      --primary:#667eea;
      --primary2:#764ba2;
      --ok:#28a745;
      --danger:#ff5a6a;
    }
    *{box-sizing:border-box}
    body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:radial-gradient(1200px 600px at 20% -10%, rgba(102,126,234,.35), transparent),radial-gradient(1200px 600px at 80% 10%, rgba(118,75,162,.35), transparent),var(--bg);color:var(--text)}
    .wrap{max-width:1100px;margin:0 auto;padding:28px 18px 48px}
    .top{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:18px;flex-wrap:wrap}
    .title h1{font-size:22px;margin:0}
    .title p{margin:6px 0 0;color:var(--muted);font-size:13px}
    .actions{display:flex;gap:10px;flex-wrap:wrap}
    .btn{border:1px solid var(--border);background:rgba(255,255,255,.06);color:var(--text);padding:10px 14px;border-radius:12px;text-decoration:none;font-weight:600;font-size:14px;display:inline-flex;align-items:center;gap:10px;transition:transform .08s ease, background .2s ease}
    .btn:hover{background:rgba(255,255,255,.10)}
    .btn:active{transform:translateY(1px)}
    .btn.primary{border:none;background:linear-gradient(135deg,var(--primary),var(--primary2))}
    .grid{display:grid;grid-template-columns:.9fr 1.1fr;gap:16px}
    .card{background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03));border:1px solid var(--border);border-radius:18px;padding:18px}
    .card h2{margin:0 0 10px;font-size:16px}
    .muted{color:var(--muted);font-size:13px;line-height:1.5}
    .banner{margin:12px 0 0;padding:12px 14px;border-radius:14px;border:1px solid var(--border)}
    .banner.ok{background:rgba(40,167,69,.12);border-color:rgba(40,167,69,.35)}
    .banner.err{background:rgba(255,90,106,.10);border-color:rgba(255,90,106,.35)}
    .form{display:grid;gap:12px;margin-top:12px}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    label{display:grid;gap:6px;font-size:13px;color:var(--muted)}
    input,select{width:100%;padding:12px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(10,16,33,.65);color:var(--text);outline:none}
    .hint{font-size:12px;color:rgba(181,189,220,.85)}
    .avatar{display:flex;gap:14px;align-items:center}
    .avatar img{width:72px;height:72px;border-radius:18px;object-fit:cover;border:1px solid rgba(255,255,255,.18);background:rgba(0,0,0,.25)}
    .pill{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;border:1px solid var(--border);background:rgba(255,255,255,.05);font-size:12px;color:var(--muted)}
    .actions-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .btn.ok{border:none;background:linear-gradient(135deg,#2ecc71,#1aa34a)}
    @media (max-width: 980px){
      .grid{grid-template-columns:1fr}
      .row{grid-template-columns:1fr}
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <div class="title">
        <h1>Perfil</h1>
        <p>Administra tu información y seguridad.</p>
      </div>
      <div class="actions">
        <a class="btn" href="dashboard.php">← Inicio</a>
        <a class="btn" href="my-website.php">🌐 Mi sitio</a>
        <a class="btn primary" href="logout.php">🚪 Cerrar sesión</a>
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
        <h2>Tu cuenta</h2>
        <div class="avatar">
          <?php if ($avatarUrl): ?>
            <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="Avatar">
          <?php else: ?>
            <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120'%3E%3Crect width='120' height='120' fill='%23111a33'/%3E%3Ctext x='50%25' y='54%25' dominant-baseline='middle' text-anchor='middle' fill='%23b5bddc' font-size='54' font-family='Arial'%3E%3C/text%3E%3C/svg%3E" alt="Avatar">
          <?php endif; ?>
          <div>
            <div style="font-weight:800;font-size:16px;line-height:1.1;">
              <?php echo htmlspecialchars($student['nombre'] . ' ' . $student['apellido']); ?>
            </div>
            <div class="muted" style="margin-top:6px;">
              <span class="pill">Código: <?php echo htmlspecialchars($student['codigo']); ?></span>
              <?php if (!empty($student['grado'])): ?>
                <span class="pill">Grado: <?php echo htmlspecialchars((string)$student['grado']); ?></span>
              <?php endif; ?>
            </div>
            <div class="muted" style="margin-top:8px;">
              <div>Registrado: <?php echo htmlspecialchars((string)$student['fecha_registro']); ?></div>
              <div>Último login: <?php echo htmlspecialchars((string)($student['ultimo_login'] ?? '—')); ?></div>
            </div>
          </div>
        </div>

        <form class="form" method="post" enctype="multipart/form-data" style="margin-top:14px;">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
          <input type="hidden" name="action" value="upload_avatar">
          <label>
            Cambiar avatar
            <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp,image/gif">
            <span class="hint">Máximo 3MB. Formatos: JPG/PNG/WEBP/GIF.</span>
          </label>
          <div class="actions-row">
            <button class="btn ok" type="submit">📷 Subir avatar</button>
          </div>
        </form>

        <div style="height:14px"></div>

        <h2>Elegir avatar (estilo anime)</h2>
        <div class="muted">Son avatares originales en SVG (no son personajes de series).</div>

        <form class="form" method="post">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
          <input type="hidden" name="action" value="select_avatar_preset">

          <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;">
            <?php foreach ($AVATAR_PRESETS as $path => $label): ?>
              <label style="border:1px solid rgba(255,255,255,.14);background:rgba(10,16,33,.45);padding:12px;border-radius:14px;cursor:pointer;">
                <div style="display:flex;gap:12px;align-items:center;">
                  <img src="<?php echo htmlspecialchars($path); ?>" alt="<?php echo htmlspecialchars($label); ?>" style="width:56px;height:56px;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(0,0,0,.25);object-fit:cover;">
                  <div style="flex:1;">
                    <div style="font-weight:700;color:var(--text);font-size:13px;"><?php echo htmlspecialchars($label); ?></div>
                    <div class="muted" style="margin-top:4px;font-size:12px;">Seleccionar</div>
                  </div>
                  <input type="radio" name="preset" value="<?php echo htmlspecialchars($path); ?>" <?php echo ($avatarUrl === $path) ? 'checked' : ''; ?> />
                </div>
              </label>
            <?php endforeach; ?>
          </div>

          <div class="actions-row" style="margin-top:10px;">
            <button class="btn ok" type="submit">✅ Usar este avatar</button>
          </div>
        </form>
      </div>

      <div class="card">
        <h2>Información</h2>
        <div class="muted">Actualiza tus datos para mantener tu perfil al día.</div>

        <form class="form" method="post">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
          <input type="hidden" name="action" value="update_profile">

          <div class="row">
            <label>
              Nombre
              <input name="nombre" value="<?php echo htmlspecialchars((string)($student['nombre'] ?? '')); ?>">
            </label>
            <label>
              Apellido
              <input name="apellido" value="<?php echo htmlspecialchars((string)($student['apellido'] ?? '')); ?>">
            </label>
          </div>

          <div class="row">
            <label>
              Email
              <input name="email" type="email" value="<?php echo htmlspecialchars((string)($student['email'] ?? '')); ?>">
            </label>
            <label>
              Semestre
              <input name="semestre" type="number" min="1" max="20" value="<?php echo htmlspecialchars((string)($student['semestre'] ?? '')); ?>">
            </label>
          </div>

          <div class="row">
            <label>
              Grado
              <select name="grado">
                <option value="">-- Selecciona --</option>
                <?php foreach (($GRADOS ?? []) as $g): ?>
                  <option value="<?php echo htmlspecialchars($g); ?>" <?php echo (($student['grado'] ?? '') === $g) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($g); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>
              Carrera
              <select name="carrera">
                <option value="">-- Selecciona --</option>
                <?php foreach (($CARRERAS ?? []) as $c): ?>
                  <option value="<?php echo htmlspecialchars($c); ?>" <?php echo (($student['carrera'] ?? '') === $c) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($c); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>

          <div class="actions-row">
            <button class="btn ok" type="submit">✅ Guardar cambios</button>
          </div>
        </form>

        <div style="height:14px"></div>

        <h2>Seguridad</h2>
        <div class="muted">Cambia tu contraseña cuando lo necesites.</div>

        <form class="form" method="post">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
          <input type="hidden" name="action" value="change_password">

          <label>
            Contraseña actual
            <input name="current_password" type="password" autocomplete="current-password">
          </label>
          <div class="row">
            <label>
              Nueva contraseña
              <input name="new_password" type="password" autocomplete="new-password">
            </label>
            <label>
              Confirmar
              <input name="confirm_password" type="password" autocomplete="new-password">
            </label>
          </div>
          <div class="actions-row">
            <button class="btn ok" type="submit">🔐 Cambiar contraseña</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</body>
</html>