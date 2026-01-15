<?php
require_once __DIR__ . '/config.php';

function isLoggedIn() {
  $ok = !empty($_SESSION['user']);
  if ($ok) {
    $uid = (int)($_SESSION['user']['id'] ?? 0);
    if ($uid > 0 && function_exists('markStudentLastSeen')) {
      markStudentLastSeen($uid);
    }
  }
  return $ok;
}
function ensureLoggedIn() {
  if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
  }
}

class Auth {
  public function isLoggedIn(): bool {
    return isLoggedIn();
  }

  public function ensureLoggedIn(): void {
    ensureLoggedIn();
  }

  public function user(): ?array {
    return $_SESSION['user'] ?? null;
  }

  public function logout(): void {
    unset($_SESSION['user']);
  }
}
