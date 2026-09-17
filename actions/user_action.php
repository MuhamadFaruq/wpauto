<?php
// ================================================
// actions/user_action.php — User management
// ================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
startSession();
requireLogin();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ---- Add User (Admin only) ----
if ($action === 'add' && isPost()) {
    requireAdmin();
    verifyCsrf();

    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';
    $role     = in_array($_POST['role'] ?? '', ['admin','writer']) ? $_POST['role'] : 'writer';
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if (!$fullName || !$username || !$email || !$password) {
        setFlash('error', 'Semua field wajib diisi.'); redirect(APP_URL . '/index.php?page=users');
    }
    if ($password !== $confirm) {
        setFlash('error', 'Konfirmasi password tidak cocok.'); redirect(APP_URL . '/index.php?page=users');
    }
    if (strlen($password) < 6) {
        setFlash('error', 'Password minimal 6 karakter.'); redirect(APP_URL . '/index.php?page=users');
    }

    // Check unique
    $check = db()->prepare("SELECT id FROM users WHERE username=? OR email=?");
    $check->execute([$username, $email]);
    if ($check->fetch()) {
        setFlash('error', 'Username atau email sudah digunakan.'); redirect(APP_URL . '/index.php?page=users');
    }

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    db()->prepare(
        "INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (?,?,?,?,?,?)"
    )->execute([$username, $email, $hash, $fullName, $role, $isActive]);

    logActivity('user_add', "User baru: {$username} ({$role})");
    setFlash('success', "User \"{$fullName}\" berhasil ditambahkan.");
    redirect(APP_URL . '/index.php?page=users');
}

// ---- Edit User (Admin only) ----
if ($action === 'edit' && isPost()) {
    requireAdmin();
    verifyCsrf();

    $id       = (int)($_POST['user_id'] ?? 0);
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';
    $role     = in_array($_POST['role'] ?? '', ['admin','writer']) ? $_POST['role'] : 'writer';
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if (!$id || !$fullName || !$username || !$email) {
        setFlash('error', 'Data tidak valid.'); redirect(APP_URL . '/index.php?page=users');
    }
    if ($password && $password !== $confirm) {
        setFlash('error', 'Konfirmasi password tidak cocok.'); redirect(APP_URL . '/index.php?page=users');
    }

    if ($password) {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        db()->prepare(
            "UPDATE users SET full_name=?,username=?,email=?,password_hash=?,role=?,is_active=? WHERE id=?"
        )->execute([$fullName, $username, $email, $hash, $role, $isActive, $id]);
    } else {
        db()->prepare(
            "UPDATE users SET full_name=?,username=?,email=?,role=?,is_active=? WHERE id=?"
        )->execute([$fullName, $username, $email, $role, $isActive, $id]);
    }

    logActivity('user_edit', "User diedit: {$username}");
    setFlash('success', "User \"{$fullName}\" berhasil diperbarui.");
    redirect(APP_URL . '/index.php?page=users');
}

// ---- Delete User (Admin only) ----
if ($action === 'delete' && isPost()) {
    requireAdmin();
    verifyCsrf();
    $id = (int)($_POST['user_id'] ?? 0);

    // Cannot delete self
    if ($id === (int)currentUser()['id']) {
        setFlash('error', 'Tidak dapat menghapus akun sendiri.'); redirect(APP_URL . '/index.php?page=users');
    }

    $stmt = db()->prepare("SELECT username FROM users WHERE id=?"); $stmt->execute([$id]);
    $username = $stmt->fetchColumn();
    db()->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
    logActivity('user_delete', "User dihapus: {$username}");
    setFlash('success', 'User berhasil dihapus.');
    redirect(APP_URL . '/index.php?page=users');
}

// ---- Toggle Active (Admin only) ----
if ($action === 'toggle_active' && isPost()) {
    requireAdmin();
    verifyCsrf();
    $id = (int)($_POST['user_id'] ?? 0);
    $stmt = db()->prepare("SELECT is_active, username FROM users WHERE id=?"); $stmt->execute([$id]);
    $user = $stmt->fetch();
    if ($user) {
        $new = $user['is_active'] ? 0 : 1;
        db()->prepare("UPDATE users SET is_active=? WHERE id=?")->execute([$new, $id]);
        $status = $new ? 'diaktifkan' : 'dinonaktifkan';
        logActivity('user_toggle', "User {$user['username']} {$status}");
        setFlash('success', "User berhasil {$status}.");
    }
    redirect(APP_URL . '/index.php?page=users');
}

// ---- Update Profile (any user) ----
if ($action === 'update_profile' && isPost()) {
    verifyCsrf();
    $me       = currentUser();
    $fullName = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $avatarUrl= trim($_POST['avatar_url'] ?? '');

    if (!$fullName || !$email) {
        setFlash('error', 'Nama dan email wajib diisi.'); redirect(APP_URL . '/index.php?page=profile');
    }

    db()->prepare(
        "UPDATE users SET full_name=?,email=?,avatar_url=? WHERE id=?"
    )->execute([$fullName, $email, $avatarUrl ?: null, $me['id']]);

    // Update session name
    $_SESSION['user_name'] = $fullName;
    setFlash('success', 'Profil berhasil diperbarui.');
    redirect(APP_URL . '/index.php?page=profile');
}

// ---- Change Password (any user) ----
if ($action === 'change_password' && isPost()) {
    verifyCsrf();
    $me      = currentUser();
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!password_verify($current, $me['password_hash'])) {
        setFlash('error', 'Password lama tidak benar.'); redirect(APP_URL . '/index.php?page=profile');
    }
    if ($new !== $confirm) {
        setFlash('error', 'Konfirmasi password tidak cocok.'); redirect(APP_URL . '/index.php?page=profile');
    }
    if (strlen($new) < 6) {
        setFlash('error', 'Password baru minimal 6 karakter.'); redirect(APP_URL . '/index.php?page=profile');
    }

    $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
    db()->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash, $me['id']]);
    logActivity('change_password', 'Password diubah');
    setFlash('success', 'Password berhasil diubah.');
    redirect(APP_URL . '/index.php?page=profile');
}

// ---- Save Settings (Admin only) ----
if ($action === 'save_settings' && isPost()) {
    requireAdmin();
    verifyCsrf();

    $db = db();
    $keys = [
        'app_name',
        'timezone',
        'posts_per_page',
        'default_post_status',
        'ai_api_url',
        'ai_api_key',
        'ai_model',
        'ai_basic_user',
        'ai_basic_pass',
        'pexels_api_key'
    ];

    $stmt = $db->prepare("INSERT INTO app_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value`=?");

    foreach ($keys as $key) {
        $val = isset($_POST[$key]) ? trim($_POST[$key]) : '';
        $stmt->execute([$key, $val, $val]);
    }

    logActivity('settings_save', 'Pengaturan global diperbarui');
    setFlash('success', 'Pengaturan berhasil disimpan.');
    redirect(APP_URL . '/index.php?page=settings');
}

redirect(APP_URL . '/index.php?page=dashboard');
