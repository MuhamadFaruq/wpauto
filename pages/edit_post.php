<?php
// ================================================
// pages/edit_post.php — Edit existing post
// ================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin();

$user  = currentUser();
$isAdm = isAdmin();
$db    = db();

// ---- Load post ----
$postId = (int)($_GET['id'] ?? 0);
if (!$postId) {
    setFlash('error', 'ID artikel tidak valid.');
    redirect(APP_URL . '/index.php?page=posts');
}

$stmt = $db->prepare(
    "SELECT p.*, s.name AS site_name
     FROM posts p
     LEFT JOIN wp_sites s ON p.site_id = s.id
     WHERE p.id = ?"
);
$stmt->execute([$postId]);
$post = $stmt->fetch();

if (!$post) {
    setFlash('error', 'Artikel tidak ditemukan.');
    redirect(APP_URL . '/index.php?page=posts');
}

// ---- Authorization: only author or admin ----
if (!$isAdm && (int)$post['author_id'] !== (int)$user['id']) {
    setFlash('error', 'Anda tidak memiliki izin untuk mengedit artikel ini.');
    redirect(APP_URL . '/index.php?page=posts');
}

// ---- Load schedule if exists ----
$schedStmt = $db->prepare("SELECT * FROM post_schedules WHERE post_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1");
$schedStmt->execute([$postId]);
$schedule = $schedStmt->fetch() ?: null;

// ---- Fetch active sites ----
$sites = $db->query("SELECT id, name FROM wp_sites WHERE status='active' ORDER BY name")->fetchAll();

// ---- Decode tags ----
$tagsJson = $post['tags'] ?? '[]';
$tagsArr  = json_decode($tagsJson, true) ?: [];
$tagsJson = json_encode($tagsArr);

// ---- Determine schedule_type for form ----
$currentSchedType = 'draft';
if ($post['status'] === 'published' || $post['status'] === 'pending_publish') {
    $currentSchedType = 'now';
} elseif ($schedule) {
    $currentSchedType = $schedule['schedule_type'];
}

// ---- Category (pre-select if stored) ----
$categories    = $post['categories'] ?? null;
$catIds        = json_decode($categories ?? '[]', true) ?: [];
$selectedCatId = $catIds[0] ?? '';

