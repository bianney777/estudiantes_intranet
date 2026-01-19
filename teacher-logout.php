<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/teacher_auth.php';

teacherLogout();

// Si también había sesión de estudiante, la dejamos intacta.
// Si quieres cerrar TODO, usa logout.php.

header('Location: ' . appBaseUrl() . '/teacher-login.php');
exit;
