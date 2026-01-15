<?php
// CLI helper to debug preview routing paths.
// Usage: php tools/check_site.php <user> <site> [page]

require_once __DIR__ . '/../includes/config.php';

$user = $argv[1] ?? '';
$site = $argv[2] ?? '';
$page = $argv[3] ?? 'index';

$_GET['user'] = $user;
$_GET['site'] = $site;
$_GET['page'] = $page;

// Copy of the path-resolution part of preview.php
$identifier = trim((string)($_GET['user'] ?? ''));
$siteSlugParam = isset($_GET['site']) ? sanitizeSlug((string)$_GET['site']) : '';
$pageRoute = isset($_GET['page']) ? sanitizePageRoute((string)$_GET['page']) : 'index';
if ($pageRoute === '') $pageRoute = 'index';

$db = getDB();
$stmt = $db->prepare('SELECT id, codigo FROM estudiantes WHERE codigo = :id OR email = :id LIMIT 1');
$stmt->execute([':id' => $identifier]);
$est = $stmt->fetch();
$estudianteId = $est ? (int)$est['id'] : null;

$idFolder = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$identifier);
$dbFolder = $est ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$est['codigo']) : '';

$username = $idFolder;
if ($username === '' && $dbFolder !== '') $username = $dbFolder;
if ($idFolder !== '' && is_dir(STUDENTS_DIR . $idFolder . '/')) {
  $username = $idFolder;
} elseif ($dbFolder !== '' && is_dir(STUDENTS_DIR . $dbFolder . '/')) {
  $username = $dbFolder;
}

$rutaSitio = '';
$activeSlug = '';
$activeSiteId = null;
$useLegacy = false;

if ($estudianteId) {
  if ($siteSlugParam !== '') {
    $stmt = $db->prepare('SELECT id, url_personalizada FROM sitios_web WHERE estudiante_id = ? AND url_personalizada = ? LIMIT 1');
    $stmt->execute([$estudianteId, $siteSlugParam]);
    $siteRow = $stmt->fetch();
    if ($siteRow) {
      $activeSiteId = (int)$siteRow['id'];
      $activeSlug = (string)$siteRow['url_personalizada'];
      $rutaSitio = studentSitePath($username, $activeSlug);
    } else {
      $fallbackPath = studentSitePath($username, $siteSlugParam);
      if (is_dir($fallbackPath)) {
        $activeSlug = $siteSlugParam;
        $rutaSitio = $fallbackPath;
      }
    }
  }

  if ($rutaSitio === '') {
    $stmt = $db->prepare('SELECT id, url_personalizada FROM sitios_web WHERE estudiante_id = ? ORDER BY ultima_actualizacion DESC, id DESC LIMIT 1');
    $stmt->execute([$estudianteId]);
    $siteRow = $stmt->fetch();
    if ($siteRow) {
      $activeSiteId = (int)$siteRow['id'];
      $activeSlug = (string)$siteRow['url_personalizada'];
      if ($activeSlug !== '') $rutaSitio = studentSitePath($username, $activeSlug);
    }
  }
}

if ($rutaSitio === '') {
  $useLegacy = true;
  $rutaSitio = STUDENTS_DIR . $username . '/';
}

$pageFile = ($pageRoute === 'index') ? 'index.html' : ($pageRoute . '.html');
$full = $rutaSitio . $pageFile;

echo "identifier=$identifier\n";
echo "estudianteId=" . ($estudianteId ?? 'null') . "\n";
echo "username=$username\n";
echo "siteSlugParam=$siteSlugParam\n";
echo "activeSlug=$activeSlug\n";
echo "rutaSitio=$rutaSitio\n";
echo "pageFile=$pageFile\n";
echo "full=$full\n";
echo "is_dir(rutaSitio)=" . (is_dir($rutaSitio) ? 'yes' : 'no') . "\n";
echo "file_exists(full)=" . (file_exists($full) ? 'yes' : 'no') . "\n";
