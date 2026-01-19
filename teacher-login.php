<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/teacher_auth.php';

if (isTeacherLoggedIn()) {
  header('Location: ' . appBaseUrl() . '/teacher-dashboard.php');
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
            $stmt = $db->prepare('SELECT * FROM profesores WHERE codigo = :id OR email = :id LIMIT 1');
            $stmt->execute([':id' => $identifier]);
            $teacher = $stmt->fetch();

            if (!$teacher || empty($teacher['password']) || !password_verify($password, $teacher['password'])) {
                $error = 'Credenciales inválidas.';
            } elseif (isset($teacher['activo']) && (int)$teacher['activo'] === 0) {
                $error = 'Tu cuenta está inactiva.';
            } else {
                $_SESSION['teacher'] = [
                    'id' => (int)$teacher['id'],
                    'codigo' => (string)$teacher['codigo'],
                    'nombre' => (string)$teacher['nombre'],
                    'apellido' => (string)$teacher['apellido'],
                    'email' => (string)$teacher['email'],
                ];

                $upd = $db->prepare("UPDATE profesores SET ultimo_login = datetime('now') WHERE id = ?");
                $upd->execute([(int)$teacher['id']]);

                header('Location: ' . appBaseUrl() . '/teacher-dashboard.php');
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
  <title>Acceso docente</title>
  <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body>
  <main class="auth-shell">
    <section class="auth-card">
      <div class="panel panel-main">
        <div class="brand">
          <a href="index.php" aria-label="Ir al inicio">EWEBLAB</a>
          <span class="badge" aria-hidden="true">🎓</span>
        </div>

        <h1>Acceso docente</h1>
        <p class="subtitle">Entra como maestro para revisar proyectos, comentar y calificar.</p>

        <?php if ($error): ?>
          <div class="alert" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form class="form" method="post" action="" autocomplete="on">
          <div class="field">
            <label class="label" for="username">Usuario (código o email)</label>
            <input class="input" id="username" name="username" autocomplete="username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
          </div>

          <div class="field">
            <label class="label" for="password">Contraseña</label>
            <input class="input" id="password" name="password" type="password" autocomplete="current-password" required>
          </div>

          <div class="actions">
            <button class="btn" type="submit">Entrar</button>
            <a class="link-muted" href="login.php">Login estudiante</a>
          </div>
        </form>
      </div>

      <aside class="panel panel-side">
        <p class="side-title">Cuenta demo</p>
        <ul class="side-list">
          <li>Usuario: <b>maestro</b></li>
          <li>Contraseña: <b>maestro123</b></li>
        </ul>
        <p class="side-note">Luego podemos agregar “crear maestros” o cambiar contraseña desde un perfil docente.</p>
        <div style="margin-top:18px;">
          <a class="btn-secondary" href="index.php">← Volver al inicio</a>
        </div>
      </aside>
    </section>
  </main>
</body>
</html>
