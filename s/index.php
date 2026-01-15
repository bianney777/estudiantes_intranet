<?php
// Build route from /s/{user}/{site}/{page}
$uri = (string)($_SERVER['REQUEST_URI'] ?? '');
$path = (string)(parse_url($uri, PHP_URL_PATH) ?? '');

// Extract everything AFTER the first '/s/' segment.
// Note: when this script is reached via an internal rewrite (e.g. /dashboard/s/...),
// REQUEST_URI can still include the original prefix.
if ($path === '/s' || $path === '/s/') {
    $path = '';
} elseif (str_starts_with($path, '/s/')) {
    $path = substr($path, 3);
} else {
    $pos = strpos($path, '/s/');
    if ($pos !== false) {
        $path = substr($path, $pos + 3);
    } elseif (preg_match('~/(s)$~', $path)) {
        $path = '';
    }
}
$path = trim($path, '/');

$parts = $path === '' ? [] : explode('/', $path);

if (!isset($_GET['user']) && isset($parts[0]) && $parts[0] !== '') {
    $_GET['user'] = $parts[0];
}
if (!isset($_GET['site']) && isset($parts[1]) && $parts[1] !== '') {
    $_GET['site'] = $parts[1];
}
if (!isset($_GET['page']) && isset($parts[2]) && $parts[2] !== '') {
    $_GET['page'] = $parts[2];
}

// Ensure preview.php computes base href from the project root.
// (When served via /s/index.php, SCRIPT_NAME would be /s/index.php and would break relative asset paths.)
$_SERVER['SCRIPT_NAME'] = '/s.php';

require_once __DIR__ . '/../preview.php';

// preview.php echoes the site HTML and exits.
