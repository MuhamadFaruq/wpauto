<?php
// ================================================
// pages/new_post.php — New post editor
// ================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin();

$user  = currentUser();
$db    = db();

// Fetch active sites
$sites = $db->query("SELECT id, name FROM wp_sites WHERE status='active' ORDER BY name")->fetchAll();

// Handle AI draft prefill
$aiDraft = null;
if (isset($_GET['ai_draft']) && !empty($_SESSION['ai_draft'])) {
    $aiDraft = $_SESSION['ai_draft'];
    unset($_SESSION['ai_draft']);
}

pageWrap('new_post', 'Tulis Artikel', 'Buat artikel baru untuk diterbitkan ke WordPress', function() use ($sites, $user, $aiDraft) {
?>

<form method="POST" action="<?= APP_URL ?>/actions/post_action.php"
      id="post-form" enctype="multipart/form-data" data-loading>
  <input type="hidden" name="action"     value="create">
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
               value="<?= $aiDraft ? sanitize($aiDraft['title']) : '' ?>">
        <div style="display:flex;align-items:center;gap:.5rem;margin-top:.6rem">
          <span style="font-size:.8rem;color:var(--text-muted)">Slug:</span>
          <input type="text"
                 id="post-slug"
                 name="slug"
                 class="form-control"
                 style="font-size:.8rem;padding:.25rem .5rem;flex:1"
                 placeholder="auto-generate dari judul"
                 autocomplete="off">
        </div>
      </div>

      <!-- Quill Editor -->
      <div class="card" style="padding:0;overflow:hidden;margin-bottom:1rem">
        <div id="quill-editor" style="min-height:420px;font-size:1rem"></div>
        <textarea name="content" id="post-content" style="display:none"></textarea>
      </div>

    </div><!-- /editor-main -->

    <!-- ============================================================
         RIGHT: Sidebar
    ============================================================ -->
    <div class="editor-sidebar">

      <!-- 1. Terbitkan Card -->
      <div class="card" style="margin-bottom:1rem">
        <div class="card-header" style="padding:.85rem 1rem">
          <h4 style="margin:0;font-size:.95rem"><i class="fas fa-rocket" style="color:var(--orange);margin-right:.4rem"></i> Terbitkan</h4>
        </div>
        <div style="padding:1rem">
          <div class="form-group" style="margin-bottom:.75rem">
            <label class="form-label">Jenis Penerbitan</label>
            <select name="schedule_type" id="schedule_type" class="form-control">
              <option value="now">Terbitkan Sekarang</option>
              <option value="draft">Simpan sebagai Draft</option>
              <option value="once">Jadwalkan Sekali</option>
            </select>
          </div>

          <!-- Schedule options wrapper -->
          <div id="schedule-options">

            <!-- scheduled_at — shown for all except 'now' / 'draft' -->
            <div id="scheduled-fields" class="hidden">
              <div class="form-group" style="margin-bottom:.75rem">
                <label class="form-label">Waktu Jadwal</label>
                <input type="datetime-local" name="scheduled_at" id="scheduled_at" class="form-control">
              </div>
            </div>

            <!-- Recurring fields — shown for daily/weekly/monthly/custom -->
            <div id="recur-fields" class="hidden">
              <!-- Custom interval -->
              <div id="field-interval" style="display:none" class="form-group" style="margin-bottom:.75rem">
                <label class="form-label">Interval (jam)</label>
                <input type="number" name="interval_hours" id="interval_hours" class="form-control" min="1" placeholder="mis. 24">
              </div>
              <!-- Day of week (weekly) -->
              <div id="field-dow" style="display:none" class="form-group" style="margin-bottom:.75rem">
                <label class="form-label">Hari dalam Minggu</label>
                <select name="day_of_week" id="day_of_week" class="form-control">
                  <option value="0">Minggu</option>
                  <option value="1">Senin</option>
                  <option value="2">Selasa</option>
                  <option value="3">Rabu</option>
                  <option value="4">Kamis</option>
                  <option value="5">Jumat</option>
                  <option value="6">Sabtu</option>
                </select>
              </div>
              <!-- Day of month (monthly) -->
              <div id="field-dom" style="display:none" class="form-group" style="margin-bottom:.75rem">
                <label class="form-label">Tanggal dalam Bulan</label>
                <input type="number" name="day_of_month" id="day_of_month" class="form-control" min="1" max="31" placeholder="1–31">
              </div>
              <!-- Recur until -->
              <div class="form-group" style="margin-bottom:.75rem">
                <label class="form-label">Ulangi Sampai (opsional)</label>
                <input type="date" name="recur_until" id="recur_until" class="form-control">
              </div>
              <!-- Recur count -->
              <div class="form-group" style="margin-bottom:.75rem">
                <label class="form-label">Maks. Pengulangan (opsional)</label>
                <input type="number" name="recur_count" id="recur_count" class="form-control" min="1" placeholder="kosong = tak terbatas">
              </div>
            </div>

          </div><!-- /schedule-options -->

          <button type="submit" class="btn btn-orange" style="width:100%">
            <i class="fas fa-paper-plane"></i> <span id="submit-label">Terbitkan Sekarang</span>
          </button>
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
              <option value="<?= $site['id'] ?>" <?= ($aiDraft && $aiDraft['site_id'] == $site['id']) ? 'selected' : '' ?>><?= sanitize($site['name']) ?></option>
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
            <option value="">-- Pilih situs dulu --</option>
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
          <input type="hidden" name="tags" id="tags-hidden" value="<?= $aiDraft ? sanitize(json_encode($aiDraft['tags'])) : '[]' ?>">
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
            <div id="upload-area" class="image-upload-area">
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
                   class="form-control" placeholder="https://example.com/image.jpg">
          </div>

          <!-- Preview -->
          <div id="image-preview" style="display:none;margin-top:.75rem;position:relative">
            <!-- populated by JS -->
          </div>
        </div>
      </div>

      <!-- 6. Excerpt Card -->
      <div class="card" style="margin-bottom:1rem">
        <div class="card-header" style="padding:.85rem 1rem">
          <h4 style="margin:0;font-size:.95rem"><i class="fas fa-align-left" style="color:var(--navy);margin-right:.4rem"></i> Ringkasan (Excerpt)</h4>
        </div>
        <div style="padding:1rem">
          <textarea name="excerpt" id="post-excerpt" class="form-control"
                    rows="3" placeholder="Ringkasan singkat artikel (opsional)..."></textarea>
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
#schedule-options .show { display:block !important; }
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

  // Load AI Draft content if available
  const aiContent = <?= json_encode($aiDraft ? $aiDraft['content'] : '') ?>;
  if (aiContent) {
    quill.root.innerHTML = aiContent;
  }

  // Copy Quill HTML to hidden textarea on submit
  const postForm = document.getElementById('post-form');
  if (postForm) {
    postForm.addEventListener('submit', (e) => {
      const contentArea = document.getElementById('post-content');
      if (contentArea) contentArea.value = quill.root.innerHTML;
      // Basic validation
      if (!quill.getText().trim() && quill.root.innerHTML === '<p><br></p>') {
        // Allow empty content (draft scenario)
      }
    });
  }

  // ---- Schedule type UI ----
  const scheduleType = document.getElementById('schedule_type');
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

    if (submitLabel) submitLabel.textContent = labelMap[val] || 'Simpan';
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

  // ---- Load categories when site changes ----
  const siteSelect  = document.getElementById('site-select');
  const catSelect   = document.getElementById('category-select');
  const catLoading  = document.getElementById('cat-loading');
  const catSuggest  = <?= json_encode($aiDraft ? $aiDraft['category_suggestion'] : '') ?>;

  if (siteSelect && catSelect) {
    siteSelect.addEventListener('change', async () => {
      const siteId = siteSelect.value;
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
            data.categories.map(c => `<option value="${c.id}">${c.name}</option>`).join('');

          // Auto-match category suggest
          if (catSuggest) {
            const normalizedSuggest = catSuggest.toLowerCase().trim();
            for (let opt of catSelect.options) {
              const optText = opt.text.toLowerCase().trim();
              if (optText && (optText.includes(normalizedSuggest) || normalizedSuggest.includes(optText))) {
                opt.selected = true;
                break;
              }
            }
          }
        } else {
          catSelect.innerHTML = '<option value="">Tidak ada kategori ditemukan</option>';
        }
      } catch(e) {
        catSelect.innerHTML = '<option value="">Gagal memuat kategori</option>';
      }
      catSelect.style.display = '';
      if (catLoading) catLoading.style.display = 'none';
    });

    // Trigger categories load if site pre-selected
    if (siteSelect.value) {
      siteSelect.dispatchEvent(new Event('change'));
    }
  }

});
</script>

<?php
});
