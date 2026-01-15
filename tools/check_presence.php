<?php
require_once __DIR__ . '/../includes/config.php';

$db = getDB();

$rows = $db->query(
    "SELECT id, codigo, nombre, apellido, last_seen_at FROM estudiantes ORDER BY (last_seen_at IS NULL) ASC, last_seen_at DESC, id DESC LIMIT 30"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $r) {
    $ls = isset($r['last_seen_at']) ? (string)$r['last_seen_at'] : '';
    echo (int)$r['id'] . "\t" . (string)$r['codigo'] . "\t" . $ls . "\n";
}
