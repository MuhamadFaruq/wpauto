<?php
// ================================================
// pages/login.php
// ================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
startSession();

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login — <?= APP_NAME ?></title>
  <meta name="description" content="Login ke WP Dashboard — Platform manajemen multi-WordPress">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="login-wrapper">
  <!-- Left Panel -->
  <div class="login-left">
    <div class="login-brand">
      <div class="brand-icon"><i class="fas fa-wordpress"></i></div>
      <div>
        <h1>WP Dashboard</h1>
        <span>Multi-Site Manager</span>
      </div>
    </div>
    <div class="login-tagline">
      <h2>Kelola Semua Situs WordPress Anda</h2>
      <p>Satu platform terpusat untuk mengelola konten dari banyak website WordPress sekaligus.</p>
    </div>
    <div class="login-features">
      <div class="login-feature">
        <i class="fas fa-globe"></i>
        <span>Kelola banyak situs WordPress dari satu dasbor</span>
      </div>
      <div class="login-feature">
        <i class="fas fa-pen-to-square"></i>
        <span>Editor artikel WYSIWYG yang lengkap</span>
      </div>
      <div class="login-feature">
        <i class="fas fa-clock"></i>
        <span>Jadwalkan posting otomatis dengan frekuensi tertentu</span>
      </div>
      <div class="login-feature">
        <i class="fas fa-users"></i>
        <span>Manajemen tim penulis dengan mapping author</span>
      </div>
      <div class="login-feature">
        <i class="fas fa-image"></i>
        <span>Upload gambar langsung ke WordPress Media Library</span>
      </div>
    </div>
  </div>

  <!-- Right Panel: Login Form -->
  <div class="login-right">
    <div class="login-form-box">
      <h3>Selamat Datang 👋</h3>
      <p class="sub">Masuk ke akun Anda untuk melanjutkan</p>

      <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?>" data-auto-hide>
        <i class="fas fa-circle-exclamation"></i> <?= sanitize($flash['message']) ?>
      </div>
      <?php endif; ?>

      <form action="actions/auth_action.php" method="POST" data-loading>
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

        <div class="form-group">
          <label for="username">Username / Email</label>
          <div class="input-icon">
            <i class="fas fa-user"></i>
            <input type="text" id="username" name="username" class="form-control"
                   placeholder="Masukkan username atau email" required autofocus autocomplete="username">
          </div>
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <div class="input-icon">
            <i class="fas fa-lock"></i>
            <input type="password" id="password" name="password" class="form-control"
                   placeholder="Masukkan password" required autocomplete="current-password">
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top:1.5rem">
          <i class="fas fa-right-to-bracket"></i> Masuk
        </button>
      </form>

      <p style="text-align:center;margin-top:1.5rem;font-size:.82rem;color:var(--text-muted)">
        Login default: <code>admin</code> / <code>admin123</code>
      </p>
    </div>
  </div>
</div>

<div id="loading-overlay" class="loading-overlay">
  <div class="spinner"></div>
  <p style="color:var(--text-muted);font-size:.875rem">Masuk...</p>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
