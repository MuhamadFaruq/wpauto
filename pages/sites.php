<?php
// ================================================
// pages/sites.php — Manage WordPress Sites (Admin)
// ================================================
require_once __DIR__ . '/../includes/layout.php';
requireAdmin();

$db    = db();
$sites = $db->query(
    "SELECT s.*, u.full_name as added_by_name FROM wp_sites s
     LEFT JOIN users u ON s.added_by=u.id ORDER BY s.created_at DESC"
)->fetchAll();

$appUrl = APP_URL;

pageWrap('sites', 'Kelola Situs WordPress', 'Tambah dan kelola koneksi ke situs WordPress', function() use ($sites, $appUrl) {
?>
<div class="d-flex justify-between align-center mb-3">
  <p style="color:var(--text-muted);font-size:.875rem"><?= count($sites) ?> situs terdaftar</p>
  <button class="btn btn-orange" data-modal-open="modal-add-site">
    <i class="fas fa-plus"></i> Tambah Situs
  </button>
</div>

<?php if ($sites): ?>
<div class="sites-grid">
  <?php foreach ($sites as $site): ?>
  <div class="site-card">
    <div class="site-card-header">
      <div class="site-favicon">
        <img src="<?= sanitize(rtrim($site['url'], '/')) ?>/favicon.ico"
             onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"
             alt="favicon" style="width:100%;height:100%;object-fit:contain">
        <span style="display:none;width:100%;height:100%;align-items:center;justify-content:center">
          <i class="fas fa-wordpress"></i>
        </span>
      </div>
      <div class="site-info">
        <h4><?= sanitize($site['name']) ?></h4>
        <a href="<?= sanitize($site['url']) ?>" target="_blank">
          <?= sanitize(parse_url($site['url'], PHP_URL_HOST) ?? $site['url']) ?>
          <i class="fas fa-arrow-up-right-from-square" style="font-size:.65rem"></i>
        </a>
      </div>
      <span class="badge <?= $site['status']==='active' ? 'badge-active' : 'badge-inactive' ?>" style="margin-left:auto">
        <?= $site['status']==='active' ? 'Aktif' : 'Nonaktif' ?>
      </span>
    </div>

    <div style="font-size:.8rem;color:var(--text-muted)">
      <div><i class="fas fa-user" style="width:16px"></i> API User: <strong><?= sanitize($site['app_username']) ?></strong></div>
      <div><i class="fas fa-code" style="width:16px"></i> API: <span class="truncate" style="max-width:200px;display:inline-block;vertical-align:middle">
        <?= sanitize(str_replace('/wp-json/wp/v2', '', $site['api_base_url'])) ?>
      </span></div>
      <div><i class="fas fa-user-plus" style="width:16px"></i> Ditambahkan oleh: <?= sanitize($site['added_by_name'] ?? 'System') ?></div>
    </div>

    <div class="site-actions">
      <button class="btn btn-outline btn-sm" onclick="openEditSite(<?= htmlspecialchars(json_encode($site)) ?>)">
        <i class="fas fa-pen"></i> Edit
      </button>
      <form method="POST" action="actions/site_action.php?action=delete" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="site_id" value="<?= $site['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm"
                data-confirm="Hapus situs <?= sanitize($site['name']) ?>? Semua mapping author juga akan dihapus.">
          <i class="fas fa-trash"></i>
        </button>
      </form>
      <a href="<?= sanitize($site['api_base_url']) ?>" target="_blank" class="btn btn-outline btn-sm" title="Test API">
        <i class="fas fa-plug"></i>
      </a>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php else: ?>
<div class="card">
  <div class="empty-state">
    <i class="fas fa-globe"></i>
    <h4>Belum ada situs WordPress</h4>
    <p>Tambahkan situs WordPress pertama Anda untuk mulai mengelola konten dari satu dasbor.</p>
    <button class="btn btn-orange" data-modal-open="modal-add-site">
      <i class="fas fa-plus"></i> Tambah Situs Pertama
    </button>
  </div>
</div>
<?php endif; ?>

<!-- Info Card: cara mendapatkan Application Password -->
<div class="card" style="margin-top:1.5rem;border-left:4px solid var(--orange)">
  <div class="card-body" style="display:flex;gap:1rem;align-items:flex-start">
    <i class="fas fa-circle-info" style="color:var(--orange);font-size:1.25rem;flex-shrink:0;margin-top:.1rem"></i>
    <div>
      <strong style="font-size:.875rem">Cara mendapatkan Application Password WordPress</strong>
      <p style="font-size:.8rem;color:var(--text-muted);margin-top:.25rem">
        Masuk ke WordPress Admin → Users → Profile → Application Passwords → buat password baru.
        Gunakan username WordPress dan password aplikasi tersebut di sini.
        API Base URL biasanya: <code style="background:var(--surface2);padding:.1rem .3rem;border-radius:4px">https://situs.com/wp-json/wp/v2</code>
      </p>
    </div>
  </div>
</div>

<!-- ====== MODAL: Add Site ====== -->
<div class="modal-overlay" id="modal-add-site">
  <div class="modal" style="max-width:600px">
    <div class="modal-header">
      <h4><i class="fas fa-plus-circle" style="color:var(--orange);margin-right:.4rem"></i> Tambah Situs WordPress</h4>
      <button class="modal-close"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="actions/site_action.php?action=add">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <div class="modal-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
          <div class="form-group">
            <label>Nama Situs <span style="color:var(--danger)">*</span></label>
            <input type="text" name="name" class="form-control" placeholder="Blog Utama" required>
          </div>
          <div class="form-group">
            <label>Status</label>
            <select name="status" class="form-control form-select">
              <option value="active">Aktif</option>
              <option value="inactive">Nonaktif</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>URL Situs <span style="color:var(--danger)">*</span></label>
          <input type="url" name="url" id="add-site-url" class="form-control"
                 placeholder="https://blog.example.com" required oninput="autoFillApi(this.value)">
        </div>
        <div class="form-group">
          <label>API Base URL <span style="color:var(--danger)">*</span></label>
          <input type="url" name="api_base_url" id="api_base_url" class="form-control"
                 placeholder="https://blog.example.com/wp-json/wp/v2" required>
          <p class="form-hint">Biasanya URL situs + /wp-json/wp/v2</p>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
          <div class="form-group">
            <label>WP Username <span style="color:var(--danger)">*</span></label>
            <input type="text" name="app_username" id="app_username" class="form-control"
                   placeholder="admin" required autocomplete="off">
          </div>
          <div class="form-group">
            <label>Application Password <span style="color:var(--danger)">*</span></label>
            <div style="position:relative">
              <input type="password" name="app_password" id="app_password" class="form-control"
                     placeholder="xxxx xxxx xxxx xxxx" required autocomplete="off">
              <button type="button" onclick="togglePass('app_password')"
                      style="position:absolute;right:.75rem;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer">
                <i class="fas fa-eye" id="eye-app_password"></i>
              </button>
            </div>
          </div>
        </div>
        <button type="button" id="test-connection-btn" class="btn btn-outline btn-sm">
          <i class="fas fa-plug"></i> Test Koneksi
        </button>
        <div id="connection-result" class="hidden"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" data-modal-close>Batal</button>
        <button type="submit" class="btn btn-orange"><i class="fas fa-save"></i> Simpan Situs</button>
      </div>
    </form>
  </div>
</div>

<!-- ====== MODAL: Edit Site ====== -->
<div class="modal-overlay" id="modal-edit-site">
  <div class="modal" style="max-width:600px">
    <div class="modal-header">
      <h4><i class="fas fa-pen" style="color:var(--navy);margin-right:.4rem"></i> Edit Situs WordPress</h4>
      <button class="modal-close"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="actions/site_action.php?action=edit">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="site_id" id="edit-site-id">
      <div class="modal-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
          <div class="form-group">
            <label>Nama Situs *</label>
            <input type="text" name="name" id="edit-name" class="form-control" required>
          </div>
          <div class="form-group">
            <label>Status</label>
            <select name="status" id="edit-status" class="form-control form-select">
              <option value="active">Aktif</option>
              <option value="inactive">Nonaktif</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>URL Situs *</label>
          <input type="url" name="url" id="edit-url" class="form-control" required>
        </div>
        <div class="form-group">
          <label>API Base URL *</label>
          <input type="url" name="api_base_url" id="edit-api" class="form-control" required>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
          <div class="form-group">
            <label>WP Username *</label>
            <input type="text" name="app_username" id="edit-username" class="form-control" required autocomplete="off">
          </div>
          <div class="form-group">
            <label>Application Password (kosongkan jika tidak diubah)</label>
            <div style="position:relative">
              <input type="password" name="app_password" id="edit-password" class="form-control" autocomplete="off">
              <button type="button" onclick="togglePass('edit-password')"
                      style="position:absolute;right:.75rem;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer">
                <i class="fas fa-eye" id="eye-edit-password"></i>
              </button>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" data-modal-close>Batal</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Perubahan</button>
      </div>
    </form>
  </div>
</div>

<script>
function autoFillApi(url) {
  const apiField = document.getElementById('api_base_url');
  if (url && !apiField.value) {
    apiField.value = url.replace(/\/$/, '') + '/wp-json/wp/v2';
  }
}

function openEditSite(data) {
  document.getElementById('edit-site-id').value = data.id;
  document.getElementById('edit-name').value     = data.name;
  document.getElementById('edit-url').value      = data.url;
  document.getElementById('edit-api').value      = data.api_base_url;
  document.getElementById('edit-username').value = data.app_username;
  document.getElementById('edit-status').value   = data.status;
  document.getElementById('edit-password').value = '';
  document.getElementById('modal-edit-site').classList.add('show');
  document.body.style.overflow = 'hidden';
}

function togglePass(id) {
  const inp  = document.getElementById(id);
  const icon = document.getElementById('eye-' + id);
  if (inp.type === 'password') { inp.type = 'text'; icon.className = 'fas fa-eye-slash'; }
  else { inp.type = 'password'; icon.className = 'fas fa-eye'; }
}
</script>
<?php
});