pageWrap('posts', 'Edit Artikel', 'Perbarui artikel yang sudah ada', function()
    use ($post, $schedule, $sites, $tagsJson, $tagsArr, $currentSchedType, $selectedCatId, $isAdm) {
?>

<?php if ($post['status'] === 'published' && $post['wp_post_url']): ?>
<div class="alert alert-success" style="margin-bottom:1rem">
  <i class="fas fa-circle-check"></i>
  Artikel ini sudah terbit di WordPress.
  <a href="<?= sanitize($post['wp_post_url']) ?>" target="_blank" style="font-weight:700;margin-left:.5rem">
    Lihat Artikel <i class="fas fa-arrow-up-right-from-square"></i>
  </a>
</div>
<?php endif; ?>

<?php if ($post['status'] === 'failed' && $post['error_message']): ?>
<div class="alert alert-danger" style="margin-bottom:1rem">
  <i class="fas fa-circle-xmark"></i>
  <strong>Gagal terbit:</strong> <?= sanitize($post['error_message']) ?>
</div>
<?php endif; ?>

<form method="POST" action="<?= APP_URL ?>/actions/post_action.php"
      id="post-form" enctype="multipart/form-data" data-loading>
  <input type="hidden" name="action"     value="update">
  <input type="hidden" name="post_id"    value="<?= $post['id'] ?>">
  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

  <div class="editor-layout">

    <!-- ============================================================
         LEFT: Main Editor
    ============================================================ -->
    <div class="editor-main">

      <!-- Title -->
      <div class="card" style="padding:1.25rem 1.5rem;margin-bottom:1rem">
        <input type="text"
               id="post-title"
               name="title"
               class="editor-title"
               placeholder="Judul artikel..."
               required
               autocomplete="off"
               value="<?= sanitize($post['title']) ?>">
        <div style="display:flex;align-items:center;gap:.5rem;margin-top:.6rem">
          <span style="font-size:.8rem;color:var(--text-muted)">Slug:</span>
          <input type="text"
                 id="post-slug"
                 name="slug"
                 class="form-control"
                 style="font-size:.8rem;padding:.25rem .5rem;flex:1"
                 placeholder="auto-generate dari judul"
                 value="<?= sanitize($post['slug'] ?? '') ?>"
                 autocomplete="off">
        </div>
      </div>

      <!-- Quill Editor -->
      <div class="card" style="padding:0;overflow:hidden;margin-bottom:1rem">
        <div id="quill-editor" style="min-height:420px;font-size:1rem"></div>
        <textarea name="content" id="post-content" style="display:none"><?= htmlspecialchars($post['content'], ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>

    </div><!-- /editor-main -->

    <!-- ============================================================
         RIGHT: Sidebar
    ============================================================ -->
    <div class="editor-sidebar">

      <!-- 1. Terbitkan Card -->
      <div class="card" style="margin-bottom:1rem">
        <div class="card-header" style="padding:.85rem 1rem;justify-content:space-between">
          <h4 style="margin:0;font-size:.95rem"><i class="fas fa-rocket" style="color:var(--orange);margin-right:.4rem"></i> Terbitkan</h4>
          <!-- Current status badge -->
          <?php
          $badgeMap = [
              'published'       => ['badge-published', 'Terbit'],
              'draft'           => ['badge-draft', 'Draft'],
              'scheduled'       => ['badge-scheduled', 'Terjadwal'],
              'failed'          => ['badge-failed', 'Gagal'],
              'pending_publish' => ['badge-pending', 'Pending'],
          ];
          [$cls, $lbl] = $badgeMap[$post['status']] ?? ['badge-draft', $post['status']];
          ?>
          <span class="badge <?= $cls ?>" style="font-size:.72rem"><?= $lbl ?></span>
        </div>
        <div style="padding:1rem">
          <div class="form-group" style="margin-bottom:.75rem">
            <label class="form-label">Jenis Penerbitan</label>
            <select name="schedule_type" id="schedule_type" class="form-control">
              <option value="now"     <?= $currentSchedType === 'now'     ? 'selected' : '' ?>>Terbitkan Sekarang</option>
              <option value="draft"   <?= $currentSchedType === 'draft'   ? 'selected' : '' ?>>Simpan sebagai Draft</option>
              <option value="once"    <?= $currentSchedType === 'once'    ? 'selected' : '' ?>>Jadwalkan Sekali</option>
            </select>
          </div>

          <!-- Schedule options wrapper -->
          <div id="schedule-options">

            <div id="scheduled-fields" class="hidden">
              <div class="form-group" style="margin-bottom:.75rem">
                <label class="form-label">Waktu Jadwal</label>
                <input type="datetime-local" name="scheduled_at" id="scheduled_at" class="form-control"
                       value="<?= $schedule ? date('Y-m-d\TH:i', strtotime($schedule['scheduled_at'])) : '' ?>">
              </div>
            </div>

            <div id="recur-fields" class="hidden">
              <div id="field-interval" style="display:none" class="form-group" style="margin-bottom:.75rem">
                <label class="form-label">Interval (jam)</label>
                <input type="number" name="interval_hours" id="interval_hours" class="form-control" min="1"
                       placeholder="mis. 24" value="<?= $schedule['interval_hours'] ?? '' ?>">
              </div>
              <div id="field-dow" style="display:none" class="form-group" style="margin-bottom:.75rem">
                <label class="form-label">Hari dalam Minggu</label>
                <select name="day_of_week" id="day_of_week" class="form-control">
                  <?php
                  $days = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
                  foreach ($days as $di => $dn):
                  ?>
                  <option value="<?= $di ?>" <?= isset($schedule['day_of_week']) && $schedule['day_of_week'] == $di ? 'selected' : '' ?>><?= $dn ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div id="field-dom" style="display:none" class="form-group" style="margin-bottom:.75rem">
                <label class="form-label">Tanggal dalam Bulan</label>
                <input type="number" name="day_of_month" id="day_of_month" class="form-control" min="1" max="31"
                       placeholder="1–31" value="<?= $schedule['day_of_month'] ?? '' ?>">
              </div>
              <div class="form-group" style="margin-bottom:.75rem">
                <label class="form-label">Ulangi Sampai (opsional)</label>
                <input type="date" name="recur_until" id="recur_until" class="form-control"
                       value="<?= $schedule && $schedule['recur_until'] ? date('Y-m-d', strtotime($schedule['recur_until'])) : '' ?>">
              </div>
              <div class="form-group" style="margin-bottom:.75rem">
                <label class="form-label">Maks. Pengulangan (opsional)</label>
                <input type="number" name="recur_count" id="recur_count" class="form-control" min="1"
                       placeholder="kosong = tak terbatas" value="<?= $schedule['recur_count'] ?? '' ?>">
              </div>
            </div>
          </div>

          <?php if ($post['wp_post_id']): ?>
          <div style="margin-bottom:.75rem">
            <label style="display:flex;align-items:center;gap:.5rem;font-size:.85rem;cursor:pointer">
              <input type="checkbox" name="update_in_wp" value="1" checked>
              <span>Update di WordPress juga</span>
            </label>
          </div>
          <?php endif; ?>

          <button type="submit" class="btn btn-orange" style="width:100%">
            <i class="fas fa-save"></i> <span id="submit-label">Simpan Perubahan</span>
          </button>
          <a href="<?= APP_URL ?>/index.php?page=posts" class="btn btn-outline" style="width:100%;margin-top:.4rem;text-align:center">
            Batal
          </a>
        </div>
      </div>

      <!-- 2. Situs WordPress Card -->
      <div class="card" style="margin-bottom:1rem">
        <div class="card-header" style="padding:.85rem 1rem">
          <h4 style="margin:0;font-size:.95rem"><i class="fas fa-globe" style="color:var(--navy);margin-right:.4rem"></i> Situs WordPress</h4>
        </div>
        <div style="padding:1rem">
          <select name="site_id" id="site-select" class="form-control">
            <option value="">-- Pilih Situs --</option>
            <?php foreach ($sites as $site): ?>
              <option value="<?= $site['id'] ?>" <?= $post['site_id'] == $site['id'] ? 'selected' : '' ?>>
                <?= sanitize($site['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- 3. Kategori Card -->
      <div class="card" style="margin-bottom:1rem">
        <div class="card-header" style="padding:.85rem 1rem">
          <h4 style="margin:0;font-size:.95rem"><i class="fas fa-folder-open" style="color:var(--navy);margin-right:.4rem"></i> Kategori</h4>
        </div>
        <div style="padding:1rem">
          <select name="category_id" id="category-select" class="form-control">
            <?php if ($selectedCatId): ?>
              <option value="<?= (int)$selectedCatId ?>" selected>Kategori #<?= (int)$selectedCatId ?> (memuat...)</option>
            <?php else: ?>
              <option value="">-- Pilih situs dulu --</option>
            <?php endif; ?>
          </select>
          <div id="cat-loading" style="display:none;font-size:.8rem;color:var(--text-muted);margin-top:.5rem">
            <i class="fas fa-spinner fa-spin"></i> Memuat kategori...
          </div>
        </div>
      </div>

      <!-- 4. Tags Card -->
      <div class="card" style="margin-bottom:1rem">
        <div class="card-header" style="padding:.85rem 1rem">
          <h4 style="margin:0;font-size:.95rem"><i class="fas fa-tags" style="color:var(--navy);margin-right:.4rem"></i> Tags</h4>
        </div>
        <div style="padding:1rem">
          <div class="tag-container" id="tag-container">
            <input type="text" id="tag-input" placeholder="Ketik tag, tekan Enter atau koma...">
          </div>
          <input type="hidden" name="tags" id="tags-hidden" value='<?= htmlspecialchars($tagsJson, ENT_QUOTES, 'UTF-8') ?>'>
          <p style="font-size:.75rem;color:var(--text-muted);margin-top:.4rem;margin-bottom:0">
            Tekan Enter atau , untuk menambah tag
          </p>
        </div>
      </div>

      <!-- 5. Gambar Unggulan Card -->
      <div class="card" style="margin-bottom:1rem">
        <div class="card-header" style="padding:.85rem 1rem">
          <h4 style="margin:0;font-size:.95rem"><i class="fas fa-image" style="color:var(--navy);margin-right:.4rem"></i> Gambar Unggulan</h4>
        </div>
        <div style="padding:1rem">
          <!-- Tabs -->
          <div style="display:flex;gap:.25rem;margin-bottom:.75rem">
            <button type="button" class="btn btn-sm img-tab-btn active-tab" data-tab="upload"
                    style="flex:1;font-size:.78rem">
              <i class="fas fa-upload"></i> Upload File
            </button>
            <button type="button" class="btn btn-outline btn-sm img-tab-btn" data-tab="url"
                    style="flex:1;font-size:.78rem">
              <i class="fas fa-link"></i> URL
            </button>
          </div>

          <!-- Upload tab -->
          <div id="tab-upload">
            <div id="upload-area" class="image-upload-area" <?= $post['featured_image_url'] ? 'style="display:none"' : '' ?>>
              <i class="fas fa-cloud-upload-alt" style="font-size:2rem;color:var(--text-muted);margin-bottom:.5rem"></i>
              <p style="margin:0;font-size:.8rem;color:var(--text-muted)">Klik atau seret gambar ke sini</p>
              <p style="margin:.25rem 0 0;font-size:.72rem;color:var(--text-muted)">JPG, PNG, GIF, WebP • Maks 5MB</p>
            </div>
            <input type="file" id="featured_image_file" name="featured_image_file"
                   accept="image/*" style="display:none">
          </div>

          <!-- URL tab -->
          <div id="tab-url" style="display:none">
            <input type="url" id="featured_image_url" name="featured_image_url"
                   class="form-control" placeholder="https://example.com/image.jpg"
                   value="<?= sanitize($post['featured_image_url'] ?? '') ?>">
          </div>

          <!-- Preview (pre-populate if image exists) -->
          <div id="image-preview" style="<?= $post['featured_image_url'] ? 'display:block' : 'display:none' ?>;margin-top:.75rem;position:relative">
            <?php if ($post['featured_image_url']): ?>
            <img src="<?= sanitize($post['featured_image_url']) ?>" alt="Preview" style="width:100%;border-radius:var(--radius)">
            <div class="remove-img" id="remove-image"><i class="fas fa-times"></i></div>
            <?php endif; ?>
          </div>
          <!-- Keep track of cleared image -->
          <input type="hidden" name="clear_image" id="clear-image" value="0">
        </div>
      </div>

      <!-- 6. Excerpt Card -->
      <div class="card" style="margin-bottom:1rem">
        <div class="card-header" style="padding:.85rem 1rem">
          <h4 style="margin:0;font-size:.95rem"><i class="fas fa-align-left" style="color:var(--navy);margin-right:.4rem"></i> Ringkasan (Excerpt)</h4>
        </div>
        <div style="padding:1rem">
          <textarea name="excerpt" id="post-excerpt" class="form-control"
                    rows="3" placeholder="Ringkasan singkat artikel (opsional)..."><?= sanitize($post['excerpt'] ?? '') ?></textarea>
        </div>
      </div>

      <!-- Info Card -->
      <div class="card" style="margin-bottom:1rem;padding:1rem;font-size:.8rem;color:var(--text-muted)">
        <div style="display:flex;flex-direction:column;gap:.35rem">
          <div><i class="fas fa-calendar-plus" style="width:14px"></i> Dibuat: <?= formatDate($post['created_at']) ?></div>
          <div><i class="fas fa-pencil" style="width:14px"></i> Diperbarui: <?= formatDate($post['updated_at']) ?></div>
          <?php if ($post['published_at']): ?>
          <div><i class="fas fa-rocket" style="width:14px"></i> Terbit: <?= formatDate($post['published_at']) ?></div>
          <?php endif; ?>
          <?php if ($post['wp_post_id']): ?>
          <div><i class="fab fa-wordpress" style="width:14px"></i> WP Post ID: #<?= $post['wp_post_id'] ?></div>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /editor-sidebar -->
  </div><!-- /editor-layout -->
</form>

<style>
.editor-layout { display:grid; grid-template-columns:1fr 320px; gap:1.25rem; align-items:start; }
@media(max-width:900px){ .editor-layout { grid-template-columns:1fr; } }
.editor-title {
  width:100%; border:none; outline:none; font-size:1.75rem; font-weight:700;
  color:var(--text); background:transparent; line-height:1.3;
  padding:0; resize:none;
}
.editor-title::placeholder { color:var(--text-muted); }
.image-upload-area {
  border:2px dashed var(--border); border-radius:var(--radius); padding:1.5rem;
  text-align:center; cursor:pointer; transition:border-color .2s, background .2s;
}
.image-upload-area:hover, .image-upload-area.dragover { border-color:var(--navy); background:var(--navy-xlight); }
#image-preview img { width:100%;border-radius:var(--radius);display:block; }
.remove-img {
  position:absolute;top:.4rem;right:.4rem;background:#000000aa;color:#fff;
  border-radius:50%;width:24px;height:24px;display:flex;align-items:center;
  justify-content:center;cursor:pointer;font-size:.7rem;
}
.img-tab-btn { background:var(--border); color:var(--text-muted); }
.img-tab-btn.active-tab { background:var(--navy); color:#fff; }
.hidden { display:none !important; }
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {

  // ---- Quill Editor ----
  const quill = new Quill('#quill-editor', {
    theme: 'snow',
    placeholder: 'Tulis konten artikel di sini...',
    modules: {
      toolbar: [
        [{ header: [1, 2, 3, false] }],
        ['bold', 'italic', 'underline', 'strike'],
        ['blockquote', 'code-block'],
        [{ list: 'ordered' }, { list: 'bullet' }],
        [{ indent: '-1' }, { indent: '+1' }],
        ['link', 'image'],
        ['clean']
      ]
    }
  });

  // Pre-populate Quill with existing content
  const existingContent = document.getElementById('post-content').value;
  if (existingContent) {
    quill.root.innerHTML = existingContent;
  }

  // Copy Quill HTML to hidden textarea on submit
  const postForm = document.getElementById('post-form');
  if (postForm) {
    postForm.addEventListener('submit', () => {
      const contentArea = document.getElementById('post-content');
      if (contentArea) contentArea.value = quill.root.innerHTML;
    });
  }

  // ---- Schedule type UI ----
  const scheduleType    = document.getElementById('schedule_type');
  const scheduledFields = document.getElementById('scheduled-fields');
  const recurFields     = document.getElementById('recur-fields');
  const submitLabel     = document.getElementById('submit-label');
  const fieldInterval   = document.getElementById('field-interval');
  const fieldDow        = document.getElementById('field-dow');
  const fieldDom        = document.getElementById('field-dom');

  const labelMap = {
    'now':     'Terbitkan Sekarang',
    'draft':   'Simpan Draft',
    'once':    'Simpan Jadwal',
    'daily':   'Simpan Jadwal Harian',
    'weekly':  'Simpan Jadwal Mingguan',
    'monthly': 'Simpan Jadwal Bulanan',
    'custom':  'Simpan Jadwal Kustom',
  };

  function updateScheduleUI() {
    const val = scheduleType.value;
    const isScheduled = !['now','draft'].includes(val);
    const isRecurring = !['now','draft','once'].includes(val);

    scheduledFields.classList.toggle('hidden', !isScheduled);
    recurFields.classList.toggle('hidden', !isRecurring);

    if (fieldInterval) fieldInterval.style.display = val === 'custom'  ? 'block' : 'none';
    if (fieldDow)      fieldDow.style.display      = val === 'weekly'  ? 'block' : 'none';
    if (fieldDom)      fieldDom.style.display      = val === 'monthly' ? 'block' : 'none';

    if (submitLabel) submitLabel.textContent = labelMap[val] || 'Simpan Perubahan';
  }

  if (scheduleType) {
    scheduleType.addEventListener('change', updateScheduleUI);
    updateScheduleUI();
  }

  // ---- Image Tab Toggle ----
  document.querySelectorAll('.img-tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.img-tab-btn').forEach(b => b.classList.remove('active-tab'));
      btn.classList.add('active-tab');
      const tab = btn.getAttribute('data-tab');
      document.getElementById('tab-upload').style.display = tab === 'upload' ? 'block' : 'none';
      document.getElementById('tab-url').style.display    = tab === 'url'    ? 'block' : 'none';
    });
  });

  // ---- Remove image ----
  function attachRemoveBtn() {
    const rmBtn = document.getElementById('remove-image');
    if (rmBtn) {
      rmBtn.addEventListener('click', () => {
        const preview  = document.getElementById('image-preview');
        const uploadAr = document.getElementById('upload-area');
        const imgFile  = document.getElementById('featured_image_file');
        const imgUrl   = document.getElementById('featured_image_url');
        const clearImg = document.getElementById('clear-image');
        if (preview)  { preview.innerHTML = ''; preview.style.display = 'none'; }
        if (uploadAr) uploadAr.style.display = 'block';
        if (imgFile)  imgFile.value = '';
        if (imgUrl)   imgUrl.value  = '';
        if (clearImg) clearImg.value = '1';
      });
    }
  }
  attachRemoveBtn();

  // ---- Load categories when site changes ----
  const siteSelect = document.getElementById('site-select');
  const catSelect  = document.getElementById('category-select');
  const catLoading = document.getElementById('cat-loading');
  const preSelectedCatId = '<?= (int)$selectedCatId ?>';

  async function loadCategories(siteId, preselectId) {
    if (!siteId) {
      catSelect.innerHTML = '<option value="">-- Pilih situs dulu --</option>';
      return;
    }
    catSelect.style.display = 'none';
    if (catLoading) catLoading.style.display = 'block';
    try {
      const resp = await fetch(`<?= APP_URL ?>/actions/post_action.php?action=get_categories&site_id=${siteId}`);
      const data = await resp.json();
      if (data.success && data.categories.length) {
        catSelect.innerHTML = '<option value="">-- Pilih Kategori --</option>' +
          data.categories.map(c => {
            const sel = preselectId && String(c.id) === String(preselectId) ? ' selected' : '';
            return `<option value="${c.id}"${sel}>${c.name}</option>`;
          }).join('');
      } else {
        catSelect.innerHTML = '<option value="">Tidak ada kategori ditemukan</option>';
      }
    } catch(e) {
      catSelect.innerHTML = '<option value="">Gagal memuat kategori</option>';
    }
    catSelect.style.display = '';
    if (catLoading) catLoading.style.display = 'none';
  }

  if (siteSelect && catSelect) {
    // Auto-load on page load if site already selected
    const currentSiteId = siteSelect.value;
    if (currentSiteId) loadCategories(currentSiteId, preSelectedCatId);

    siteSelect.addEventListener('change', () => loadCategories(siteSelect.value, ''));
  }

});
</script>

<?php
});
