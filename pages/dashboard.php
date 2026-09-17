<?php
// ================================================
// pages/dashboard.php — Dashboard Intelligence
// ================================================
require_once __DIR__ . '/../includes/layout.php';
requireLogin();

$user   = currentUser();
$isAdm  = isAdmin();
$userId = $user['id'];
$db     = db();

// Stats
if ($isAdm) {
    $totalPosts     = $db->query("SELECT COUNT(*) FROM posts")->fetchColumn();
    $publishedPosts = $db->query("SELECT COUNT(*) FROM posts WHERE status='published'")->fetchColumn();
    $draftPosts     = $db->query("SELECT COUNT(*) FROM posts WHERE status='draft'")->fetchColumn();
    $scheduledPosts = $db->query("SELECT COUNT(*) FROM posts WHERE status='scheduled'")->fetchColumn();
    $failedPosts    = $db->query("SELECT COUNT(*) FROM posts WHERE status='failed'")->fetchColumn();
    $totalSites     = $db->query("SELECT COUNT(*) FROM wp_sites WHERE status='active'")->fetchColumn();
    $totalUsers     = $db->query("SELECT COUNT(*) FROM users WHERE is_active=1 AND role='writer'")->fetchColumn();
} else {
    $stmt = $db->prepare("SELECT COUNT(*) FROM posts WHERE author_id=?"); 
    $stmt->execute([$userId]); 
    $totalPosts = $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM posts WHERE author_id=? AND status='published'"); 
    $stmt->execute([$userId]); 
    $publishedPosts = $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM posts WHERE author_id=? AND status='draft'"); 
    $stmt->execute([$userId]); 
    $draftPosts = $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM posts WHERE author_id=? AND status='scheduled'"); 
    $stmt->execute([$userId]); 
    $scheduledPosts = $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM posts WHERE author_id=? AND status='failed'"); 
    $stmt->execute([$userId]); 
    $failedPosts = $stmt->fetchColumn();

    $totalSites = $db->query("SELECT COUNT(*) FROM wp_sites WHERE status='active'")->fetchColumn();
    $totalUsers = null;
}

// 1. Time Series Data (Last 7 Days)
$dates = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $dates[$d] = 0;
}

