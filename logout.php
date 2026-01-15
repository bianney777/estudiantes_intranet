<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/teacher_auth.php';

$auth = new Auth();
$auth->logout();
teacherLogout();

// Opcional: destruir sesión completa
session_destroy();

header('Location: login.php');
exit;
