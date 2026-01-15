<?php
// Inicializa database/students_intranet.db ejecutando students_intranet.sql
// Úsalo desde el navegador: /dashboard/estudiantes_intranet/database/init_db.php

declare(strict_types=1);

$baseDir = dirname(__DIR__);
$dbPath = $baseDir . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'students_intranet.db';
$sqlPath = $baseDir . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'students_intranet.sql';

header('Content-Type: text/plain; charset=utf-8');

if (!file_exists($sqlPath)) {
    http_response_code(500);
    echo "No existe el esquema: {$sqlPath}\n";
    exit;
}

try {
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec('PRAGMA foreign_keys = ON;');

    $schemaSql = file_get_contents($sqlPath);
    if ($schemaSql === false) {
        throw new RuntimeException('No se pudo leer students_intranet.sql');
    }

    $pdo->beginTransaction();
    $pdo->exec($schemaSql);

    // Seed mínimo (idempotente) con passwords reales (bcrypt)
    $check = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='estudiantes'")->fetch();
    if ($check) {
        $stmt = $pdo->prepare('SELECT id FROM estudiantes WHERE codigo = ?');

        $demo = [
            ['2023001', 'Ana', 'García', 'ana@email.com', 'Ingeniería en Sistemas', 3],
            ['2023002', 'Carlos', 'López', 'carlos@email.com', 'Diseño Gráfico', 2],
            ['2023003', 'María', 'Rodríguez', 'maria@email.com', 'Comunicación Social', 4],
        ];

        foreach ($demo as $row) {
            [$codigo, $nombre, $apellido, $email, $carrera, $semestre] = $row;
            $stmt->execute([$codigo]);
            $exists = $stmt->fetch();
            if ($exists) {
                continue;
            }

            $hash = password_hash('123456', PASSWORD_BCRYPT);
            $ins = $pdo->prepare('INSERT INTO estudiantes (codigo, nombre, apellido, email, password, carrera, semestre) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $ins->execute([$codigo, $nombre, $apellido, $email, $hash, $carrera, $semestre]);
        }
    }

    $pdo->commit();

    echo "OK\n";
    echo "DB creada/actualizada: {$dbPath}\n";
    echo "Usuarios demo: codigo 2023001/2023002/2023003, password 123456\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
    exit;
}
