<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
ensureLoggedIn();
$user = $_SESSION['user'] ?? [];
$studentId = (int)($user['id'] ?? 0);
$studentCode = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($user['codigo'] ?? $user['username'] ?? ''));

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = (string)($_POST['action'] ?? 'upload');
    if ($action === 'delete') {
      $imgId = (int)($_POST['image_id'] ?? 0);
      if ($imgId <= 0) {
        $error = 'Imagen inválida.';
      } else {
        $db = getDB();
        $stmt = $db->prepare('SELECT id, ruta FROM recursos WHERE id = ? AND estudiante_id = ? LIMIT 1');
        $stmt->execute([$imgId, $studentId]);
        $row = $stmt->fetch();
        if (!$row) {
          $error = 'No se encontró la imagen.';
        } else {
          $relPath = (string)$row['ruta'];
          $absPath = PROJECT_ROOT . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relPath);
          if (is_file($absPath)) {
            @unlink($absPath);
          }
          $del = $db->prepare('DELETE FROM recursos WHERE id = ? AND estudiante_id = ?');
          $del->execute([$imgId, $studentId]);
          $success = 'Imagen eliminada.';
        }
      }
    } else {
      if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        $error = 'Selecciona una imagen.';
      } else {
        $file = $_FILES['file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
          $error = 'Error al subir el archivo.';
        } else {
          $maxBytes = 5 * 1024 * 1024; // 5MB
          if (($file['size'] ?? 0) > $maxBytes) {
            $error = 'La imagen supera 5MB.';
          } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            $allowed = [
              'image/jpeg' => 'jpg',
              'image/png' => 'png',
              'image/gif' => 'gif',
              'image/webp' => 'webp',
            ];
            if (!isset($allowed[$mime])) {
              $error = 'Formato no permitido. Usa JPG, PNG, GIF o WEBP.';
            } else {
              $ext = $allowed[$mime];
              $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '', pathinfo((string)($file['name'] ?? ''), PATHINFO_FILENAME));
              if ($safeName === '') {
                $safeName = 'imagen';
              }
              $fileName = $safeName . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
              $subdir = $studentCode !== '' ? $studentCode : ('user-' . $studentId);
              $destDir = rtrim(UPLOAD_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $subdir . DIRECTORY_SEPARATOR;
              if (!is_dir($destDir)) {
                mkdir($destDir, 0777, true);
              }
              $destPath = $destDir . $fileName;
              if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                $error = 'No se pudo guardar la imagen.';
              } else {
                $relPath = 'uploads/' . $subdir . '/' . $fileName;
                try {
                  $db = getDB();
                  $ins = $db->prepare('INSERT INTO recursos (estudiante_id, nombre, tipo, ruta, tamanio, fecha_subida) VALUES (?, ?, ?, ?, ?, datetime("now"))');
                  $ins->execute([$studentId, $fileName, $mime, $relPath, (int)($file['size'] ?? 0)]);
                } catch (Throwable $e) {
                  // Si falla la BD, igual mostramos la imagen guardada.
                }
                $success = 'Imagen subida correctamente.';
              }
            }
          }
        }
      }
    }
  } catch (Throwable $e) {
    $error = 'Error inesperado al subir.';
  }
}

// Cargar imágenes del alumno
$images = [];
try {
  $db = getDB();
  $stmt = $db->prepare('SELECT id, nombre, ruta, tipo, fecha_subida FROM recursos WHERE estudiante_id = ? ORDER BY id DESC');
  $stmt->execute([$studentId]);
  $images = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
  $images = [];
}

