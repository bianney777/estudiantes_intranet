<?php
require_once __DIR__ . '/../includes/config.php';

// Usage:
//   php tools/create_teacher.php email password [codigo] [nombre] [apellido]

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$email = isset($argv[1]) ? trim((string)$argv[1]) : '';
$password = isset($argv[2]) ? (string)$argv[2] : '';
$codigo = isset($argv[3]) ? trim((string)$argv[3]) : '';
$nombre = isset($argv[4]) ? trim((string)$argv[4]) : '';
$apellido = isset($argv[5]) ? trim((string)$argv[5]) : '';

if ($email === '' || $password === '') {
    fwrite(STDERR, "Uso: php tools/create_teacher.php email password [codigo] [nombre] [apellido]\n");
    exit(1);
}

if ($codigo === '') {
    $codigo = strtolower(preg_replace('/[^a-z0-9]/i', '', strtok($email, '@')));
}
if ($codigo === '') {
    $codigo = 'maestro_' . time();
}
if ($nombre === '') {
    $nombre = ucfirst(strtolower($codigo));
}
if ($apellido === '') {
    $apellido = 'Docente';
}

$db = getDB();

// Ensure schema exists
ensureTeacherSchema($db);

$hash = password_hash($password, PASSWORD_BCRYPT);

$stmt = $db->prepare('SELECT id FROM profesores WHERE email = ? OR codigo = ? LIMIT 1');
$stmt->execute([$email, $codigo]);
$existing = $stmt->fetch();

if ($existing) {
    $upd = $db->prepare('UPDATE profesores SET password = ?, activo = 1, email = ?, codigo = ?, nombre = ?, apellido = ? WHERE id = ?');
    $upd->execute([$hash, $email, $codigo, $nombre, $apellido, (int)$existing['id']]);
    echo "UPDATED id=" . (int)$existing['id'] . " codigo=" . $codigo . " email=" . $email . "\n";
    exit(0);
}

$ins = $db->prepare('INSERT INTO profesores (codigo, nombre, apellido, email, password, activo) VALUES (?, ?, ?, ?, ?, 1)');
$ins->execute([$codigo, $nombre, $apellido, $email, $hash]);

echo "CREATED id=" . (int)$db->lastInsertId() . " codigo=" . $codigo . " email=" . $email . "\n";
