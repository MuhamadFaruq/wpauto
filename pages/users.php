<?php
// ================================================
// pages/users.php — Manage Users (Admin)
// ================================================
require_once __DIR__ . '/../includes/layout.php';
requireAdmin();

$db = db();

$users = $db->query(
    "SELECT * FROM users ORDER BY created_at DESC"
)->fetchAll();

// Load single user for editing
$editUser = null;
if (!empty($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $editUser = $stmt->fetch() ?: null;
}

$roleBadge = fn($role) => $role === 'admin'
    ? "<span class='badge badge-published'><i class='fas fa-shield-halved'></i> Admin</span>"
    : "<span class='badge badge-scheduled'><i class='fas fa-pen'></i> Penulis</span>";

$statusBadge = fn($active) => $active
    ? "<span class='badge badge-published'>Aktif</span>"
    : "<span class='badge badge-draft'>Nonaktif</span>";

pageWrap('users', 'Kelola User', 'Manajemen akun pengguna dashboard', function() use ($users, $editUser, $roleBadge, $statusBadge) {
?>

<!-- Header -->
<div style="display:flex;justify-content:flex-end;margin-bottom:1.25rem">
  <button class="btn btn-orange" data-modal-open="modal-add-user">
    <i class="fas fa-user-plus"></i> Tambah User
  </button>
</div>

<!-- Users Table -->
<div class="card">
  <div class="card-header">
    <h3><i class="fas fa-users" style="color:var(--navy);margin-right:.4rem"></i> Daftar Pengguna</h3>
    <span class="badge badge-draft" style="font-size:.8rem"><?= count($users) ?> user</span>
  </div>
  <div class="table-responsive">
    <?php if ($users): ?>
    <table class="table">
      <thead>
        <tr>
          <th style="width:48px">Avatar</th>
          <th>Nama</th>
          <th>Username</th>
          <th>Email</th>
          <th>Role</th>
          <th>Status</th>
          <th>Tanggal Daftar</th>
          <th style="width:160px">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u):
          $initial = strtoupper(substr($u['full_name'], 0, 1));
          $isSelf  = ($u['id'] === (int)($_SESSION['user_id'] ?? 0));
        ?>
        <tr>
          <td>
            <?php if ($u['avatar_url']): ?>
              <img src="<?= sanitize($u['avatar_url']) ?>" alt="avatar"
                   style="width:38px;height:38px;border-radius:50%;object-fit:cover">
            <?php else: ?>
              <div style="width:38px;height:38px;border-radius:50%;background:var(--navy);
                          color:#fff;display:flex;align-items:center;justify-content:center;
                          font-weight:700;font-size:.9rem"><?= $initial ?></div>
            <?php endif; ?>
          </td>
          <td>
            <div style="font-weight:600"><?= sanitize($u['full_name']) ?></div>
            <?php if ($isSelf): ?>
              <small style="color:var(--orange)"><i class="fas fa-circle-dot"></i> Anda</small>
            <?php endif; ?>
          </td>
          <td><code style="font-size:.85rem"><?= sanitize($u['username']) ?></code></td>
          <td style="font-size:.875rem"><?= sanitize($u['email']) ?></td>
          <td><?= $roleBadge($u['role']) ?></td>
          <td><?= $statusBadge($u['is_active']) ?></td>
          <td><span class="text-small text-muted"><?= formatDate($u['created_at'], 'd M Y') ?></span></td>
          <td>
            <div style="display:flex;gap:.35rem;flex-wrap:wrap">
              <a href="index.php?page=users&edit=<?= $u['id'] ?>"
                 class="btn btn-outline btn-sm" title="Edit">
                <i class="fas fa-pen"></i>
              </a>
              <?php if (!$isSelf): ?>
              <a href="actions/user_action.php?action=toggle_active&id=<?= $u['id'] ?>"
                 class="btn btn-outline btn-sm" title="<?= $u['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>"
                 data-confirm="<?= $u['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?> user ini?">
                <i class="fas fa-<?= $u['is_active'] ? 'toggle-off' : 'toggle-on' ?>"></i>
              </a>
              <a href="actions/user_action.php?action=delete&id=<?= $u['id'] ?>"
                 class="btn btn-danger btn-sm" title="Hapus"
                 data-confirm="Hapus user '<?= sanitize($u['full_name']) ?>'? Tindakan ini tidak bisa dibatalkan.">
                <i class="fas fa-trash"></i>
              </a>
              <?php else: ?>
              <span class="text-small text-muted" style="padding:.3rem .5rem">—</span>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="empty-state">
      <i class="fas fa-users"></i>
      <h4>Belum ada user</h4>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ============================================================ -->
<!-- ADD USER MODAL                                                -->
<!-- ============================================================ -->
<div class="modal-overlay" id="modal-add-user">
  <div class="modal" style="max-width:520px">
    <div class="modal-header">
      <h3><i class="fas fa-user-plus" style="color:var(--orange)"></i> Tambah User</h3>
      <button class="modal-close"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="actions/user_action.php" data-loading>
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="add">

        <div class="form-group">
          <label class="form-label">Nama Lengkap <span style="color:var(--danger)">*</span></label>
          <input type="text" name="full_name" class="form-control"
                 placeholder="Nama lengkap pengguna" required>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
          <div class="form-group">
            <label class="form-label">Username <span style="color:var(--danger)">*</span></label>
            <input type="text" name="username" class="form-control"
                   placeholder="username" required autocomplete="off">
          </div>
          <div class="form-group">
            <label class="form-label">Email <span style="color:var(--danger)">*</span></label>
            <input type="email" name="email" class="form-control"
                   placeholder="email@domain.com" required>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
          <div class="form-group">
            <label class="form-label">Password <span style="color:var(--danger)">*</span></label>
            <div style="position:relative">
              <input type="password" name="password" id="add-password" class="form-control"
                     placeholder="Min. 8 karakter" required minlength="8" autocomplete="new-password"
                     style="padding-right:2.5rem">
              <button type="button" onclick="togglePwd('add-password', this)"
                      style="position:absolute;right:.65rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted)">
                <i class="fas fa-eye"></i>
              </button>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Konfirmasi Password <span style="color:var(--danger)">*</span></label>
            <div style="position:relative">
              <input type="password" name="confirm_password" id="add-confirm-password" class="form-control"
                     placeholder="Ulangi password" required autocomplete="new-password"
                     style="padding-right:2.5rem">
              <button type="button" onclick="togglePwd('add-confirm-password', this)"
                      style="position:absolute;right:.65rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted)">
                <i class="fas fa-eye"></i>
              </button>
            </div>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
          <div class="form-group">
            <label class="form-label">Role <span style="color:var(--danger)">*</span></label>
            <select name="role" class="form-control form-select" required>
              <option value="writer">Penulis</option>
              <option value="admin">Admin</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Status</label>
            <select name="is_active" class="form-control form-select">
              <option value="1">Aktif</option>
              <option value="0">Nonaktif</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Avatar URL <span style="color:var(--text-muted);font-weight:400">(opsional)</span></label>
          <input type="url" name="avatar_url" class="form-control"
                 placeholder="https://...url-gambar.jpg">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" data-modal-close>Batal</button>
        <button type="submit" class="btn btn-orange">
          <i class="fas fa-save"></i> Simpan User
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================ -->
<!-- EDIT USER MODAL (auto-open when ?edit=ID)                    -->
<!-- ============================================================ -->
<?php if ($editUser): ?>
<div class="modal-overlay show" id="modal-edit-user">
  <div class="modal" style="max-width:520px">
    <div class="modal-header">
      <h3><i class="fas fa-user-pen" style="color:var(--navy)"></i> Edit User</h3>
      <a href="index.php?page=users" class="modal-close"><i class="fas fa-times"></i></a>
    </div>
    <form method="POST" action="actions/user_action.php" data-loading>
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" value="<?= (int)$editUser['id'] ?>">

        <div class="form-group">
          <label class="form-label">Nama Lengkap <span style="color:var(--danger)">*</span></label>
          <input type="text" name="full_name" class="form-control"
                 value="<?= sanitize($editUser['full_name']) ?>" required>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
          <div class="form-group">
            <label class="form-label">Username <span style="color:var(--danger)">*</span></label>
            <input type="text" name="username" class="form-control"
                   value="<?= sanitize($editUser['username']) ?>" required autocomplete="off">
          </div>
          <div class="form-group">
            <label class="form-label">Email <span style="color:var(--danger)">*</span></label>
            <input type="email" name="email" class="form-control"
                   value="<?= sanitize($editUser['email']) ?>" required>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
          <div class="form-group">
            <label class="form-label">Password Baru <span style="color:var(--text-muted);font-weight:400">(opsional)</span></label>
            <div style="position:relative">
              <input type="password" name="password" id="edit-password" class="form-control"
                     placeholder="Kosongkan jika tidak diubah" minlength="8" autocomplete="new-password"
                     style="padding-right:2.5rem">
              <button type="button" onclick="togglePwd('edit-password', this)"
                      style="position:absolute;right:.65rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted)">
                <i class="fas fa-eye"></i>
              </button>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Konfirmasi Password</label>
            <div style="position:relative">
              <input type="password" name="confirm_password" id="edit-confirm-password" class="form-control"
                     placeholder="Ulangi password baru" autocomplete="new-password"
                     style="padding-right:2.5rem">
              <button type="button" onclick="togglePwd('edit-confirm-password', this)"
                      style="position:absolute;right:.65rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted)">
                <i class="fas fa-eye"></i>
              </button>
            </div>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
          <div class="form-group">
            <label class="form-label">Role</label>
            <select name="role" class="form-control form-select">
              <option value="writer" <?= $editUser['role'] === 'writer' ? 'selected' : '' ?>>Penulis</option>
              <option value="admin"  <?= $editUser['role'] === 'admin'  ? 'selected' : '' ?>>Admin</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Status</label>
            <select name="is_active" class="form-control form-select">
              <option value="1" <?= $editUser['is_active'] ? 'selected' : '' ?>>Aktif</option>
              <option value="0" <?= !$editUser['is_active'] ? 'selected' : '' ?>>Nonaktif</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Avatar URL <span style="color:var(--text-muted);font-weight:400">(opsional)</span></label>
          <input type="url" name="avatar_url" class="form-control"
                 value="<?= sanitize($editUser['avatar_url'] ?? '') ?>"
                 placeholder="https://...url-gambar.jpg">
        </div>
      </div>
      <div class="modal-footer">
        <a href="index.php?page=users" class="btn btn-outline">Batal</a>
        <button type="submit" class="btn btn-orange">
          <i class="fas fa-save"></i> Simpan Perubahan
        </button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
function togglePwd(inputId, btn) {
  const input = document.getElementById(inputId);
  if (!input) return;
  const isPass = input.type === 'password';
  input.type = isPass ? 'text' : 'password';
  btn.querySelector('i').className = isPass ? 'fas fa-eye-slash' : 'fas fa-eye';
}
</script>

<?php
});
