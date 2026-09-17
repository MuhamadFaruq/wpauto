<?php
// ================================================
// pages/activity.php — Activity Log (Admin)
// ================================================
require_once __DIR__ . '/../includes/layout.php';
requireAdmin();

$db = db();
$perPage = 20;

// Filters
$filterUser = isset($_GET['user_id']) && is_numeric($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$filterFrom = $_GET['from'] ?? '';
$filterTo   = $_GET['to']   ?? '';
$page       = max(1, (int)($_GET['p'] ?? 1));

// Build WHERE clauses
$where  = [];
$params = [];

if ($filterUser) {
    $where[]  = 'a.user_id = ?';
    $params[] = $filterUser;
}
if ($filterFrom) {
    $where[]  = 'a.created_at >= ?';
    $params[] = $filterFrom . ' 00:00:00';
}
if ($filterTo) {
    $where[]  = 'a.created_at <= ?';
    $params[] = $filterTo . ' 23:59:59';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Total rows
$countStmt = $db->prepare("SELECT COUNT(*) FROM activity_log a $whereSQL");
$countStmt->execute($params);
$total     = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// Fetch rows
$logStmt = $db->prepare(
    "SELECT a.*, u.full_name, u.username
     FROM activity_log a
     LEFT JOIN users u ON a.user_id = u.id
     $whereSQL
     ORDER BY a.created_at DESC
     LIMIT $perPage OFFSET $offset"
);
$logStmt->execute($params);
$logs = $logStmt->fetchAll();

// Users for filter dropdown
$allUsers = $db->query("SELECT id, full_name, username FROM users ORDER BY full_name")->fetchAll();

// Build filter query string helper
$filterQS = function(array $extra = []) use ($filterUser, $filterFrom, $filterTo): string {
    $p = [];
    if ($filterUser)  $p['user_id'] = $filterUser;
    if ($filterFrom)  $p['from']    = $filterFrom;
    if ($filterTo)    $p['to']      = $filterTo;
    $merged = array_merge($p, $extra);
    return $merged ? '&' . http_build_query($merged) : '';
};

// Action icon map
$iconMap = [
    'login'           => ['sign-in-alt',   '#22c55e', '#f0fdf4'],
    'logout'          => ['sign-out-alt',   '#64748b', '#f1f5f9'],
    'post_create'     => ['pen',            '#3b82f6', '#eff6ff'],
    'post_update'     => ['floppy-disk',    '#f97316', '#fff3eb'],
    'post_publish'    => ['circle-check',   '#22c55e', '#f0fdf4'],
    'post_delete'     => ['trash',          '#ef4444', '#fef2f2'],
    'post_schedule'   => ['clock',          '#8b5cf6', '#f5f3ff'],
    'site_add'        => ['globe',          '#f97316', '#fff3eb'],
    'site_edit'       => ['pen-to-square',  '#3b82f6', '#eff6ff'],
    'site_delete'     => ['globe-slash',    '#ef4444', '#fef2f2'],
    'user_add'        => ['user-plus',      '#3b82f6', '#eff6ff'],
    'user_edit'       => ['user-pen',       '#f97316', '#fff3eb'],
    'user_delete'     => ['user-minus',     '#ef4444', '#fef2f2'],
    'mapping_save'    => ['user-tag',       '#8b5cf6', '#f5f3ff'],
    'mapping_delete'  => ['user-tag',       '#ef4444', '#fef2f2'],
    'profile_update'  => ['circle-user',    '#3b82f6', '#eff6ff'],
    'password_change' => ['key',            '#64748b', '#f1f5f9'],
];

pageWrap('activity', 'Log Aktivitas', 'Rekam jejak semua aksi di dashboard', function()
    use ($logs, $allUsers, $total, $totalPages, $page, $perPage, $filterUser, $filterFrom, $filterTo, $filterQS, $iconMap) {
?>

<!-- Filter bar + export -->
<div class="card" style="margin-bottom:1.25rem;padding:1rem 1.25rem">
  <form method="GET" action="index.php" style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end">
    <input type="hidden" name="page" value="activity">

    <div class="form-group" style="margin:0;flex:1;min-width:160px">
      <label class="form-label" style="font-size:.8rem">User</label>
      <select name="user_id" class="form-control form-select" style="height:38px;padding:.3rem .65rem">
        <option value="">Semua User</option>
        <?php foreach ($allUsers as $u): ?>
        <option value="<?= $u['id'] ?>" <?= $filterUser === (int)$u['id'] ? 'selected' : '' ?>>
          <?= sanitize($u['full_name']) ?> (@<?= sanitize($u['username']) ?>)
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group" style="margin:0;flex:1;min-width:140px">
      <label class="form-label" style="font-size:.8rem">Dari Tanggal</label>
      <input type="date" name="from" class="form-control" style="height:38px"
             value="<?= sanitize($filterFrom) ?>">
    </div>

    <div class="form-group" style="margin:0;flex:1;min-width:140px">
      <label class="form-label" style="font-size:.8rem">Sampai Tanggal</label>
      <input type="date" name="to" class="form-control" style="height:38px"
             value="<?= sanitize($filterTo) ?>">
    </div>

    <div style="display:flex;gap:.5rem">
      <button type="submit" class="btn btn-orange" style="height:38px">
        <i class="fas fa-filter"></i> Filter
      </button>
      <a href="index.php?page=activity" class="btn btn-outline" style="height:38px" title="Reset filter">
        <i class="fas fa-rotate-left"></i>
      </a>
      <a href="actions/post_action.php?action=export_activity<?= $filterQS() ?>"
         class="btn btn-outline" style="height:38px" title="Export CSV">
        <i class="fas fa-file-csv"></i> Export CSV
      </a>
    </div>
  </form>
</div>

<!-- Results info -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem">
  <span style="font-size:.85rem;color:var(--text-muted)">
    Menampilkan <?= number_format($total) ?> aktivitas
    <?= $filterUser || $filterFrom || $filterTo ? '(dengan filter aktif)' : '' ?>
  </span>
  <span style="font-size:.85rem;color:var(--text-muted)">
    Halaman <?= $page ?> dari <?= $totalPages ?>
  </span>
</div>

<!-- Table -->
<div class="card">
  <div class="table-responsive">
    <?php if ($logs): ?>
    <table class="table">
      <thead>
        <tr>
          <th style="width:160px">Waktu</th>
          <th>User</th>
          <th style="width:150px">Aksi</th>
          <th>Detail</th>
          <th style="width:130px">IP Address</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($logs as $log):
          $ic = $iconMap[$log['action']] ?? ['circle', '#94a3b8', '#f1f5f9'];
        ?>
        <tr>
          <td>
            <div style="font-size:.82rem;color:var(--text-muted)"><?= formatDate($log['created_at'], 'd M Y') ?></div>
            <div style="font-size:.82rem;font-weight:600"><?= formatDate($log['created_at'], 'H:i:s') ?></div>
          </td>
          <td>
            <?php if ($log['full_name']): ?>
              <div style="font-weight:600;font-size:.875rem"><?= sanitize($log['full_name']) ?></div>
              <small style="color:var(--text-muted)">@<?= sanitize($log['username'] ?? '') ?></small>
            <?php else: ?>
              <span style="color:var(--text-muted);font-size:.875rem">System</span>
            <?php endif; ?>
          </td>
          <td>
            <div style="display:flex;align-items:center;gap:.4rem">
              <span style="width:26px;height:26px;border-radius:50%;background:<?= $ic[2] ?>;color:<?= $ic[1] ?>;
                           display:flex;align-items:center;justify-content:center;font-size:.7rem;flex-shrink:0">
                <i class="fas fa-<?= $ic[0] ?>"></i>
              </span>
              <code style="font-size:.75rem;background:var(--surface-alt);padding:.1rem .4rem;border-radius:4px;word-break:break-all">
                <?= sanitize($log['action']) ?>
              </code>
            </div>
          </td>
          <td style="font-size:.875rem;max-width:320px">
            <?= sanitize($log['details'] ?? '-') ?>
          </td>
          <td>
            <code style="font-size:.8rem"><?= sanitize($log['ip_address'] ?? '-') ?></code>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="empty-state">
      <i class="fas fa-clock-rotate-left"></i>
      <h4>Belum ada log aktivitas</h4>
      <p>Aktivitas akan tercatat secara otomatis.</p>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div style="display:flex;justify-content:center;gap:.5rem;margin-top:1.25rem;flex-wrap:wrap">
  <?php if ($page > 1): ?>
  <a href="index.php?page=activity&p=<?= $page - 1 ?><?= $filterQS() ?>"
     class="btn btn-outline btn-sm"><i class="fas fa-chevron-left"></i></a>
  <?php endif; ?>

  <?php
  $start = max(1, $page - 2);
  $end   = min($totalPages, $page + 2);
  if ($start > 1): ?>
    <a href="index.php?page=activity&p=1<?= $filterQS() ?>" class="btn btn-outline btn-sm">1</a>
    <?php if ($start > 2): ?><span style="padding:.3rem .5rem;color:var(--text-muted)">…</span><?php endif; ?>
  <?php endif; ?>

  <?php for ($i = $start; $i <= $end; $i++): ?>
  <a href="index.php?page=activity&p=<?= $i ?><?= $filterQS() ?>"
     class="btn btn-sm <?= $i === $page ? 'btn-orange' : 'btn-outline' ?>"><?= $i ?></a>
  <?php endfor; ?>

  <?php if ($end < $totalPages): ?>
    <?php if ($end < $totalPages - 1): ?><span style="padding:.3rem .5rem;color:var(--text-muted)">…</span><?php endif; ?>
    <a href="index.php?page=activity&p=<?= $totalPages ?><?= $filterQS() ?>"
       class="btn btn-outline btn-sm"><?= $totalPages ?></a>
  <?php endif; ?>

  <?php if ($page < $totalPages): ?>
  <a href="index.php?page=activity&p=<?= $page + 1 ?><?= $filterQS() ?>"
     class="btn btn-outline btn-sm"><i class="fas fa-chevron-right"></i></a>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php
});
