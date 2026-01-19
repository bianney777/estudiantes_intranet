<?php
require_once __DIR__ . '/config.php';

function isTeacherLoggedIn(): bool {
    return !empty($_SESSION['teacher']);
}

function ensureTeacherLoggedIn(): void {
    if (!isTeacherLoggedIn()) {
        header('Location: ' . appBaseUrl() . '/teacher-login.php');
        exit;
    }
}

function teacherUser(): ?array {
    return $_SESSION['teacher'] ?? null;
}

function teacherLogout(): void {
    unset($_SESSION['teacher']);
}
