<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $codigo = trim((string)($_POST['username'] ?? ''));
  $email = trim((string)($_POST['email'] ?? ''));
  $password = (string)($_POST['password'] ?? '');
  $nombre = trim((string)($_POST['nombre'] ?? ''));
  $apellido = trim((string)($_POST['apellido'] ?? ''));
  $carrera = trim((string)($_POST['carrera'] ?? ''));
  $grado = trim((string)($_POST['grado'] ?? ''));
  $semestre = (int)($_POST['semestre'] ?? 0);

  if ($codigo === '' || $email === '' || $password === '' || $nombre === '' || $apellido === '' || $grado === '') {
    $error = 'Completa código, nombre, apellido, grado, email y contraseña.';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = 'Email inválido.';
  } elseif (strlen($password) < 6) {
    $error = 'La contraseña debe tener al menos 6 caracteres.';
  } else {
    try {
      $db = getDB();
      $hash = password_hash($password, PASSWORD_BCRYPT);

      $stmt = $db->prepare('INSERT INTO estudiantes (codigo, nombre, apellido, email, password, carrera, grado, semestre) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
      $stmt->execute([
        $codigo,
        $nombre,
        $apellido,
        $email,
        $hash,
        ($carrera !== '' ? $carrera : null),
        ($grado !== '' ? $grado : null),
        ($semestre > 0 ? $semestre : null),
      ]);

      $id = (int)$db->lastInsertId();
      $_SESSION['user'] = [
        'id' => $id,
        'codigo' => $codigo,
        'username' => $codigo,
        'nombre' => $nombre,
        'apellido' => $apellido,
        'email' => $email,
        'carrera' => ($carrera !== '' ? $carrera : null),
        'grado' => ($grado !== '' ? $grado : null),
        'semestre' => ($semestre > 0 ? $semestre : null),
        'avatar' => null,
      ];

      header('Location: dashboard.php');
      exit;
    } catch (Throwable $e) {
      // Mensajes amigables para constraints comunes
      $msg = $e->getMessage();
      if (stripos($msg, 'UNIQUE') !== false && stripos($msg, 'estudiantes.codigo') !== false) {
        $error = 'Ese código ya está registrado.';
      } elseif (stripos($msg, 'UNIQUE') !== false && stripos($msg, 'estudiantes.email') !== false) {
        $error = 'Ese email ya está registrado.';
      } else {
        $error = 'Error al registrar: ' . $msg;
      }
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Crear cuenta</title>
  <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body>
  <main class="auth-shell">
    <section class="auth-card">
      <div class="panel panel-main">
        <div class="brand">
          <a href="index.php" aria-label="Ir al inicio">EWEBLAB</a>
          <span class="badge" aria-hidden="true">＋</span>
        </div>

        <h1>Crear cuenta</h1>
        <p class="subtitle">Regístrate para crear tus sitios y comenzar a publicar.</p>

        <?php if ($error): ?>
          <div class="alert" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form class="form" method="post" action="" autocomplete="on">
          <div class="row">
            <div class="field">
              <label class="label" for="username">Código</label>
              <input class="input" id="username" name="username" autocomplete="username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
            </div>
            <div class="field">
              <label class="label" for="email">Email</label>
              <input class="input" id="email" name="email" type="email" inputmode="email" autocomplete="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
            </div>
          </div>

          <div class="row">
            <div class="field">
              <label class="label" for="nombre">Nombre</label>
              <input class="input" id="nombre" name="nombre" autocomplete="given-name" value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>" required>
            </div>
            <div class="field">
              <label class="label" for="apellido">Apellido</label>
              <input class="input" id="apellido" name="apellido" autocomplete="family-name" value="<?php echo htmlspecialchars($_POST['apellido'] ?? ''); ?>" required>
            </div>
          </div>

          <div class="field">
            <label class="label" for="password">Contraseña</label>
            <input class="input" id="password" name="password" type="password" autocomplete="new-password" minlength="6" required>
          </div>

          <div class="row">
            <div class="field">
              <label class="label" for="carrera">Carrera (opcional)</label>
              <select class="select" id="carrera" name="carrera">
                <option value="">-- Selecciona --</option>
                <?php foreach (($CARRERAS ?? []) as $c): ?>
                  <option value="<?php echo htmlspecialchars($c); ?>" <?php echo (($_POST['carrera'] ?? '') === $c) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($c); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label class="label" for="semestre">Semestre (opcional)</label>
              <input class="input" id="semestre" name="semestre" type="number" min="1" max="20" value="<?php echo htmlspecialchars($_POST['semestre'] ?? ''); ?>">
            </div>
          </div>

          <div class="field">
            <label class="label" for="grado">Grado</label>
            <select class="select" id="grado" name="grado" required>
              <option value="">-- Selecciona tu grado --</option>
              <?php foreach (($GRADOS ?? []) as $g): ?>
                <option value="<?php echo htmlspecialchars($g); ?>" <?php echo (($_POST['grado'] ?? '') === $g) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($g); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="actions">
            <button class="btn" type="submit">Registrar</button>
            <a class="link-muted" href="login.php">Ya tengo cuenta</a>
          </div>
        </form>
      </div>

      <aside class="panel panel-side">
        <p class="side-title">Al registrarte obtienes</p>
        <ul class="side-list">
          <li>Editor online para HTML/CSS/JS</li>
          <li>Gestión de sitios y páginas</li>
          <li>Enlace de compartido con URL bonita</li>
        </ul>
        <p class="side-note">Tip: usa un código corto y fácil de recordar (sin espacios).</p>
        <div style="margin-top:18px;">
          <a class="btn-secondary" href="index.php">← Volver al inicio</a>
        </div>
      </aside>
    </section>
  </main>
</body>
</html>