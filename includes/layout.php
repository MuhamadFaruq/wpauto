<?php
// ================================================
// includes/layout.php — Shared Layout Helpers
// ================================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

function renderHead(string $title = 'Dashboard'): void {
    $appName = APP_NAME;
    echo <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$title} — {$appName}</title>
  <meta name="description" content="Multi WordPress Management Dashboard">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.quilljs.com/1.3.7/quill.snow.css">
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    .tag-container {
      display: flex; flex-wrap: wrap; gap: .35rem;
      border: 1.5px solid var(--border); border-radius: var(--radius);
      padding: .45rem .65rem; cursor: text; background: var(--surface);
      min-height: 42px; align-items: center;
    }
    .tag-container:focus-within { border-color: var(--navy); box-shadow: 0 0 0 3px rgba(26,58,92,.1); }
    .tag-chip {
      background: var(--navy-xlight); color: var(--navy);
      padding: .2rem .55rem; border-radius: 20px; font-size: .78rem; font-weight: 600;
      display: flex; align-items: center; gap: .3rem;
    }
    .tag-chip i { cursor: pointer; font-size: .65rem; opacity: .7; }
    .tag-chip i:hover { opacity: 1; }
    #tag-input { border: none; outline: none; font-size: .875rem; min-width: 120px; flex: 1; background: transparent; }
  </style>
</head>
<body>
HTML;
}

function renderSidebar(string $activePage = ''): void {
    $user   = currentUser();
    $isAdm  = isAdmin();
    $appUrl = APP_URL;
    $initial = strtoupper(substr($user['full_name'] ?? 'U', 0, 1));
    $avatar = $user['avatar_url'] ? "<img src=\"{$user['avatar_url']}\" alt=\"avatar\">" : $initial;
    $role   = $user['role'] === 'admin' ? 'Administrator' : 'Penulis';

    $nav = function(string $icon, string $label, string $pg, string $badge = '') use ($appUrl, $activePage): string {
        $active = $activePage === $pg ? ' active' : '';
        $b = $badge ? "<span class='nav-badge'>{$badge}</span>" : '';
        return "<a href='{$appUrl}/index.php?page={$pg}' class='nav-item{$active}'><i class='fas fa-{$icon}'></i><span>{$label}</span>{$b}</a>";
    };

    echo <<<HTML
<nav class="sidebar">
  <div class="sidebar-brand">
    <div class="brand-icon"><i class="fas fa-wordpress"></i></div>
    <div class="brand-text">
      <strong>WP Dashboard</strong>
      <small>Multi-Site Manager</small>
    </div>
    <button class="sidebar-close" id="sidebar-close-btn" title="Tutup Menu"><i class="fas fa-times"></i></button>
  </div>
  <div class="sidebar-nav">
    <div class="nav-section-label">Menu Utama</div>
    {$nav('gauge-high', 'Dashboard', 'dashboard')}
    {$nav('newspaper', 'Semua Posting', 'posts')}
    {$nav('pen-to-square', 'Tulis Artikel', 'new_post')}
HTML;

    if ($isAdm) {
        echo '<div class="nav-section-label" style="margin-top:.75rem">AI & Otomasi</div>';
        echo $nav('robot', 'Tulis Otomatis (AI)', 'auto_writer');
        echo $nav('calendar-days', 'Kampanye Auto Post', 'auto_posts');
        echo '<div class="nav-section-label" style="margin-top:.75rem">Manajemen</div>';
        echo $nav('globe', 'Kelola Situs WP', 'sites');
        echo $nav('users', 'Kelola User', 'users');
        echo $nav('user-tag', 'Mapping Author', 'author_map');
        echo $nav('clock-rotate-left', 'Log Aktivitas', 'activity');
    }

    echo <<<HTML
    <div class="nav-section-label" style="margin-top:.75rem">Akun</div>
    {$nav('circle-user', 'Profil Saya', 'profile')}
HTML;

    if ($isAdm) {
        echo $nav('gear', 'Pengaturan', 'settings');
    }

    echo <<<HTML
  </div>
  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="sidebar-avatar">{$avatar}</div>
      <div class="sidebar-user-info">
        <strong>{$user['full_name']}</strong>
        <span>{$role}</span>
      </div>
      <a href="actions/auth_action.php?action=logout" title="Logout" style="color:rgba(255,255,255,.5);margin-left:auto;font-size:.85rem;" onclick="return confirm('Keluar dari dashboard?')">
        <i class="fas fa-right-from-bracket"></i>
      </a>
    </div>
  </div>
</nav>
<div class="sidebar-backdrop" id="sidebar-backdrop"></div>
HTML;
}

