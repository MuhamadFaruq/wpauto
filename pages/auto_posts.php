<?php
// ================================================
// pages/auto_posts.php — AI Campaign Tracking Dashboard
// ================================================
require_once __DIR__ . '/../includes/layout.php';
requireLogin();

$db   = db();
$user = currentUser();

// Ensure ai_projects table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `ai_projects` (
        `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `name`        VARCHAR(255) NOT NULL,
        `description` TEXT DEFAULT NULL,
        `site_id`     INT UNSIGNED DEFAULT NULL,
        `model`       VARCHAR(200) DEFAULT NULL,
        `language`    VARCHAR(50) NOT NULL DEFAULT 'indonesian',
        `author_id`   INT UNSIGNED NOT NULL,
        `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("ALTER TABLE `posts`
        ADD COLUMN IF NOT EXISTS `project_id` INT UNSIGNED DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS `ai_generated` TINYINT(1) NOT NULL DEFAULT 0");

    $db->exec("ALTER TABLE `post_schedules`
        ADD COLUMN IF NOT EXISTS `project_id` INT UNSIGNED DEFAULT NULL");

    $db->exec("ALTER TABLE `posts` MODIFY COLUMN `status`
        ENUM('draft','pending_publish','published','failed','scheduled','auto_template') NOT NULL DEFAULT 'draft'");
} catch (Throwable $e) {
    // Tables may already exist
}

// Filter by project if given
$filterProjectId = (int)($_GET['project_id'] ?? 0);

// ---- Fetch Projects ----
$projQuery = $db->prepare("SELECT p.*, s.name AS site_name,
    (SELECT COUNT(*) FROM post_schedules ps2
        JOIN posts pt2 ON ps2.post_id = pt2.id
        WHERE pt2.status = 'auto_template'
          AND (ps2.project_id = p.id OR pt2.project_id = p.id)
    ) AS keyword_count,
    (SELECT COUNT(*) FROM posts pt3
        WHERE pt3.project_id = p.id AND pt3.status = 'published') AS published_count
    FROM ai_projects p
    LEFT JOIN wp_sites s ON p.site_id = s.id
    WHERE p.author_id = ?
    ORDER BY p.created_at DESC");
$projQuery->execute([$user['id']]);
$projects = $projQuery->fetchAll();

// ---- Fetch Active Campaigns (auto_template posts with their schedules) ----
$campWhere = "p.status = 'auto_template'";
$campParams = [];
if (!isAdmin()) { $campWhere .= " AND p.author_id = ?"; $campParams[] = $user['id']; }
if ($filterProjectId) { $campWhere .= " AND (ps.project_id = ? OR p.project_id = ?)"; $campParams[] = $filterProjectId; $campParams[] = $filterProjectId; }

$campSql = "SELECT ps.*, p.title AS topic, p.content AS source_material, p.excerpt AS campaign_settings,
                   p.project_id, s.name AS site_name, u.full_name AS author_name
            FROM post_schedules ps
            JOIN posts p ON ps.post_id = p.id
            LEFT JOIN wp_sites s ON p.site_id = s.id
            LEFT JOIN users u ON p.author_id = u.id
            WHERE $campWhere
            ORDER BY ps.scheduled_at ASC";
$campStmt = $db->prepare($campSql);
$campStmt->execute($campParams);
$campaigns = $campStmt->fetchAll();

// ---- Fetch Published AI Articles ----
$histWhere = "p.status = 'published' AND p.wp_post_id IS NOT NULL";
$histParams = [];
if (!isAdmin()) { $histWhere .= " AND p.author_id = ?"; $histParams[] = $user['id']; }
if ($filterProjectId) { $histWhere .= " AND p.project_id = ?"; $histParams[] = $filterProjectId; }

$histSql = "SELECT p.*, s.name AS site_name, u.full_name AS author_name,
                   proj.name AS project_name
            FROM posts p
            LEFT JOIN wp_sites s ON p.site_id = s.id
            LEFT JOIN users u ON p.author_id = u.id
            LEFT JOIN ai_projects proj ON p.project_id = proj.id
            WHERE $histWhere
            ORDER BY p.published_at DESC
            LIMIT 100";
$histStmt = $db->prepare($histSql);
$histStmt->execute($histParams);
$history = $histStmt->fetchAll();

// Stats
$totalCampaigns = count($campaigns);
$activeCampaigns = count(array_filter($campaigns, fn($c) => $c['is_active']));
$totalPublished  = count($history);

$scheduleLabels = [
    'once'    => 'Sekali',
    'hourly'  => 'Per Jam',
    'daily'   => 'Per Hari',
    'weekly'  => 'Per Minggu',
    'monthly' => 'Per Bulan',
    'custom'  => 'Kustom',
];

$activeTab = $_GET['tab'] ?? 'campaigns';

pageWrap('auto_posts', 'Kampanye Auto Post', 'Pantau penjadwalan dan artikel yang sudah terbit', function()
    use ($campaigns, $history, $projects, $scheduleLabels, $filterProjectId, $totalCampaigns, $activeCampaigns, $totalPublished, $activeTab) {
?>
<style>
.stat-mini { background:var(--surface); border:1.5px solid var(--border); border-radius:var(--radius); padding:.85rem 1.25rem; }
.stat-mini .value { font-size:1.8rem; font-weight:800; color:var(--navy); line-height:1; }
.stat-mini .label  { font-size:.75rem; color:var(--text-muted); margin-top:.2rem; }
.camp-badge { display:inline-flex; align-items:center; gap:.3rem; font-size:.72rem; font-weight:700; padding:.2rem .55rem; border-radius:20px; }
.camp-badge.active   { background:#d1fae5; color:#065f46; }
.camp-badge.inactive { background:#fee2e2; color:#991b1b; }
.camp-badge.done     { background:#e0e7ff; color:#3730a3; }
.countdown { font-family:monospace; font-size:.78rem; color:var(--orange); font-weight:700; }
.tab-nav { display:flex; border-bottom:2px solid var(--border); margin-bottom:1.5rem; }
.tab-nav a { padding:.75rem 1.25rem; font-weight:600; font-size:.875rem; color:var(--text-muted); text-decoration:none; border-bottom:2px solid transparent; margin-bottom:-2px; white-space:nowrap; display:inline-flex; align-items:center; gap:.4rem; }
.tab-nav a.active { color:var(--navy); border-bottom-color:var(--orange); }
.tab-nav a:hover:not(.active) { color:var(--text); }
.proj-pill { display:inline-flex; align-items:center; gap:.3rem; background:var(--navy-xlight); color:var(--navy); padding:.2rem .6rem; border-radius:20px; font-size:.72rem; font-weight:700; }
</style>

<!-- Header -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:.75rem">
  <div>
    <h2 style="margin:0;color:var(--navy)"><i class="fas fa-calendar-days" style="color:var(--orange);margin-right:.5rem"></i>Kampanye Auto Post</h2>
    <p style="margin:.25rem 0 0;color:var(--text-muted);font-size:.875rem">
      Pantau penjadwalan aktif dan riwayat artikel yang sudah diterbitkan
      <?php if ($filterProjectId): ?>
      <span class="proj-pill"><i class="fas fa-folder"></i> Filter Proyek</span>
      <?php endif; ?>
    </p>
  </div>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <?php if ($filterProjectId): ?>
    <a href="index.php?page=auto_posts" class="btn btn-outline btn-sm"><i class="fas fa-times"></i> Reset Filter</a>
    <?php endif; ?>
    <a href="index.php?page=auto_writer" class="btn btn-orange"><i class="fas fa-plus"></i> Buat Kampanye</a>
  </div>
</div>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem">
  <div class="stat-mini">
    <div class="value"><?= $totalCampaigns ?></div>
    <div class="label">Total Kampanye Terjadwal</div>
  </div>
  <div class="stat-mini">
    <div class="value" style="color:var(--orange)"><?= $activeCampaigns ?></div>
    <div class="label">Kampanye Aktif</div>
  </div>
  <div class="stat-mini">
    <div class="value" style="color:#10b981"><?= $totalPublished ?></div>
    <div class="label">Artikel Sudah Terbit</div>
  </div>
</div>

<!-- Projects row (if any) -->
<?php if (!empty($projects)): ?>
<div class="card" style="margin-bottom:1.5rem;padding:1rem 1.25rem">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem">
    <h4 style="margin:0;font-size:.9rem"><i class="fas fa-folder" style="color:var(--navy)"></i> Proyek AI</h4>
    <a href="index.php?page=auto_writer" class="btn btn-outline btn-xs" style="font-size:.75rem">+ Buat Proyek</a>
  </div>
  <div style="display:flex;gap:.75rem;flex-wrap:wrap">
    <a href="index.php?page=auto_posts" class="btn btn-sm <?= !$filterProjectId ? 'btn-orange' : 'btn-outline' ?>" style="font-size:.78rem">
      Semua (<?= $totalCampaigns ?>)
    </a>
    <?php foreach ($projects as $proj): ?>
    <a href="index.php?page=auto_posts&project_id=<?= $proj['id'] ?>"
       class="btn btn-sm <?= $filterProjectId == $proj['id'] ? 'btn-orange' : 'btn-outline' ?>"
       style="font-size:.78rem">
      <i class="fas fa-folder"></i> <?= sanitize($proj['name']) ?>
      <span style="background:rgba(255,255,255,.3);padding:.05rem .4rem;border-radius:99px;font-size:.7rem"><?= (int)$proj['keyword_count'] ?></span>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Tabs -->
<div class="tab-nav">
  <a href="?page=auto_posts<?= $filterProjectId ? '&project_id='.$filterProjectId : '' ?>&tab=campaigns"
     class="<?= $activeTab === 'campaigns' ? 'active' : '' ?>">
    <i class="fas fa-clock"></i> Terjadwal (<?= $totalCampaigns ?>)
  </a>
  <a href="?page=auto_posts<?= $filterProjectId ? '&project_id='.$filterProjectId : '' ?>&tab=history"
     class="<?= $activeTab === 'history' ? 'active' : '' ?>">
    <i class="fas fa-circle-check"></i> Sudah Terbit (<?= $totalPublished ?>)
  </a>
</div>

<!-- TAB: Campaigns -->
<?php if ($activeTab === 'campaigns'): ?>
<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
    <h4 style="margin:0"><i class="fas fa-list"></i> Kampanye Terjadwal</h4>
    <span style="font-size:.8rem;color:var(--text-muted)">Diurutkan berdasarkan waktu terdekat</span>
  </div>
  <?php if (empty($campaigns)): ?>
  <div style="padding:3rem;text-align:center;color:var(--text-muted)">
    <i class="fas fa-folder-open" style="font-size:3rem;margin-bottom:1rem;opacity:.3"></i>
    <p>Belum ada kampanye terjadwal.</p>
    <a href="index.php?page=auto_writer" class="btn btn-orange btn-sm">Buat Kampanye Baru</a>
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Keyword / Topik</th>
          <th>Situs</th>
          <th>Frekuensi</th>
          <th>Dijalankan</th>
          <th>Waktu Jadwal</th>
          <th>Status</th>
          <th style="text-align:right">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($campaigns as $camp):
          $now     = new DateTime();
          $schedDt = new DateTime($camp['scheduled_at']);
          $isPast  = $schedDt <= $now;
          $statusBadge = $camp['is_active']
            ? '<span class="camp-badge active"><i class="fas fa-circle-play"></i> Aktif</span>'
            : '<span class="camp-badge inactive"><i class="fas fa-circle-pause"></i> Dijeda</span>';
          if ($camp['run_count'] >= $camp['recur_count'] && $camp['recur_count']) {
            $statusBadge = '<span class="camp-badge done"><i class="fas fa-check"></i> Selesai</span>';
          }
        ?>
        <tr>
          <td style="max-width:220px">
            <div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= sanitize($camp['topic']) ?>">
              <?= sanitize($camp['topic']) ?>
            </div>
            <?php
            $settings = json_decode($camp['campaign_settings'] ?? '{}', true);
            if (!empty($settings['model'])):
            ?>
            <div style="font-size:.72rem;color:var(--text-muted);margin-top:.15rem">
              <i class="fas fa-robot"></i> <?= sanitize($settings['model']) ?>
            </div>
            <?php endif; ?>
          </td>
          <td><span class="badge badge-pending" style="font-size:.72rem"><i class="fas fa-globe"></i> <?= sanitize($camp['site_name'] ?: 'Lokal') ?></span></td>
          <td>
            <span class="badge badge-published" style="background:var(--navy);color:#fff;font-size:.72rem">
              <?= $scheduleLabels[$camp['schedule_type']] ?? $camp['schedule_type'] ?>
            </span>
          </td>
          <td style="font-size:.8rem">
            <strong><?= (int)$camp['run_count'] ?></strong>
            <?php if ($camp['recur_count']): ?>/<span style="color:var(--text-muted)"><?= $camp['recur_count'] ?></span><?php endif; ?>
          </td>
          <td>
            <div style="font-size:.78rem;font-weight:600;color:<?= $isPast ? '#ef4444' : 'var(--navy)' ?>">
              <?= (new DateTime($camp['scheduled_at']))->format('d M Y') ?>
            </div>
            <div class="countdown" id="cd-<?= $camp['id'] ?>" data-ts="<?= $schedDt->getTimestamp() ?>">
              <?= (new DateTime($camp['scheduled_at']))->format('H:i') ?>
            </div>
          </td>
          <td><?= $statusBadge ?></td>
          <td style="text-align:right;white-space:nowrap">
            <a href="actions/post_action.php?action=toggle_schedule&id=<?= $camp['id'] ?>&csrf_token=<?= csrfToken() ?>"
               class="btn <?= $camp['is_active'] ? 'btn-outline' : 'btn-orange' ?> btn-xs"
               title="<?= $camp['is_active'] ? 'Jeda' : 'Aktifkan' ?>">
              <i class="fas <?= $camp['is_active'] ? 'fa-pause' : 'fa-play' ?>"></i>
            </a>
            <a href="actions/post_action.php?action=delete_schedule&id=<?= $camp['id'] ?>&csrf_token=<?= csrfToken() ?>"
               class="btn btn-xs" style="background:#fef2f2;color:#ef4444;border:1px solid #fecaca"
               onclick="return confirm('Hapus kampanye ini? Template topik dan jadwalnya akan dihapus permanen.')"
               title="Hapus">
              <i class="fas fa-trash"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- TAB: History -->
<?php if ($activeTab === 'history'): ?>
<div class="card">
  <div class="card-header">
    <h4 style="margin:0"><i class="fas fa-history"></i> Riwayat Artikel Terbit</h4>
  </div>
  <?php if (empty($history)): ?>
  <div style="padding:3rem;text-align:center;color:var(--text-muted)">
    <i class="fas fa-history" style="font-size:3rem;margin-bottom:1rem;opacity:.3"></i>
    <p>Belum ada artikel yang diterbitkan melalui Auto Post.</p>
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Gambar</th>
          <th>Judul Artikel</th>
          <th>Situs</th>
          <?php if (isAdmin()): ?><th>Penulis</th><?php endif; ?>
          <th>Proyek</th>
          <th>Tanggal Terbit</th>
          <th style="text-align:right">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($history as $post): ?>
        <tr>
          <td style="width:70px">
            <?php if ($post['featured_image_url']): ?>
            <img src="<?= sanitize($post['featured_image_url']) ?>" alt="preview"
                 style="width:60px;height:40px;object-fit:cover;border-radius:6px;border:1px solid var(--border)">
            <?php else: ?>
            <div style="width:60px;height:40px;background:var(--surface2);border-radius:6px;border:1px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--border)">
              <i class="fas fa-image"></i>
            </div>
            <?php endif; ?>
          </td>
          <td>
            <div style="font-weight:600;max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= sanitize($post['title']) ?>">
              <?= sanitize($post['title']) ?>
            </div>
            <?php
            $tags = json_decode($post['tags'] ?? '[]', true);
            if (!empty($tags)):
            ?>
            <div style="font-size:.72rem;color:var(--text-muted);margin-top:.15rem">
              <?= implode(' · ', array_map('htmlspecialchars', array_slice($tags, 0, 3))) ?>
              <?= count($tags) > 3 ? '+' . (count($tags)-3) . ' tag' : '' ?>
            </div>
            <?php endif; ?>
          </td>
          <td><span class="badge badge-published" style="font-size:.72rem"><i class="fas fa-globe"></i> <?= sanitize($post['site_name'] ?: 'Lokal') ?></span></td>
          <?php if (isAdmin()): ?>
          <td style="font-size:.8rem;color:var(--text-muted)"><?= sanitize($post['author_name'] ?? '-') ?></td>
          <?php endif; ?>
          <td>
            <?php if ($post['project_name']): ?>
            <span class="proj-pill"><i class="fas fa-folder"></i> <?= sanitize($post['project_name']) ?></span>
            <?php else: ?>
            <span style="font-size:.78rem;color:var(--text-muted)">—</span>
            <?php endif; ?>
          </td>
          <td style="font-size:.8rem;color:var(--text-muted)">
            <?= $post['published_at'] ? (new DateTime($post['published_at']))->format('d M Y, H:i') : '—' ?>
          </td>
          <td style="text-align:right">
            <?php if ($post['wp_post_url']): ?>
            <a href="<?= sanitize($post['wp_post_url']) ?>" target="_blank" class="btn btn-outline btn-xs">
              Lihat <i class="fas fa-external-link" style="font-size:.7rem;margin-left:.2rem"></i>
            </a>
            <?php endif; ?>
            <a href="index.php?page=edit_post&id=<?= $post['id'] ?>" class="btn btn-outline btn-xs" title="Edit">
              <i class="fas fa-pen"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<script>
// Countdown timers for scheduled campaigns
document.querySelectorAll('.countdown[data-ts]').forEach(el => {
  const ts = parseInt(el.dataset.ts) * 1000;
  function update() {
    const diff = ts - Date.now();
    if (diff <= 0) {
      el.textContent = 'Segera diproses...';
      el.style.color = '#ef4444';
      return;
    }
    const d = Math.floor(diff / 86400000);
    const h = Math.floor((diff % 86400000) / 3600000);
    const m = Math.floor((diff % 3600000) / 60000);
    const s = Math.floor((diff % 60000) / 1000);
    if (d > 0) el.textContent = `${d}h ${h}j lagi`;
    else if (h > 0) el.textContent = `${h}j ${m}m lagi`;
    else el.textContent = `${m}m ${s}d lagi`;
  }
  update();
  setInterval(update, 1000);
});
</script>
<?php
});