if ($isAdm) {
    $trendStmt = $db->query("SELECT DATE(published_at) as date_grp, COUNT(*) as total 
                             FROM posts 
                             WHERE status='published' AND published_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                             GROUP BY DATE(published_at)");
} else {
    $trendStmt = $db->prepare("SELECT DATE(published_at) as date_grp, COUNT(*) as total 
                               FROM posts 
                               WHERE status='published' AND author_id=? AND published_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                               GROUP BY DATE(published_at)");
    $trendStmt->execute([$userId]);
}

foreach ($trendStmt->fetchAll() as $row) {
    if (isset($dates[$row['date_grp']])) {
        $dates[$row['date_grp']] = (int)$row['total'];
    }
}

$trendLabels = array_map(function($d) {
    return date('d M', strtotime($d));
}, array_keys($dates));
$trendValues = array_values($dates);

// 2. Multi-Site Contribution Data
if ($isAdm) {
    $siteStmt = $db->query("SELECT s.name as site_name, COUNT(p.id) as total
                            FROM posts p
                            JOIN wp_sites s ON p.site_id = s.id
                            WHERE p.status = 'published'
                            GROUP BY p.site_id
                            ORDER BY total DESC");
} else {
    $siteStmt = $db->prepare("SELECT s.name as site_name, COUNT(p.id) as total
                              FROM posts p
                              JOIN wp_sites s ON p.site_id = s.id
                              WHERE p.status = 'published' AND p.author_id=?
                              GROUP BY p.site_id
                              ORDER BY total DESC");
    $siteStmt->execute([$userId]);
}
$siteData = $siteStmt->fetchAll();
$siteLabels = array_column($siteData, 'site_name');
$siteValues = array_map('intval', array_column($siteData, 'total'));

// 3. Status Distribution Data
$statusLabels = ['Terbit', 'Draft', 'Terjadwal', 'Gagal'];
$statusValues = [(int)$publishedPosts, (int)$draftPosts, (int)$scheduledPosts, (int)$failedPosts];

// 10 latest articles
if ($isAdm) {
    $recentStmt = $db->query("SELECT p.*, u.full_name as author_name, s.name as site_name 
                               FROM posts p 
                               LEFT JOIN users u ON p.author_id=u.id 
                               LEFT JOIN wp_sites s ON p.site_id=s.id 
                               ORDER BY p.updated_at DESC LIMIT 10");
} else {
    $recentStmt = $db->prepare("SELECT p.*, u.full_name as author_name, s.name as site_name 
                                 FROM posts p 
                                 LEFT JOIN users u ON p.author_id=u.id 
                                 LEFT JOIN wp_sites s ON p.site_id=s.id 
                                 WHERE p.author_id=? 
                                 ORDER BY p.updated_at DESC LIMIT 10");
    $recentStmt->execute([$userId]);
}
$recentPosts = $recentStmt->fetchAll();

// Active Sites for dynamic monitor
$activeSites = $db->query("SELECT id, name, url FROM wp_sites WHERE status='active' ORDER BY name")->fetchAll();

// Recent activity (admin only)
$activities = [];
if ($isAdm) {
    $activities = $db->query("SELECT a.*, u.full_name FROM activity_log a 
                               LEFT JOIN users u ON a.user_id=u.id 
                               ORDER BY a.created_at DESC LIMIT 6")->fetchAll();
}

$statusBadge = fn($s) => match($s) {
    'published' => "<span class='badge badge-published'><i class='fas fa-circle-check'></i> Terbit</span>",
    'draft'     => "<span class='badge badge-draft'><i class='fas fa-circle-dot'></i> Draft</span>",
    'scheduled' => "<span class='badge badge-scheduled'><i class='fas fa-clock'></i> Terjadwal</span>",
    'failed'    => "<span class='badge badge-failed'><i class='fas fa-circle-xmark'></i> Gagal</span>",
    default     => "<span class='badge badge-draft'>{$s}</span>",
};

// Start output layout
pageWrap('dashboard', 'Dashboard', 'Selamat datang kembali, ' . $user['full_name'], function() use (
    $totalPosts, $publishedPosts, $draftPosts, $scheduledPosts, $failedPosts, $totalSites, $totalUsers,
    $recentPosts, $activities, $isAdm, $statusBadge, $activeSites,
    $trendLabels, $trendValues, $siteLabels, $siteValues, $statusLabels, $statusValues
) {
?>
<!-- Include Chart.js via CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- Stats Grid -->
<div class="stats-grid">
  <div class="stat-card blue">
    <div class="stat-icon"><i class="fas fa-newspaper"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= $totalPosts ?></div>
      <div class="stat-label">Total Artikel</div>
    </div>
  </div>
  <div class="stat-card green">
    <div class="stat-icon"><i class="fas fa-circle-check"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= $publishedPosts ?></div>
      <div class="stat-label">Sudah Terbit</div>
    </div>
  </div>
  <div class="stat-card orange">
    <div class="stat-icon"><i class="fas fa-clock"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= $scheduledPosts ?></div>
      <div class="stat-label">Terjadwal</div>
    </div>
  </div>
  <div class="stat-card red">
    <div class="stat-icon"><i class="fas fa-circle-xmark"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= $failedPosts ?></div>
      <div class="stat-label">Gagal Publish</div>
    </div>
  </div>
</div>

<!-- Dynamic Charts Grid -->
<div style="display:grid;grid-template-columns: 2fr 1fr;gap:1.5rem;margin-bottom:1.5rem">
  <!-- Line Chart: Time Series -->
  <div class="card" style="padding:1.5rem">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
      <h3 style="font-size:0.95rem;font-weight:700;"><i class="fas fa-chart-line" style="color:var(--navy);margin-right:.4rem"></i> Tren Penerbitan (7 Hari Terakhir)</h3>
    </div>
    <div style="position:relative;height:260px;">
      <canvas id="timeSeriesChart"></canvas>
    </div>
  </div>

  <!-- Doughnut Chart: Status Breakdown -->
  <div class="card" style="padding:1.5rem">
    <h3 style="font-size:0.95rem;font-weight:700;margin-bottom:1rem"><i class="fas fa-chart-pie" style="color:var(--orange);margin-right:.4rem"></i> Distribusi Status</h3>
    <div style="position:relative;height:260px;">
      <canvas id="statusChart"></canvas>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns: 1fr 1fr;gap:1.5rem;margin-bottom:1.5rem">
  <!-- Bar Chart: Multi-Site Contribution -->
  <div class="card" style="padding:1.5rem">
    <h3 style="font-size:0.95rem;font-weight:700;margin-bottom:1rem"><i class="fas fa-chart-bar" style="color:var(--navy);margin-right:.4rem"></i> Distribusi per Situs (Situs WordPress)</h3>
    <div style="position:relative;height:220px;">
      <?php if (!empty($siteLabels)): ?>
        <canvas id="siteChart"></canvas>
      <?php else: ?>
        <div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--text-light)">
          Belum ada data distribusi kontribusi situs.
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Live WordPress Monitor -->
  <div class="card" style="padding:1.5rem">
    <h3 style="font-size:0.95rem;font-weight:700;margin-bottom:1rem">
      <i class="fas fa-circle-nodes" style="color:var(--orange);margin-right:.4rem"></i> WordPress Sites Live Monitor
    </h3>
    <div id="live-monitor-container" style="display:flex;flex-direction:column;gap:0.75rem;max-height:220px;overflow-y:auto;padding-right:0.25rem;">
      <?php if ($activeSites): ?>
        <?php foreach ($activeSites as $site): ?>
          <div class="live-site-row" data-site-id="<?= $site['id'] ?>" style="display:flex;align-items:center;justify-content:space-between;padding:0.65rem 0.85rem;background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius);transition:var(--transition)">
            <div style="display:flex;align-items:center;gap:0.65rem;min-width:0;flex:1;">
              <span class="status-pulse" style="width:10px;height:10px;border-radius:50%;background:#cbd5e1;display:inline-block;flex-shrink:0;"></span>
              <div style="min-width:0;">
                <strong style="font-size:0.85rem;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text)"><?= sanitize($site['name']) ?></strong>
                <span class="live-tagline text-small text-muted" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;">Menghubungkan API...</span>
              </div>
            </div>
            <div style="display:flex;align-items:center;gap:0.5rem;flex-shrink:0;">
              <span class="live-posts-count badge badge-draft" style="font-size:0.7rem">-- Posts</span>
              <span class="live-cats-count badge badge-draft" style="font-size:0.7rem">-- Cats</span>
              <a href="actions/site_action.php?action=sync&site_id=<?= $site['id'] ?>" class="btn btn-outline btn-sm sync-btn" style="padding:0.25rem 0.45rem;font-size:0.75rem;" title="Sinkronisasi artikel dari situs ini">
                <i class="fas fa-rotate"></i>
              </a>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div style="color:var(--text-light);text-align:center;padding:2rem;">
          <i class="fas fa-globe-slash" style="font-size:1.5rem;margin-bottom:0.5rem;display:block;"></i>
          Belum ada situs WordPress aktif.
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Main content row: 10 Latest Articles & Activity Log -->
<div style="display:grid;grid-template-columns:<?= $isAdm && !empty($activities) ? '1.8fr 1.2fr' : '1fr' ?>;gap:1.5rem">

  <!-- 10 Latest Articles -->
  <div class="card">
    <div class="card-header">
      <h3><i class="fas fa-newspaper" style="color:var(--navy);margin-right:.4rem"></i> 10 Artikel Terakhir</h3>
      <a href="index.php?page=posts" class="btn btn-outline btn-sm">Semua Artikel</a>
    </div>
    <div class="table-responsive">
      <?php if ($recentPosts): ?>
      <table class="table">
        <thead>
          <tr>
            <th>Judul</th>
            <?php if ($isAdm): ?><th>Penulis</th><?php endif; ?>
            <th>Situs</th>
            <th>Status</th>
            <th>Terakhir Update</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentPosts as $post): ?>
          <tr>
            <td>
              <div style="font-weight:600;max-width:280px" class="truncate" title="<?= sanitize($post['title']) ?>"><?= sanitize($post['title']) ?></div>
            </td>
            <?php if ($isAdm): ?>
            <td><span style="font-size:.8rem;color:var(--text-muted)"><?= sanitize($post['author_name'] ?? '-') ?></span></td>
            <?php endif; ?>
            <td><span style="font-size:.8rem;color:var(--text-muted)"><?= sanitize($post['site_name'] ?? 'Belum ditentukan') ?></span></td>
            <td><?= $statusBadge($post['status']) ?></td>
            <td><span class="text-small text-muted"><?= timeAgo($post['updated_at']) ?></span></td>
            <td>
              <div style="display:flex;gap:0.35rem">
                <?php if ($post['status'] === 'draft' || $post['status'] === 'failed'): ?>
                <a href="index.php?page=edit_post&id=<?= $post['id'] ?>" class="btn btn-outline btn-sm" style="padding:0.3rem 0.5rem">
                  <i class="fas fa-pen"></i>
                </a>
                <?php elseif ($post['wp_post_url']): ?>
                <a href="<?= sanitize($post['wp_post_url']) ?>" target="_blank" class="btn btn-outline btn-sm" style="padding:0.3rem 0.5rem">
                  <i class="fas fa-arrow-up-right-from-square"></i>
                </a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
      <div class="empty-state">
        <i class="fas fa-file-circle-plus"></i>
        <h4>Belum ada artikel</h4>
        <p>Mulai menulis artikel pertama Anda</p>
        <a href="index.php?page=new_post" class="btn btn-orange">
          <i class="fas fa-plus"></i> Tulis Artikel
        </a>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($isAdm && !empty($activities)): ?>
  <!-- Activity Log -->
  <div class="card">
    <div class="card-header">
      <h3><i class="fas fa-clock-rotate-left" style="color:var(--orange);margin-right:.4rem"></i> Log Aktivitas</h3>
      <a href="index.php?page=activity" class="btn btn-outline btn-sm">Semua</a>
    </div>
    <div class="card-body" style="padding-top:.5rem">
      <div class="activity-list">
        <?php foreach ($activities as $act):
          $iconMap = [
            'login'       => ['sign-in-alt', '#22c55e', '#f0fdf4'],
            'logout'      => ['sign-out-alt', '#64748b', '#f1f5f9'],
            'post_create' => ['pen', '#3b82f6', '#eff6ff'],
            'post_publish'=> ['circle-check', '#22c55e', '#f0fdf4'],
            'post_delete' => ['trash', '#ef4444', '#fef2f2'],
            'site_add'    => ['globe', '#f97316', '#fff3eb'],
            'user_add'    => ['user-plus', '#3b82f6', '#eff6ff'],
          ];
          $ic = $iconMap[$act['action']] ?? ['circle', '#94a3b8', '#f1f5f9'];
        ?>
        <div class="activity-item">
          <div class="activity-icon" style="background:<?= $ic[2] ?>;color:<?= $ic[1] ?>">
            <i class="fas fa-<?= $ic[0] ?>"></i>
          </div>
          <div class="activity-body" style="min-width:0">
            <strong class="truncate" style="display:block"><?= sanitize($act['full_name'] ?? 'System') ?></strong>
            <p style="font-size:0.78rem;line-height:1.25"><?= sanitize($act['details'] ?: $act['action']) ?></p>
          </div>
          <span class="activity-time"><?= timeAgo($act['created_at']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<style>
.status-pulse.online {
  background: var(--success) !important;
  box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
  animation: pulse-green 2s infinite;
}
.status-pulse.offline {
  background: var(--danger) !important;
  box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
}
@keyframes pulse-green {
  0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7); }
  70% { transform: scale(1); box-shadow: 0 0 0 5px rgba(34, 197, 94, 0); }
  100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
}
</style>

<!-- Chart.js scripts -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  // Chart colors
  const colorNavy = '#1a3a5c';
  const colorOrange = '#f97316';
  const colorGreen = '#22c55e';
  const colorRed = '#ef4444';
  const colorMuted = '#94a3b8';
  const colorLightNavy = 'rgba(26, 58, 92, 0.15)';

  // 1. Time Series Chart
  const trendLabels = <?= json_encode($trendLabels) ?>;
  const trendValues = <?= json_encode($trendValues) ?>;
  const tsCtx = document.getElementById('timeSeriesChart').getContext('2d');
  new Chart(tsCtx, {
    type: 'line',
    data: {
      labels: trendLabels,
      datasets: [{
        label: 'Artikel Terbit',
        data: trendValues,
        borderColor: colorNavy,
        backgroundColor: colorLightNavy,
        borderWidth: 3,
        fill: true,
        tension: 0.35,
        pointBackgroundColor: colorOrange,
        pointBorderColor: '#fff',
        pointRadius: 5,
        pointHoverRadius: 7
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false }
      },
      scales: {
        y: {
          beginAtZero: true,
          grid: { color: '#f1f5f9' },
          ticks: { stepSize: 1 }
        },
        x: {
          grid: { display: false }
        }
      }
    }
  });

  // 2. Status Distribution Chart
  const statusLabels = <?= json_encode($statusLabels) ?>;
  const statusValues = <?= json_encode($statusValues) ?>;
  const statusCtx = document.getElementById('statusChart').getContext('2d');
  new Chart(statusCtx, {
    type: 'doughnut',
    data: {
      labels: statusLabels,
      datasets: [{
        data: statusValues,
        backgroundColor: [colorGreen, colorMuted, '#3b82f6', colorRed],
        borderWidth: 2,
        borderColor: '#fff'
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'bottom',
          labels: { boxWidth: 12, font: { family: 'Inter', size: 11 } }
        }
      },
      cutout: '65%'
    }
  });

  // 3. Multi-Site Bar Chart
  const siteLabels = <?= json_encode($siteLabels) ?>;
  const siteValues = <?= json_encode($siteValues) ?>;
  const siteCanvas = document.getElementById('siteChart');
  if (siteCanvas) {
    const siteCtx = siteCanvas.getContext('2d');
    new Chart(siteCtx, {
      type: 'bar',
      data: {
        labels: siteLabels,
        datasets: [{
          label: 'Artikel',
          data: siteValues,
          backgroundColor: colorNavy,
          borderRadius: 6,
          barThickness: 24
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { stepSize: 1 },
            grid: { color: '#f1f5f9' }
          },
          x: {
            grid: { display: false }
          }
        }
      }
    });
  }

  // 4. Live WordPress Monitor Fetching
  const siteRows = document.querySelectorAll('.live-site-row');
  siteRows.forEach(row => {
    const siteId = row.getAttribute('data-site-row') || row.getAttribute('data-site-id');
    const pulse = row.querySelector('.status-pulse');
    const tagline = row.querySelector('.live-tagline');
    const postsBadge = row.querySelector('.live-posts-count');
    const catsBadge = row.querySelector('.live-cats-count');

    fetch(`actions/site_action.php?action=get_live_stats&site_id=${siteId}`)
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          pulse.classList.add('online');
          tagline.textContent = data.description || 'Koneksi Berhasil';
          postsBadge.className = 'live-posts-count badge badge-published';
          postsBadge.textContent = `${data.total_posts} Posts`;
          catsBadge.className = 'live-cats-count badge badge-scheduled';
          catsBadge.textContent = `${data.categories} Kategori`;
        } else {
          pulse.classList.add('offline');
          tagline.textContent = data.message || 'Offline';
          postsBadge.className = 'live-posts-count badge badge-failed';
          postsBadge.textContent = 'Err';
          catsBadge.className = 'live-cats-count badge badge-failed';
          catsBadge.textContent = 'Err';
        }
      })
      .catch(err => {
        pulse.classList.add('offline');
        tagline.textContent = 'Koneksi Gagal';
        postsBadge.className = 'live-posts-count badge badge-failed';
        postsBadge.textContent = 'Offline';
      });
  });

  // Loading overlay for sync buttons
  document.querySelectorAll('.sync-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const overlay = document.getElementById('loading-overlay');
      if (overlay) {
        const p = overlay.querySelector('p');
        if (p) p.textContent = 'Menyinkronkan artikel dari situs WordPress...';
        overlay.classList.add('show');
      }
    });
  });
});
</script>
<?php
});