function renderTopbar(string $title, string $subtitle = ''): void {
    $sub = $subtitle ? "<p>{$subtitle}</p>" : '';
    echo <<<HTML
<div class="topbar">
  <button id="sidebar-toggle" class="btn btn-outline btn-icon" style="display:none" title="Menu">
    <i class="fas fa-bars"></i>
  </button>
  <div class="topbar-title">
    <h2>{$title}</h2>
    {$sub}
  </div>
  <div class="topbar-actions">
    <a href="index.php?page=new_post" class="btn btn-orange btn-sm">
      <i class="fas fa-plus"></i> Tulis Artikel
    </a>
  </div>
</div>
HTML;
}

function renderFlash(): void {
    $flash = getFlash();
    if (!$flash) return;
    $icons = ['success' => 'circle-check', 'error' => 'circle-exclamation', 'warning' => 'triangle-exclamation', 'info' => 'circle-info'];
    $type = $flash['type'] === 'error' ? 'danger' : $flash['type'];
    $icon = $icons[$flash['type']] ?? 'circle-info';
    $msg  = sanitize($flash['message']);
    echo "<div class='alert alert-{$type}' data-auto-hide><i class='fas fa-{$icon}'></i> {$msg}</div>";
}

function renderFoot(): void {
    echo <<<HTML
<div id="loading-overlay" class="loading-overlay">
  <div class="spinner"></div>
  <p style="color:var(--text-muted);font-size:.875rem">Memproses...</p>
</div>
<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<script src="assets/js/app.js"></script>
<script>
// ---- Mobile Sidebar Toggle ----
(function() {
  const sidebar   = document.querySelector('.sidebar');
  const backdrop  = document.getElementById('sidebar-backdrop');
  const toggleBtn = document.getElementById('sidebar-toggle');
  const closeBtn  = document.getElementById('sidebar-close-btn');
  if (!sidebar) return;

  function openSidebar() {
    sidebar.classList.add('open');
    if (backdrop) backdrop.classList.add('show');
    document.body.style.overflow = 'hidden';
  }
  function closeSidebar() {
    sidebar.classList.remove('open');
    if (backdrop) backdrop.classList.remove('show');
    document.body.style.overflow = '';
  }

  if (toggleBtn) toggleBtn.addEventListener('click', openSidebar);
  if (closeBtn)  closeBtn.addEventListener('click', closeSidebar);
  if (backdrop)  backdrop.addEventListener('click', closeSidebar);

  // Close on nav item click (mobile navigation)
  sidebar.querySelectorAll('.nav-item').forEach(el => {
    el.addEventListener('click', () => {
      if (window.innerWidth <= 768) closeSidebar();
    });
  });

  // Swipe left to close sidebar
  let touchStartX = 0;
  sidebar.addEventListener('touchstart', e => { touchStartX = e.touches[0].clientX; }, { passive: true });
  sidebar.addEventListener('touchend', e => {
    if (touchStartX - e.changedTouches[0].clientX > 60) closeSidebar();
  }, { passive: true });
})();
</script>
</body></html>
HTML;
}

function pageWrap(string $page, string $title, string $subtitle, callable $content): void {
    renderHead($title);
    echo '<div class="app-wrapper">';
    renderSidebar($page);
    echo '<div class="main-area">';
    renderTopbar($title, $subtitle);
    echo '<div class="page-content">';
    renderFlash();
    $content();
    echo '</div></div></div>';
    renderFoot();
}