function h($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Subir imágenes</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;margin:0;background:#f5f7fb;color:#1f2937}
    .wrap{max-width:1100px;margin:0 auto;padding:24px 16px 48px}
    h2{margin:0 0 10px}
    .card{background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:14px;padding:16px;box-shadow:0 10px 30px rgba(0,0,0,.06)}
    .row{display:grid;grid-template-columns:1fr;gap:14px}
    .alert{padding:10px 12px;border-radius:10px;font-weight:700}
    .alert.ok{background:#e7f8ee;color:#0f5132;border:1px solid #b7e4c7}
    .alert.err{background:#fdecea;color:#b00020;border:1px solid #f5c2c7}
    .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-top:14px}
    .img-card{border:1px solid rgba(0,0,0,.08);border-radius:12px;overflow:hidden;background:#fff}
    .img-card img{width:100%;height:140px;object-fit:cover;display:block}
    .img-meta{padding:8px 10px;font-size:12px;color:#6b7280}
    .actions{margin-top:10px;display:flex;gap:10px;flex-wrap:wrap}
    .btn{padding:10px 14px;border-radius:10px;border:1px solid rgba(0,0,0,.12);background:#fff;font-weight:700;cursor:pointer}
    .file-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
    .file-input{position:absolute;left:-9999px;opacity:0}
    .file-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;border:1px dashed rgba(0,0,0,.2);background:#f8fafc;font-weight:800;cursor:pointer}
    .file-name{font-size:13px;color:#6b7280;min-height:18px}
    .modal{position:fixed;inset:0;background:rgba(0,0,0,.75);display:none;align-items:center;justify-content:center;z-index:50}
    .modal.open{display:flex}
    .modal-content{max-width:92vw;max-height:86vh;background:#111;border-radius:12px;overflow:hidden;position:relative}
    .modal-content img{display:block;max-width:92vw;max-height:86vh}
    .modal-close{position:absolute;top:8px;right:10px;background:rgba(0,0,0,.7);color:#fff;border:none;border-radius:999px;padding:6px 10px;cursor:pointer;font-weight:700}
  </style>
</head>
<body>
  <div class="wrap">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
      <h2>Subir imágenes</h2>
      <a class="btn" href="dashboard.php">← Volver al dashboard</a>
    </div>
    <p style="color:#6b7280;margin:0 0 14px;">Formatos permitidos: JPG, PNG, GIF, WEBP (máx. 5MB).</p>

    <div class="card">
      <?php if ($success): ?><div class="alert ok"><?php echo h($success); ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert err"><?php echo h($error); ?></div><?php endif; ?>

      <form method="post" enctype="multipart/form-data" class="row" id="uploadForm">
        <input type="hidden" name="action" value="upload">
        <div class="file-row">
          <input class="file-input" id="fileInput" type="file" name="file" accept="image/*" required>
          <label class="file-btn" for="fileInput">📷 Elegir imagen</label>
          <span class="file-name" id="fileName">Ningún archivo seleccionado</span>
        </div>
        <div class="actions">
          <button class="btn" type="submit">Subir</button>
        </div>
      </form>
    </div>

    <div style="margin-top:18px;">
      <h3 style="margin:0 0 8px;">Tus imágenes</h3>
      <?php if (empty($images)): ?>
        <p style="color:#6b7280;">Aún no hay imágenes subidas.</p>
      <?php else: ?>
        <div class="grid">
          <?php foreach ($images as $img): ?>
            <?php $url = (string)($img['ruta'] ?? ''); ?>
            <div class="img-card">
              <a href="<?php echo h($url); ?>" class="img-open" data-src="<?php echo h($url); ?>" aria-label="Ver imagen">
                <img src="<?php echo h($url); ?>" alt="<?php echo h($img['nombre'] ?? 'imagen'); ?>">
              </a>
              <div class="img-meta">
                <?php echo h($img['nombre'] ?? ''); ?>
                <div><?php echo h($img['fecha_subida'] ?? ''); ?></div>
                <form method="post" style="margin-top:8px;">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="image_id" value="<?php echo (int)($img['id'] ?? 0); ?>">
                  <button class="btn" type="submit" onclick="return confirm('¿Eliminar esta imagen?');">Eliminar</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="modal" id="imgModal" aria-hidden="true">
    <div class="modal-content" role="dialog" aria-modal="true">
      <button class="modal-close" type="button" id="imgModalClose">✕</button>
      <img id="imgModalImg" src="" alt="Imagen">
    </div>
  </div>

  <script>
    (function() {
      const modal = document.getElementById('imgModal');
      const modalImg = document.getElementById('imgModalImg');
      const closeBtn = document.getElementById('imgModalClose');

      document.querySelectorAll('.img-open').forEach(link => {
        link.addEventListener('click', (e) => {
          e.preventDefault();
          const src = link.getAttribute('data-src');
          if (!src) return;
          modalImg.src = src;
          modal.classList.add('open');
          modal.setAttribute('aria-hidden', 'false');
        });
      });

      function closeModal() {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        modalImg.src = '';
      }

      closeBtn.addEventListener('click', closeModal);
      modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
      });
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeModal();
      });
    })();
    (function() {
      const fileInput = document.getElementById('fileInput');
      const fileName = document.getElementById('fileName');
      if (!fileInput || !fileName) return;
      fileInput.addEventListener('change', () => {
        const name = fileInput.files && fileInput.files[0] ? fileInput.files[0].name : 'Ningún archivo seleccionado';
        fileName.textContent = name;
      });
    })();
  </script>
</body>
</html>