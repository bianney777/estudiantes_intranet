<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
if (isLoggedIn()) {
  header('Location: dashboard.php');
  exit;
}

$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $identifier = trim((string)($_POST['username'] ?? ''));
  $password = (string)($_POST['password'] ?? '');

  if ($identifier === '' || $password === '') {
    $error = 'Completa usuario y contraseña.';
  } else {
    try {
      $db = getDB();
      $stmt = $db->prepare('SELECT * FROM estudiantes WHERE codigo = :id OR email = :id LIMIT 1');
      $stmt->execute([':id' => $identifier]);
      $student = $stmt->fetch();

      if (!$student || empty($student['password']) || !password_verify($password, $student['password'])) {
        $error = 'Credenciales inválidas.';
      } elseif (isset($student['activo']) && (int)$student['activo'] === 0) {
        $error = 'Tu cuenta está inactiva.';
      } else {
        // Guardar sesión (compatibilidad: username es el código)
        $_SESSION['user'] = [
          'id' => (int)$student['id'],
          'codigo' => (string)$student['codigo'],
          'username' => (string)$student['codigo'],
          'nombre' => (string)$student['nombre'],
          'apellido' => (string)$student['apellido'],
          'email' => (string)$student['email'],
          'carrera' => $student['carrera'] ?? null,
          'semestre' => $student['semestre'] ?? null,
          'grado' => $student['grado'] ?? null,
          'avatar' => $student['avatar'] ?? null,
        ];

        $upd = $db->prepare("UPDATE estudiantes SET ultimo_login = datetime('now') WHERE id = ?");
        $upd->execute([(int)$student['id']]);

        header('Location: dashboard.php');
        exit;
      }
    } catch (Throwable $e) {
      $error = 'Error de servidor: ' . $e->getMessage();
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Iniciar sesión</title>
  <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body>
  <main class="auth-shell">
    <section class="auth-card">
      <div class="panel panel-main">
        <div class="brand">
          <a href="index.php" aria-label="Ir al inicio">EWEBLAB</a>
          <span class="badge" aria-hidden="true">↩</span>
        </div>

        <h1>Iniciar sesión</h1>
        <p class="subtitle">Accede con tu código o email para editar y publicar tus sitios.</p>

        <?php if ($error): ?>
          <div class="alert" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form class="form" method="post" action="" autocomplete="on">
          <div class="field">
            <label class="label" for="username">Usuario (código o email)</label>
            <input class="input" id="username" name="username" inputmode="email" autocomplete="username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
          </div>

          <div class="field">
            <label class="label" for="password">Contraseña</label>
            <input class="input" id="password" name="password" type="password" autocomplete="current-password" required>
          </div>

          <div class="actions">
            <button class="btn" type="submit">Entrar</button>
            <a class="link-muted" href="register.php">Crear cuenta</a>
          </div>
        </form>
      </div>

      <aside class="panel panel-side">
        <p class="side-title">Tu espacio de publicación</p>
        <ul class="side-list">
          <li>Múltiples sitios por estudiante</li>
          <li>Múltiples páginas por sitio</li>
          <li>Vista previa con URL bonita</li>
        </ul>
        <p class="side-note">Si estás en laboratorio/red interna, usa la URL que te dio el profesor/servidor.</p>
        <div style="margin-top:18px;">
          <a class="btn-secondary" href="index.php">← Volver al inicio</a>
        </div>
      </aside>
    </section>
  </main>
</body>
</html>