<?php
// ================================================
// actions/auth_action.php — Login / Logout handler
// ================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
startSession();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'logout') {
    logoutUser();
    setFlash('success', 'Anda telah keluar.');
    redirect(APP_URL . '/index.php?page=login');
}

if ($action === 'login' && isPost()) {
    verifyCsrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        setFlash('error', 'Username dan password wajib diisi.');
        redirect(APP_URL . '/index.php?page=login');
    }

    if (loginUser($username, $password)) {
        redirect(APP_URL . '/index.php?page=dashboard');
    } else {
        setFlash('error', 'Username atau password salah.');
        redirect(APP_URL . '/index.php?page=login');
    }
}

redirect(APP_URL . '/index.php?page=login');
