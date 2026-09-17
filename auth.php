<?php
// ================================================
// auth.php — Authentication helpers
// ================================================

require_once __DIR__ . '/config.php';

function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    static $user = null;
    if ($user === null) {
        $stmt = db()->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;
    }
    return $user;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        redirect(APP_URL . '/index.php?page=login');
    }
}

function requireAdmin(): void {
    requireLogin();
    $user = currentUser();
    if (!$user || $user['role'] !== 'admin') {
        setFlash('error', 'Akses ditolak. Halaman ini hanya untuk admin.');
        redirect(APP_URL . '/index.php?page=dashboard');
    }
}

function isAdmin(): bool {
    $user = currentUser();
    return $user && $user['role'] === 'admin';
}

function loginUser(string $username, string $password): bool {
    $stmt = db()->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_name'] = $user['full_name'];
        logActivity('login', 'Login berhasil', $user['id']);
        return true;
    }
    return false;
}

function logoutUser(): void {
    logActivity('logout', 'Logout');
    session_destroy();
    session_start();
    session_regenerate_id(true);
}
