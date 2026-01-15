<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
ensureLoggedIn();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Placeholder: handle upload
  echo 'Archivo subido (placeholder).';
  exit;
}
?>
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>Subir</title></head>
<body>
  <h2>Subir recursos</h2>
  <form method="post" enctype="multipart/form-data">
    <input type="file" name="file">
    <button type="submit">Subir</button>
  </form>
</body>
</html>