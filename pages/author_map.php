<?php
// ================================================
// pages/author_map.php — Author Mapping (Admin)
// ================================================
require_once __DIR__ . '/../includes/layout.php';
requireAdmin();

$db = db();

// All existing mappings with joined names
$mappings = $db->query(
    "SELECT m.*, u.full_name AS user_name, u.username, s.name AS site_name, s.url AS site_url
     FROM wp_author_map m
     JOIN users u ON m.user_id = u.id
     JOIN wp_sites s ON m.site_id = s.id
     ORDER BY m.created_at DESC"
)->fetchAll();

// Writers for the dropdown
$writers = $db->query(
    "SELECT id, full_name, username FROM users WHERE role = 'writer' AND is_active = 1 ORDER BY full_name"
)->fetchAll();

// Active sites for the dropdown
$sites = $db->query(
    "SELECT id, name, url FROM wp_sites WHERE status = 'active' ORDER BY name"
)->fetchAll();

// Load mapping for editing
$editMap = null;
if (!empty($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM wp_author_map WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $editMap = $stmt->fetch() ?: null;
}

pageWrap('author_map', 'Mapping Author', 'Hubungkan user platform dengan author WordPress', function() use ($mappings, $writers, $sites, $editMap) {
?>

<!-- Header -->
<div style="display:flex;justify-content:flex-end;margin-bottom:1.25rem">
  <button class="btn btn-orange" data-modal-open="modal-add-map">
    <i class="fas fa-plus"></i> Tambah Mapping
  </button>
</div>

<!-- Mapping Table -->
<div class="card">
  <div class="card-header">
    <h3><i class="fas fa-user-tag" style="color:var(--navy);margin-right:.4rem"></i> Daftar Mapping Author</h3>
    <span class="badge badge-draft" style="font-size:.8rem"><?= count($mappings) ?> mapping</span>
  </div>
  <div class="table-responsive">
    <?php if ($mappings): ?>
    <table class="table">
      <thead>
        <tr>
          <th>Platform User</th>
          <th>Situs WordPress</th>
          <th>WP Author ID</th>
          <th>WP Author Name</th>
          <th style="width:130px">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($mappings as $m): ?>
        <tr>
          <td>
            <div style="font-weight:600"><?= sanitize($m['user_name']) ?></div>
            <small style="color:var(--text-muted)">@<?= sanitize($m['username']) ?></small>
          </td>
          <td>
            <div style="font-weight:600"><?= sanitize($m['site_name']) ?></div>
            <a href="<?= sanitize($m['site_url']) ?>" target="_blank" rel="noopener"
               style="font-size:.75rem;color:var(--text-muted)"><?= sanitize($m['site_url']) ?></a>
          </td>
          <td>
            <code style="font-size:.85rem"><?= (int)$m['wp_author_id'] ?></code>
          </td>
          <td><?= sanitize($m['wp_author_name']) ?></td>
          <td>
            <div style="display:flex;gap:.35rem">
              <a href="index.php?page=author_map&edit=<?= $m['id'] ?>"
                 class="btn btn-outline btn-sm" title="Edit">
                <i class="fas fa-pen"></i>
              </a>
              <a href="actions/site_action.php?action=delete_mapping&id=<?= $m['id'] ?>"
                 class="btn btn-danger btn-sm"
                 data-confirm="Hapus mapping '<?= sanitize($m['user_name']) ?> → <?= sanitize($m['site_name']) ?>'?">
                <i class="fas fa-trash"></i>
              </a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="empty-state">
      <i class="fas fa-user-tag"></i>
      <h4>Belum ada mapping</h4>
      <p>Hubungkan akun penulis dengan author di situs WordPress.</p>
      <button class="btn btn-orange" data-modal-open="modal-add-map">
        <i class="fas fa-plus"></i> Tambah Mapping
      </button>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ============================================================ -->
<!-- ADD MAPPING MODAL                                             -->
<!-- ============================================================ -->
<div class="modal-overlay" id="modal-add-map">
  <div class="modal" style="max-width:500px">
    <div class="modal-header">
      <h3><i class="fas fa-user-tag" style="color:var(--orange)"></i> Tambah Mapping Author</h3>
      <button class="modal-close"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="actions/site_action.php" data-loading>
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="save_mapping">

        <div class="form-group">
          <label class="form-label">Platform User (Penulis) <span style="color:var(--danger)">*</span></label>
          <select name="user_id" class="form-control form-select" required>
            <option value="">-- Pilih Penulis --</option>
            <?php foreach ($writers as $w): ?>
            <option value="<?= $w['id'] ?>"><?= sanitize($w['full_name']) ?> (@<?= sanitize($w['username']) ?>)</option>
            <?php endforeach; ?>
          </select>
          <?php if (!$writers): ?>
          <div class="form-hint" style="color:var(--warning)">
            <i class="fas fa-triangle-exclamation"></i> Belum ada user dengan role Penulis.
            <a href="index.php?page=users">Tambah user</a> dahulu.
          </div>
          <?php endif; ?>
        </div>

        <div class="form-group">
          <label class="form-label">Situs WordPress <span style="color:var(--danger)">*</span></label>
          <select name="site_id" id="map-site-select" class="form-control form-select" required>
            <option value="">-- Pilih Situs --</option>
            <?php foreach ($sites as $s): ?>
            <option value="<?= $s['id'] ?>"><?= sanitize($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!$sites): ?>
          <div class="form-hint" style="color:var(--warning)">
            <i class="fas fa-triangle-exclamation"></i> Belum ada situs aktif.
            <a href="index.php?page=sites">Tambah situs</a> dahulu.
          </div>
          <?php endif; ?>
        </div>

        <div class="form-group">
          <label class="form-label">WP Author <span style="color:var(--danger)">*</span></label>
          <select name="wp_author_id" id="map-wp-author" class="form-control form-select" required>
            <option value="">-- Pilih Situs Dulu --</option>
          </select>
          <div class="form-hint">Author dimuat otomatis setelah situs dipilih</div>
        </div>

        <div class="form-group">
          <label class="form-label">Nama WP Author</label>
          <input type="text" name="wp_author_name" id="map-wp-author-name" class="form-control"
                 placeholder="Diisi otomatis" readonly style="background:var(--surface-alt)">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" data-modal-close>Batal</button>
        <button type="submit" class="btn btn-orange">
          <i class="fas fa-save"></i> Simpan Mapping
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================ -->
<!-- EDIT MAPPING MODAL                                            -->
<!-- ============================================================ -->
<?php if ($editMap): ?>
<div class="modal-overlay show" id="modal-edit-map">
  <div class="modal" style="max-width:500px">
    <div class="modal-header">
      <h3><i class="fas fa-pen" style="color:var(--navy)"></i> Edit Mapping Author</h3>
      <a href="index.php?page=author_map" class="modal-close"><i class="fas fa-times"></i></a>
    </div>
    <form method="POST" action="actions/site_action.php" data-loading>
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="save_mapping">
        <input type="hidden" name="id" value="<?= (int)$editMap['id'] ?>">

        <div class="form-group">
          <label class="form-label">Platform User (Penulis) <span style="color:var(--danger)">*</span></label>
          <select name="user_id" class="form-control form-select" required>
            <option value="">-- Pilih Penulis --</option>
            <?php foreach ($writers as $w): ?>
            <option value="<?= $w['id'] ?>" <?= $w['id'] == $editMap['user_id'] ? 'selected' : '' ?>>
              <?= sanitize($w['full_name']) ?> (@<?= sanitize($w['username']) ?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Situs WordPress <span style="color:var(--danger)">*</span></label>
          <select name="site_id" id="map-site-select-edit" class="form-control form-select" required>
            <option value="">-- Pilih Situs --</option>
            <?php foreach ($sites as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $s['id'] == $editMap['site_id'] ? 'selected' : '' ?>>
              <?= sanitize($s['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">WP Author ID <span style="color:var(--danger)">*</span></label>
          <input type="number" name="wp_author_id" id="map-wp-author-id-edit" class="form-control"
                 value="<?= (int)$editMap['wp_author_id'] ?>" required min="1">
          <div class="form-hint">Masukkan ID author WordPress secara manual, atau pilih dari dropdown di bawah.</div>
        </div>

        <div class="form-group">
          <label class="form-label">Muat Author dari Situs</label>
          <select id="map-wp-author-edit" class="form-control form-select">
            <option value="">-- Klik Muat Author --</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Nama WP Author <span style="color:var(--danger)">*</span></label>
          <input type="text" name="wp_author_name" id="map-wp-author-name-edit" class="form-control"
                 value="<?= sanitize($editMap['wp_author_name']) ?>" required>
        </div>
      </div>
      <div class="modal-footer">
        <a href="index.php?page=author_map" class="btn btn-outline">Batal</a>
        <button type="submit" class="btn btn-orange">
          <i class="fas fa-save"></i> Simpan Perubahan
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// Wire up edit-modal site select → load authors
(function() {
  const siteSelect  = document.getElementById('map-site-select-edit');
  const authorSel   = document.getElementById('map-wp-author-edit');
  const authorId    = document.getElementById('map-wp-author-id-edit');
  const authorName  = document.getElementById('map-wp-author-name-edit');
  if (!siteSelect || !authorSel) return;

  async function loadAuthors(siteId) {
    if (!siteId) { authorSel.innerHTML = '<option value="">-- Pilih Situs Dulu --</option>'; return; }
    authorSel.innerHTML = '<option value="">Memuat...</option>';
    try {
      const resp = await fetch(`actions/site_action.php?action=get_authors&site_id=${siteId}`);
      const data = await resp.json();
      if (data.success && data.authors.length) {
        authorSel.innerHTML = '<option value="">-- Pilih Author --</option>' +
          data.authors.map(a => `<option value="${a.id}" data-name="${a.name}">${a.name} (ID: ${a.id})</option>`).join('');
        authorSel.addEventListener('change', () => {
          const opt = authorSel.selectedOptions[0];
          if (opt && opt.value) {
            if (authorId) authorId.value = opt.value;
            if (authorName) authorName.value = opt.getAttribute('data-name') || '';
          }
        }, { once: true });
      } else {
        authorSel.innerHTML = '<option value="">Tidak ada author ditemukan</option>';
      }
    } catch(e) {
      authorSel.innerHTML = '<option value="">Gagal memuat author</option>';
    }
  }

  siteSelect.addEventListener('change', () => loadAuthors(siteSelect.value));
  // Auto-load if site already selected
  if (siteSelect.value) loadAuthors(siteSelect.value);
})();
</script>
<?php endif; ?>

<?php
});
