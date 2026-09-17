<?php
// ================================================
// pages/404.php — Not Found
// ================================================
require_once __DIR__ . '/../includes/layout.php';

// Use layout for logged-in users, standalone for guests
if (isLoggedIn()) {
    pageWrap('', '404 — Halaman Tidak Ditemukan', '', function() {
?>
<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;
            padding:4rem 1.5rem;text-align:center">

  <div style="font-size:7rem;font-weight:900;color:var(--navy);line-height:1;
              text-shadow:4px 4px 0 var(--navy-light);margin-bottom:.5rem">
    404
  </div>

  <div style="width:80px;height:4px;background:var(--orange);border-radius:2px;margin:0 auto 2rem"></div>

  <h2 style="font-size:1.5rem;margin-bottom:.5rem">Halaman Tidak Ditemukan</h2>
  <p style="color:var(--text-muted);max-width:380px;margin-bottom:2rem">
    Halaman yang Anda cari tidak ada atau telah dipindahkan.
    Pastikan URL sudah benar.
  </p>

  <div style="display:flex;gap:.75rem;flex-wrap:wrap;justify-content:center">
    <a href="index.php?page=dashboard" class="btn btn-orange">
      <i class="fas fa-gauge-high"></i> Ke Dashboard
    </a>
    <button onclick="history.back()" class="btn btn-outline">
      <i class="fas fa-arrow-left"></i> Kembali
    </button>
  </div>

  <!-- Quick nav links -->
  <div style="margin-top:3rem;padding-top:2rem;border-top:1px solid var(--border);width:100%;max-width:480px">
    <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:.75rem">Atau coba halaman berikut:</p>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;justify-content:center">
      <a href="index.php?page=posts"    class="btn btn-outline btn-sm"><i class="fas fa-newspaper"></i> Artikel</a>
      <a href="index.php?page=new_post" class="btn btn-outline btn-sm"><i class="fas fa-pen"></i> Tulis Baru</a>
      <a href="index.php?page=profile"  class="btn btn-outline btn-sm"><i class="fas fa-circle-user"></i> Profil</a>
    </div>
  </div>

</div>
<?php
    }); // end pageWrap

} else {
    // Standalone 404 for unauthenticated users
    $appName = APP_NAME;
    $appUrl  = APP_URL;
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>404 — <?= htmlspecialchars($appName) ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="<?= $appUrl ?>/assets/css/style.css">
  <style>
    html, body { height: 100%; margin: 0; }
    .page-404 {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      background: var(--bg);
      text-align: center;
      padding: 2rem;
    }
    .num-404 {
      font-size: 8rem;
      font-weight: 900;
      color: var(--navy);
      line-height: 1;
      text-shadow: 4px 4px 0 rgba(26,58,92,.15);
      margin-bottom: .5rem;
    }
    .divider-bar {
      width: 80px; height: 4px;
      background: var(--orange);
      border-radius: 2px;
      margin: 0 auto 2rem;
    }
  </style>
</head>
<body>
<div class="page-404">
  <div style="font-size:2.5rem;margin-bottom:1rem">
    <i class="fas fa-wordpress" style="color:var(--navy)"></i>
    <span style="font-weight:800;color:var(--navy);margin-left:.35rem"><?= htmlspecialchars($appName) ?></span>
  </div>

  <div class="num-404">404</div>
  <div class="divider-bar"></div>

  <h2 style="font-size:1.5rem;margin-bottom:.5rem">Halaman Tidak Ditemukan</h2>
  <p style="color:var(--text-muted);max-width:380px;margin:0 auto 2rem">
    Halaman yang Anda cari tidak ada atau telah dipindahkan.
  </p>

  <div style="display:flex;gap:.75rem;flex-wrap:wrap;justify-content:center">
    <a href="<?= $appUrl ?>/index.php?page=login" class="btn btn-orange">
      <i class="fas fa-right-to-bracket"></i> Login
    </a>
    <button onclick="history.back()" class="btn btn-outline">
      <i class="fas fa-arrow-left"></i> Kembali
    </button>
  </div>

  <p style="margin-top:3rem;font-size:.8rem;color:var(--text-muted)">
    &copy; <?= date('Y') ?> <?= htmlspecialchars($appName) ?>
  </p>
</div>
</body>
</html>
<?php
} // end guest
