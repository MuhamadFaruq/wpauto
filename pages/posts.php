<?php
// ================================================
// pages/posts.php — Post listing page
// ================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin();

$user   = currentUser();
$isAdm  = isAdmin();
$userId = (int)$user['id'];
$db     = db();

// ---- Parameters ----
$tab      = $_GET['tab']    ?? 'all';
$siteId   = (int)($_GET['site_id'] ?? 0);
$search   = trim($_GET['q'] ?? '');
$page     = max(1, (int)($_GET['p'] ?? 1));
$perPage  = 15;
$offset   = ($page - 1) * $perPage;

$validTabs = ['all', 'published', 'draft', 'scheduled', 'failed'];
if (!in_array($tab, $validTabs)) $tab = 'all';

// ---- Fetch sites for filter dropdown ----
$sites = $db->query("SELECT id, name FROM wp_sites WHERE status='active' ORDER BY name")->fetchAll();

// ---- Build WHERE clauses ----
$where  = [];
$params = [];

if (!$isAdm) {
    $where[]  = 'p.author_id = ?';
    $params[] = $userId;
}
if ($tab !== 'all') {
    $where[]  = 'p.status = ?';
    $params[] = $tab;
}
if ($siteId > 0) {
    $where[]  = 'p.site_id = ?';
    $params[] = $siteId;
}
if ($search !== '') {
    $where[]  = 'p.title LIKE ?';
    $params[] = '%' . $search . '%';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ---- Count totals per tab ----
function countTab(string $status, bool $isAdm, int $userId, int $siteId, string $search): int {
    $db = db();
    $w = []; $p = [];
    if (!$isAdm) { $w[] = 'p.author_id = ?'; $p[] = $userId; }
    if ($status !== 'all') { $w[] = 'p.status = ?'; $p[] = $status; }
    if ($siteId > 0) { $w[] = 'p.site_id = ?'; $p[] = $siteId; }
    if ($search !== '') { $w[] = 'p.title LIKE ?'; $p[] = '%' . $search . '%'; }
    $sql = 'SELECT COUNT(*) FROM posts p ' . ($w ? 'WHERE ' . implode(' AND ', $w) : '');
    $stmt = $db->prepare($sql); $stmt->execute($p);
    return (int)$stmt->fetchColumn();
}

$counts = [
    'all'       => countTab('all',       $isAdm, $userId, $siteId, $search),
    'published' => countTab('published', $isAdm, $userId, $siteId, $search),
    'draft'     => countTab('draft',     $isAdm, $userId, $siteId, $search),
    'scheduled' => countTab('scheduled', $isAdm, $userId, $siteId, $search),
    'failed'    => countTab('failed',    $isAdm, $userId, $siteId, $search),
];

// ---- Fetch posts ----
$sql = "SELECT p.*,
               u.full_name  AS author_name,
               s.name       AS site_name,
               ps.scheduled_at AS sched_at,
               ps.schedule_type AS sched_type
        FROM posts p
        LEFT JOIN users    u  ON p.author_id = u.id
        LEFT JOIN wp_sites s  ON p.site_id   = s.id
        LEFT JOIN post_schedules ps ON ps.post_id = p.id AND ps.is_active = 1
        {$whereSql}
        ORDER BY p.updated_at DESC
        LIMIT {$perPage} OFFSET {$offset}";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$posts = $stmt->fetchAll();

$totalPages = (int)ceil($counts[$tab] / $perPage);

$statusBadge = fn($s) => match($s) {
    'published'       => "<span class='badge badge-published'><i class='fas fa-circle-check'></i> Terbit</span>",
    'draft'           => "<span class='badge badge-draft'><i class='fas fa-circle-dot'></i> Draft</span>",
    'scheduled'       => "<span class='badge badge-scheduled'><i class='fas fa-clock'></i> Terjadwal</span>",
    'failed'          => "<span class='badge badge-failed'><i class='fas fa-circle-xmark'></i> Gagal</span>",
    'pending_publish' => "<span class='badge badge-pending'><i class='fas fa-hourglass-half'></i> Pending</span>",
    default           => "<span class='badge badge-draft'>" . sanitize($s) . "</span>",
};

$tabDefs     = ['all' => 'Semua', 'published' => 'Terbit', 'draft' => 'Draft', 'scheduled' => 'Terjadwal', 'failed' => 'Gagal'];
$baseUrl     = APP_URL . '/index.php?page=posts';
$filterQuery = ($siteId ? "&site_id={$siteId}" : '') . ($search ? '&q=' . urlencode($search) : '');

pageWrap('posts', 'Semua Posting', 'Kelola dan pantau semua artikel', function()
    use ($posts, $isAdm, $statusBadge, $tab, $counts, $tabDefs, $sites, $siteId, $search, $page, $totalPages, $baseUrl, $filterQuery) {
?>

<!-- Toolbar -->
<div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;justify-content:space-between;margin-bottom:1rem">
  <a href="<?= APP_URL ?>/index.php?page=new_post" class="btn btn-orange">
    <i class="fas fa-plus"></i> Tulis Artikel
  </a>
  <form method="GET" action="<?= APP_URL ?>/index.php" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
    <input type="hidden" name="page" value="posts">
    <input type="hidden" name="tab"  value="<?= sanitize($tab) ?>">
    <select name="site_id" class="form-control" style="width:auto" onchange="this.form.submit()">
      <option value="">Semua Situs</option>
      <?php foreach ($sites as $s): ?>
        <option value="<?= $s['id'] ?>" <?= $siteId == $s['id'] ? 'selected' : '' ?>><?= sanitize($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <div style="display:flex;gap:.25rem">
      <input type="text" name="q" value="<?= sanitize($search) ?>" placeholder="Cari judul..." class="form-control" style="width:200px">
      <button type="submit" class="btn btn-outline"><i class="fas fa-search"></i></button>
      <?php if ($search || $siteId): ?>
        <a href="<?= $baseUrl ?>&tab=<?= $tab ?>" class="btn btn-outline" title="Reset filter"><i class="fas fa-times"></i></a>
      <?php endif; ?>
    </div>
  </form>
</div>

<!-- Tab Navigation -->
<div class="card" style="margin-bottom:1rem;padding:0;overflow:hidden">
  <div style="display:flex;border-bottom:1.5px solid var(--border);overflow-x:auto">
    <?php foreach ($tabDefs as $key => $label):
        $active = $tab === $key;
        $cnt    = $counts[$key];
    ?>
    <a href="<?= $baseUrl ?>&tab=<?= $key ?><?= $filterQuery ?>"
       style="padding:.75rem 1.25rem;font-size:.875rem;font-weight:<?= $active ? '700' : '500' ?>;
              color:<?= $active ? 'var(--navy)' : 'var(--text-muted)' ?>;
              border-bottom:<?= $active ? '2px solid var(--navy)' : '2px solid transparent' ?>;
              white-space:nowrap;text-decoration:none;display:inline-flex;align-items:center;gap:.4rem;margin-bottom:-1.5px">
      <?= $label ?>
      <span style="background:<?= $active ? 'var(--navy)' : 'var(--border)' ?>;
                   color:<?= $active ? '#fff' : 'var(--text-muted)' ?>;
                   border-radius:20px;padding:.1rem .45rem;font-size:.72rem;font-weight:700">
        <?= $cnt ?>
      </span>
    </a>
    <?php endforeach; ?>
  </div>
</div>

<!-- Posts Table -->
<div class="card">
  <?php if ($posts): ?>
  <form method="POST" action="<?= APP_URL ?>/actions/post_action.php" id="bulk-form" data-loading>
    <input type="hidden" name="action"     value="bulk_delete">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

    <!-- Bulk action bar (hidden until rows selected) -->
    <div id="bulk-bar" style="display:none;padding:.75rem 1rem;background:var(--navy-xlight);border-bottom:1px solid var(--border);align-items:center;gap:.75rem">
      <span id="bulk-count" style="font-size:.875rem;font-weight:600;color:var(--navy)">0 dipilih</span>
      <button type="submit" class="btn btn-sm" style="background:#ef4444;color:#fff;border:none"
              data-confirm="Hapus semua posting yang dipilih? Tindakan ini tidak dapat dibatalkan.">
        <i class="fas fa-trash"></i> Hapus yang Dipilih
      </button>
    </div>

    <div class="table-responsive">
      <table class="table">
        <thead>
          <tr>
            <th style="width:36px">
              <input type="checkbox" id="select-all" title="Pilih semua" style="cursor:pointer">
            </th>
            <th>Judul</th>
            <?php if ($isAdm): ?><th>Penulis</th><?php endif; ?>
            <th>Situs</th>
            <th>Status</th>
            <th>Tanggal</th>
            <th style="width:120px">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($posts as $post): ?>
          <tr>
            <td><input type="checkbox" name="post_ids[]" value="<?= $post['id'] ?>" class="row-check" style="cursor:pointer"></td>
            <td>
              <div style="font-weight:600;max-width:280px" class="truncate"><?= sanitize($post['title']) ?></div>
              <?php if ($post['status'] === 'failed' && $post['error_message']): ?>
              <div style="color:#ef4444;font-size:.75rem;margin-top:.2rem">
                <i class="fas fa-circle-exclamation"></i>
                <?= sanitize(mb_substr($post['error_message'], 0, 80)) ?>
              </div>
              <?php endif; ?>
              <?php if ($post['slug']): ?>
              <div style="font-size:.75rem;color:var(--text-muted);margin-top:.15rem">/<?= sanitize($post['slug']) ?></div>
              <?php endif; ?>
            </td>
            <?php if ($isAdm): ?>
            <td><span style="font-size:.8rem;color:var(--text-muted)"><?= sanitize($post['author_name'] ?? '-') ?></span></td>
            <?php endif; ?>
            <td><span style="font-size:.8rem;color:var(--text-muted)"><?= $post['site_name'] ? sanitize($post['site_name']) : '<em>Belum ditentukan</em>' ?></span></td>
            <td><?= $statusBadge($post['status']) ?></td>
            <td>
              <?php if ($post['status'] === 'scheduled' && $post['sched_at']): ?>
                <span style="font-size:.78rem;color:var(--text-muted)">
                  <i class="fas fa-clock" style="color:var(--orange)"></i>
                  <?= formatDate($post['sched_at'], 'd M Y') ?><br>
                  <span style="font-size:.72rem"><?= formatDate($post['sched_at'], 'H:i') ?></span>
                </span>
              <?php elseif ($post['published_at']): ?>
                <span style="font-size:.78rem;color:var(--text-muted)">
                  <?= formatDate($post['published_at'], 'd M Y') ?><br>
                  <span style="font-size:.72rem"><?= formatDate($post['published_at'], 'H:i') ?></span>
                </span>
              <?php else: ?>
                <span style="font-size:.78rem;color:var(--text-muted)"><?= timeAgo($post['updated_at']) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <div style="display:flex;gap:.35rem;flex-wrap:wrap">
                <a href="<?= APP_URL ?>/index.php?page=edit_post&id=<?= $post['id'] ?>"
                   class="btn btn-outline btn-sm btn-icon" title="Edit">
                  <i class="fas fa-pen"></i>
                </a>
                <?php if ($post['status'] === 'published' && $post['wp_post_url']): ?>
                <a href="<?= sanitize($post['wp_post_url']) ?>" target="_blank"
                   class="btn btn-outline btn-sm btn-icon" title="Lihat di WordPress">
                  <i class="fas fa-arrow-up-right-from-square"></i>
                </a>
                <?php endif; ?>
                <a href="<?= APP_URL ?>/actions/post_action.php?action=delete&id=<?= $post['id'] ?>&csrf_token=<?= csrfToken() ?>"
                   class="btn btn-sm btn-icon" style="background:#fef2f2;color:#ef4444;border:1px solid #fecaca" title="Hapus"
                   data-confirm="Hapus artikel ini? Tindakan tidak dapat dibatalkan.">
                  <i class="fas fa-trash"></i>
                </a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </form>
  <?php else: ?>
  <div class="empty-state">
    <i class="fas fa-file-circle-plus"></i>
    <h4>Belum ada artikel<?= isset($tabDefs[$tab]) && $tab !== 'all' ? ' ' . $tabDefs[$tab] : '' ?></h4>
    <p>
      <?php if ($search): ?>Tidak ada artikel dengan kata kunci "<strong><?= sanitize($search) ?></strong>"
      <?php elseif ($tab !== 'all'): ?>Belum ada artikel dengan status ini.
      <?php else: ?>Mulai tulis artikel pertama Anda.<?php endif; ?>
    </p>
    <a href="<?= APP_URL ?>/index.php?page=new_post" class="btn btn-orange">
      <i class="fas fa-plus"></i> Tulis Artikel
    </a>
  </div>
  <?php endif; ?>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div style="display:flex;justify-content:center;gap:.4rem;margin-top:1.5rem;flex-wrap:wrap">
  <?php if ($page > 1): ?>
  <a href="<?= $baseUrl ?>&tab=<?= $tab ?><?= $filterQuery ?>&p=<?= $page-1 ?>" class="btn btn-outline btn-sm"><i class="fas fa-chevron-left"></i></a>
  <?php endif; ?>
  <?php
    $start = max(1, $page - 2); $end = min($totalPages, $page + 2);
    if ($start > 1) {
        echo "<a href='{$baseUrl}&tab={$tab}{$filterQuery}&p=1' class='btn btn-outline btn-sm'>1</a>";
        if ($start > 2) echo "<span style='padding:.4rem .6rem;color:var(--text-muted)'>…</span>";
    }
    for ($i = $start; $i <= $end; $i++) {
        echo "<a href='{$baseUrl}&tab={$tab}{$filterQuery}&p={$i}' class='btn btn-sm " . ($i===$page?'btn-orange':'btn-outline') . "'>{$i}</a>";
    }
    if ($end < $totalPages) {
        if ($end < $totalPages-1) echo "<span style='padding:.4rem .6rem;color:var(--text-muted)'>…</span>";
        echo "<a href='{$baseUrl}&tab={$tab}{$filterQuery}&p={$totalPages}' class='btn btn-outline btn-sm'>{$totalPages}</a>";
    }
  ?>
  <?php if ($page < $totalPages): ?>
  <a href="<?= $baseUrl ?>&tab=<?= $tab ?><?= $filterQuery ?>&p=<?= $page+1 ?>" class="btn btn-outline btn-sm"><i class="fas fa-chevron-right"></i></a>
  <?php endif; ?>
</div>
<div style="text-align:center;margin-top:.5rem;font-size:.8rem;color:var(--text-muted)">
  Halaman <?= $page ?> dari <?= $totalPages ?> (<?= $counts[$tab] ?> artikel)
</div>
<?php endif; ?>

<script>
(function(){
  const selectAll = document.getElementById('select-all');
  const rowChecks = document.querySelectorAll('.row-check');
  const bulkBar   = document.getElementById('bulk-bar');
  const bulkCount = document.getElementById('bulk-count');

  function updateBulkBar() {
    const checked = document.querySelectorAll('.row-check:checked').length;
    if (bulkBar) { bulkBar.style.display = checked > 0 ? 'flex' : 'none'; }
    if (bulkCount) bulkCount.textContent = checked + ' dipilih';
    if (selectAll) selectAll.indeterminate = checked > 0 && checked < rowChecks.length;
  }

  if (selectAll) {
    selectAll.addEventListener('change', () => {
      rowChecks.forEach(c => c.checked = selectAll.checked);
      updateBulkBar();
    });
  }
  rowChecks.forEach(c => c.addEventListener('change', () => {
    if (selectAll) selectAll.checked = [...rowChecks].every(r => r.checked);
    updateBulkBar();
  }));
})();
</script>

<?php
});
