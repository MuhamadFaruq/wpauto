<?php
// ================================================
// pages/profile.php — User Profile (All Users)
// ================================================
require_once __DIR__ . '/../includes/layout.php';
requireLogin();

$user = currentUser();

pageWrap('profile', 'Profil Saya', 'Kelola informasi akun Anda', function() use ($user) {
    $roleLabel  = $user['role'] === 'admin' ? 'Administrator' : 'Penulis';
    $roleBadge  = $user['role'] === 'admin'
        ? "<span class='badge badge-published'><i class='fas fa-shield-halved'></i> Administrator</span>"
        : "<span class='badge badge-scheduled'><i class='fas fa-pen'></i> Penulis</span>";
    $initial    = strtoupper(substr($user['full_name'], 0, 1));
?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem">

  <!-- ============================================================ -->
  <!-- CARD 1: Edit Profile                                          -->
  <!-- ============================================================ -->
  <div class="card">
    <div class="card-header">
      <h3><i class="fas fa-circle-user" style="color:var(--navy);margin-right:.4rem"></i> Edit Profil</h3>
    </div>
    <div class="card-body">

      <!-- Avatar preview -->
      <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;padding-bottom:1.25rem;border-bottom:1px solid var(--border)">
        <div id="avatar-preview-wrap">
          <?php if ($user['avatar_url']): ?>
            <img id="avatar-preview-img" src="<?= sanitize($user['avatar_url']) ?>" alt="avatar"
                 style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:3px solid var(--border)">
          <?php else: ?>
            <div id="avatar-preview-initials"
                 style="width:72px;height:72px;border-radius:50%;background:var(--navy);color:#fff;
                        display:flex;align-items:center;justify-content:center;font-size:1.75rem;font-weight:700">
              <?= $initial ?>
            </div>
          <?php endif; ?>
        </div>
        <div>
          <div style="font-weight:700;font-size:1.05rem"><?= sanitize($user['full_name']) ?></div>
          <div style="font-size:.85rem;color:var(--text-muted);margin:.2rem 0">@<?= sanitize($user['username']) ?></div>
          <?= $roleBadge ?>
        </div>
      </div>

      <form method="POST" action="actions/user_action.php" data-loading>
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="update_profile">

        <div class="form-group">
          <label class="form-label">Nama Lengkap <span style="color:var(--danger)">*</span></label>
          <input type="text" name="full_name" class="form-control"
                 value="<?= sanitize($user['full_name']) ?>" required>
        </div>

        <div class="form-group">
          <label class="form-label">Email <span style="color:var(--danger)">*</span></label>
          <input type="email" name="email" class="form-control"
                 value="<?= sanitize($user['email']) ?>" required>
        </div>

        <div class="form-group">
          <label class="form-label">URL Avatar <span style="color:var(--text-muted);font-weight:400">(opsional)</span></label>
          <input type="url" name="avatar_url" id="profile-avatar-url" class="form-control"
                 value="<?= sanitize($user['avatar_url'] ?? '') ?>"
                 placeholder="https://...url-foto-profil.jpg">
          <div class="form-hint">URL gambar profil (JPG, PNG, WebP)</div>
        </div>

        <div class="form-group">
          <label class="form-label">Username</label>
          <input type="text" class="form-control" value="<?= sanitize($user['username']) ?>"
                 disabled style="background:var(--surface-alt);cursor:not-allowed">
          <div class="form-hint">Username tidak dapat diubah</div>
        </div>

        <div class="form-group">
          <label class="form-label">Role</label>
          <div class="form-control" style="background:var(--surface-alt);display:flex;align-items:center;gap:.5rem;height:auto;padding:.55rem .85rem">
            <?= $roleBadge ?>
          </div>
          <div class="form-hint">Role ditentukan oleh administrator</div>
        </div>

        <div style="text-align:right">
          <button type="submit" class="btn btn-orange">
            <i class="fas fa-save"></i> Simpan Profil
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ============================================================ -->
  <!-- CARD 2: Change Password                                       -->
  <!-- ============================================================ -->
  <div>
    <div class="card">
      <div class="card-header">
        <h3><i class="fas fa-key" style="color:var(--orange);margin-right:.4rem"></i> Ganti Password</h3>
      </div>
      <div class="card-body">
        <form method="POST" action="actions/user_action.php" data-loading id="form-change-password">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="change_password">

          <div class="form-group">
            <label class="form-label">Password Saat Ini <span style="color:var(--danger)">*</span></label>
            <div style="position:relative">
              <input type="password" name="current_pass" id="current-pass" class="form-control"
                     placeholder="Password lama Anda" required autocomplete="current-password"
                     style="padding-right:2.5rem">
              <button type="button" onclick="togglePwd('current-pass', this)"
                      style="position:absolute;right:.65rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted)">
                <i class="fas fa-eye"></i>
              </button>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Password Baru <span style="color:var(--danger)">*</span></label>
            <div style="position:relative">
              <input type="password" name="new_pass" id="new-pass" class="form-control"
                     placeholder="Min. 8 karakter" required minlength="8" autocomplete="new-password"
                     style="padding-right:2.5rem" oninput="checkPassStrength(this.value)">
              <button type="button" onclick="togglePwd('new-pass', this)"
                      style="position:absolute;right:.65rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted)">
                <i class="fas fa-eye"></i>
              </button>
            </div>
            <!-- Password strength bar -->
            <div id="pass-strength-bar" style="margin-top:.35rem;height:4px;border-radius:2px;background:var(--border);overflow:hidden;display:none">
              <div id="pass-strength-fill" style="height:100%;transition:width .3s,background .3s;width:0"></div>
            </div>
            <div id="pass-strength-label" class="form-hint" style="display:none"></div>
          </div>

          <div class="form-group">
            <label class="form-label">Konfirmasi Password Baru <span style="color:var(--danger)">*</span></label>
            <div style="position:relative">
              <input type="password" name="confirm_pass" id="confirm-pass" class="form-control"
                     placeholder="Ulangi password baru" required autocomplete="new-password"
                     style="padding-right:2.5rem" oninput="checkPassMatch()">
              <button type="button" onclick="togglePwd('confirm-pass', this)"
                      style="position:absolute;right:.65rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted)">
                <i class="fas fa-eye"></i>
              </button>
            </div>
            <div id="pass-match-msg" class="form-hint"></div>
          </div>

          <div style="text-align:right">
            <button type="submit" class="btn btn-orange">
              <i class="fas fa-key"></i> Ganti Password
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Account info card -->
    <div class="card" style="margin-top:1.25rem;padding:1.25rem">
      <div style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:.75rem">
        Informasi Akun
      </div>
      <div style="display:flex;flex-direction:column;gap:.5rem;font-size:.875rem">
        <div style="display:flex;justify-content:space-between;padding:.4rem 0;border-bottom:1px solid var(--border)">
          <span style="color:var(--text-muted)">Bergabung</span>
          <span style="font-weight:600"><?= formatDate($user['created_at'], 'd M Y') ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;padding:.4rem 0;border-bottom:1px solid var(--border)">
          <span style="color:var(--text-muted)">Role</span>
          <span><?= $roleBadge ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;padding:.4rem 0">
          <span style="color:var(--text-muted)">Status</span>
          <span class="badge badge-published"><i class="fas fa-circle-dot"></i> Aktif</span>
        </div>
      </div>
    </div>
  </div>

</div><!-- end grid -->

<script>
function togglePwd(inputId, btn) {
  const input = document.getElementById(inputId);
  if (!input) return;
  const isPass = input.type === 'password';
  input.type = isPass ? 'text' : 'password';
  btn.querySelector('i').className = isPass ? 'fas fa-eye-slash' : 'fas fa-eye';
}

// Live avatar preview
const avatarUrlInput = document.getElementById('profile-avatar-url');
if (avatarUrlInput) {
  let t;
  avatarUrlInput.addEventListener('input', () => {
    clearTimeout(t);
    t = setTimeout(() => {
      const url = avatarUrlInput.value.trim();
      const wrap = document.getElementById('avatar-preview-wrap');
      if (!wrap) return;
      if (url) {
        wrap.innerHTML = `<img id="avatar-preview-img" src="${url}" alt="avatar"
          style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:3px solid var(--border)"
          onerror="this.onerror=null;this.parentElement.innerHTML='<div style=\'width:72px;height:72px;border-radius:50%;background:var(--danger);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.75rem\'>Error</div>'">`;
      }
    }, 600);
  });
}

// Password strength
function checkPassStrength(val) {
  const bar   = document.getElementById('pass-strength-bar');
  const fill  = document.getElementById('pass-strength-fill');
  const label = document.getElementById('pass-strength-label');
  if (!bar || !fill || !label) return;

  if (!val) { bar.style.display = 'none'; label.style.display = 'none'; return; }
  bar.style.display = 'block'; label.style.display = 'block';

  let score = 0;
  if (val.length >= 8)  score++;
  if (val.length >= 12) score++;
  if (/[A-Z]/.test(val)) score++;
  if (/[0-9]/.test(val)) score++;
  if (/[^A-Za-z0-9]/.test(val)) score++;

  const levels = [
    { color: '#ef4444', text: 'Sangat Lemah', pct: 20 },
    { color: '#f97316', text: 'Lemah',         pct: 40 },
    { color: '#eab308', text: 'Cukup',          pct: 60 },
    { color: '#22c55e', text: 'Kuat',            pct: 80 },
    { color: '#16a34a', text: 'Sangat Kuat',    pct: 100 },
  ];
  const lv = levels[Math.min(score, 4)];
  fill.style.width = lv.pct + '%';
  fill.style.background = lv.color;
  label.style.color = lv.color;
  label.textContent = lv.text;
}

// Password match check
function checkPassMatch() {
  const np = document.getElementById('new-pass')?.value;
  const cp = document.getElementById('confirm-pass')?.value;
  const msg = document.getElementById('pass-match-msg');
  if (!msg || !cp) return;
  if (np === cp) {
    msg.style.color = '#22c55e';
    msg.textContent = '✓ Password cocok';
  } else {
    msg.style.color = 'var(--danger)';
    msg.textContent = '✗ Password tidak cocok';
  }
}

// Prevent form submit if passwords don't match
document.getElementById('form-change-password')?.addEventListener('submit', (e) => {
  const np = document.getElementById('new-pass')?.value;
  const cp = document.getElementById('confirm-pass')?.value;
  if (np !== cp) {
    e.preventDefault();
    document.getElementById('pass-match-msg').textContent = '✗ Password tidak cocok, periksa kembali.';
    document.getElementById('pass-match-msg').style.color = 'var(--danger)';
    document.getElementById('confirm-pass').focus();
  }
});
</script>

<?php
});
