<?php
// ================================================
// pages/auto_writer.php — AI Article Writer Page
// ================================================
require_once __DIR__ . '/../includes/layout.php';
requireAdmin();

$db    = db();
$user  = currentUser();
$sites = $db->query("SELECT id, name FROM wp_sites WHERE status='active' ORDER BY name")->fetchAll();

// Fetch existing projects for this user
$projQuery = $db->prepare("SELECT * FROM ai_projects WHERE author_id=? ORDER BY created_at DESC");
$projQuery->execute([$user['id']]);
$projects = $projQuery->fetchAll();

pageWrap('auto_writer', 'Tulis Artikel Otomatis (AI)', 'Generate artikel SEO berkualitas menggunakan AI secara otomatis', function() use ($sites, $projects, $user) {
?>
<style>
.ai-tabs { display:flex; border-bottom:2px solid var(--border); margin-bottom:1.5rem; gap:0; }
.ai-tab-btn { padding:.75rem 1.5rem; border:none; background:none; cursor:pointer; font-weight:600; font-size:.875rem; color:var(--text-muted); border-bottom:2px solid transparent; margin-bottom:-2px; transition:all .2s; }
.ai-tab-btn.active { color:var(--navy); border-bottom-color:var(--orange); }
.ai-tab-btn:hover:not(.active) { color:var(--text); }
.ai-tab-pane { display:none; }
.ai-tab-pane.active { display:block; }
.project-card { border:1.5px solid var(--border); border-radius:var(--radius); padding:1rem 1.25rem; background:var(--surface); cursor:pointer; transition:all .2s; }
.project-card:hover, .project-card.selected { border-color:var(--navy); background:var(--navy-xlight); }
.project-card.selected { box-shadow:0 0 0 3px rgba(26,58,92,.12); }
.kw-count-badge { background:var(--orange); color:#fff; font-size:.7rem; font-weight:700; padding:.18rem .55rem; border-radius:99px; }
</style>

<div style="max-width:900px;margin:0 auto;">

  <!-- Page Header -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem">
    <div>
      <h2 style="margin:0;color:var(--navy)"><i class="fas fa-wand-magic-sparkles" style="color:var(--orange);margin-right:.5rem"></i>AI Auto Writer</h2>
      <p style="margin:.25rem 0 0;color:var(--text-muted);font-size:.875rem">Buat dan jadwalkan artikel SEO otomatis menggunakan kecerdasan buatan</p>
    </div>
    <a href="index.php?page=auto_posts" class="btn btn-outline" style="gap:.4rem">
      <i class="fas fa-calendar-days"></i> Lihat Kampanye
    </a>
  </div>

  <!-- Tabs -->
  <div class="ai-tabs">
    <button class="ai-tab-btn active" onclick="switchAiTab('tab-generate')" id="btn-generate">
      <i class="fas fa-bolt"></i> Generate Sekarang / Jadwalkan
    </button>
    <button class="ai-tab-btn" onclick="switchAiTab('tab-project')" id="btn-project">
      <i class="fas fa-folder-plus"></i> Kelola Proyek (<?= count($projects) ?>)
    </button>
  </div>

  <!-- TAB 1: Generate -->
  <div id="tab-generate" class="ai-tab-pane active">
    <div class="card">
      <div class="card-body">

        <!-- Alert info -->
        <div class="alert alert-info" style="margin-bottom:1.25rem">
          <i class="fas fa-circle-info"></i>
          Masukkan satu keyword per baris. Anda bisa generate langsung atau menjadwalkannya secara otomatis. Hubungkan ke <strong>Proyek</strong> untuk tracking yang lebih terorganisir.
        </div>

        <!-- Keywords + Source Material -->
        <div class="form-group">
          <label for="topic">Keyword / Topik Artikel <span style="color:var(--danger)">*</span></label>
          <textarea id="topic" class="form-control" rows="5"
            placeholder="cth:&#10;Tips Cara Merawat Kucing Anggora&#10;Manfaat Belajar Coding Sejak Dini&#10;Pentingnya Bahasa Inggris Untuk IT" required></textarea>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.4rem">
            <p class="form-hint" style="margin:0">Satu topik/kata kunci per baris untuk membuat beberapa artikel sekaligus.</p>
            <span id="keyword-count-badge" class="kw-count-badge">0 keyword</span>
          </div>
        </div>

        <div class="form-group">
          <label for="source-material">Sumber Berita / Materi Referensi <span style="color:var(--text-muted);font-weight:400">(Opsional)</span></label>
          <textarea id="source-material" class="form-control" rows="3"
            placeholder="Masukkan materi referensi. Pisahkan per artikel dengan baris kosong ganda (double enter)..."></textarea>
          <p class="form-hint">Jika 3 topik & 3 referensi dipisah double enter, sistem cocokkan 1-to-1. Jika 1 referensi, semua artikel pakai referensi yang sama.</p>
        </div>

        <!-- Site, Model, Language, Project in grid -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
          <div class="form-group">
            <label for="target-site">Target Situs WordPress</label>
            <select id="target-site" class="form-control form-select">
              <option value="">Hanya Simpan Draf (Lokal)</option>
              <?php foreach ($sites as $site): ?>
              <option value="<?= $site['id'] ?>"><?= sanitize($site['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="article-language">Bahasa Artikel</label>
            <select id="article-language" class="form-control form-select">
              <option value="indonesian">Bahasa Indonesia</option>
              <option value="english">English</option>
              <option value="japanese">Japanese (日本語)</option>
              <option value="arabic">Arabic (العربية)</option>
              <option value="spanish">Spanish (Español)</option>
              <option value="french">French (Français)</option>
              <option value="german">German (Deutsch)</option>
            </select>
          </div>
          <div class="form-group">
            <label for="ai-model">Model AI <span id="model-loading" style="font-size:.75rem;color:var(--text-muted)"><i class="fas fa-spinner fa-spin"></i> Memuat...</span></label>
            <select id="ai-model" class="form-control form-select">
              <option value="<?= AI_MODEL ?>">Default (<?= AI_MODEL ?>)</option>
            </select>
          </div>
          <div class="form-group">
            <label for="project-select">Tambahkan ke Proyek <span style="color:var(--text-muted);font-weight:400">(Opsional)</span></label>
            <div style="display:flex;gap:.5rem">
              <select id="project-select" class="form-control form-select" style="flex:1">
                <option value="">— Tanpa Proyek —</option>
                <?php foreach ($projects as $proj): ?>
                <option value="<?= $proj['id'] ?>"><?= sanitize($proj['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="button" class="btn btn-outline btn-icon" title="Buat Proyek Baru" onclick="openNewProjectModal()">
                <i class="fas fa-plus"></i>
              </button>
            </div>
          </div>
        </div>

        <!-- Scheduling Section -->
        <div class="card" style="border-color:var(--border);background:var(--surface2);margin-top:1rem">
          <div class="card-header" style="padding:.75rem 1rem">
            <h4 style="margin:0;font-size:.9rem"><i class="fas fa-clock" style="color:var(--navy);margin-right:.4rem"></i> Penjadwalan & Frekuensi</h4>
          </div>
          <div class="card-body" style="padding:1rem">
            <div class="form-group">
              <label for="schedule-type">Tipe Penjadwalan</label>
              <select id="schedule-type" class="form-control form-select">
                <option value="instant">🚀 Buat Sekarang & Publish Langsung</option>
                <option value="once">📌 Jadwalkan Sekali (Tiap Keyword 1x)</option>
                <option value="hourly">⏰ Setiap Jam (Auto-Post)</option>
                <option value="daily">📅 Setiap Hari (Auto-Post)</option>
                <option value="weekly">📆 Setiap Minggu (Auto-Post)</option>
                <option value="monthly">🗓️ Setiap Bulan (Auto-Post)</option>
                <option value="custom">⚙️ Interval Kustom (Jam)</option>
              </select>
            </div>

            <div id="schedule-details" style="display:none;margin-top:1rem;border-top:1px solid var(--border);padding-top:1rem">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                  <label for="scheduled-at">Waktu Mulai Pertama <span style="color:var(--danger)">*</span></label>
                  <input type="datetime-local" id="scheduled-at" class="form-control" min="<?= date('Y-m-d\TH:i') ?>">
                </div>
                <div class="form-group" id="field-custom-hours" style="display:none">
                  <label for="interval-hours">Interval Setiap (Jam) <span style="color:var(--danger)">*</span></label>
                  <input type="number" id="interval-hours" class="form-control" min="1" placeholder="cth: 12">
                </div>
                <div class="form-group" id="field-weekly-day" style="display:none">
                  <label for="day-of-week">Hari Penulisan</label>
                  <select id="day-of-week" class="form-control form-select">
                    <option value="1">Senin</option><option value="2">Selasa</option>
                    <option value="3">Rabu</option><option value="4">Kamis</option>
                    <option value="5">Jumat</option><option value="6">Sabtu</option>
                    <option value="0">Minggu</option>
                  </select>
                </div>
                <div class="form-group" id="field-monthly-day" style="display:none">
                  <label for="day-of-month">Tanggal Penulisan (1-31)</label>
                  <input type="number" id="day-of-month" class="form-control" min="1" max="31" placeholder="1">
                </div>
              </div>
              <div style="margin-top:.75rem;padding:.75rem 1rem;border-radius:var(--radius);background:rgba(59,130,246,.07);border:1px solid rgba(59,130,246,.2);font-size:.85rem;display:flex;align-items:center;gap:.6rem">
                <i class="fas fa-circle-info" style="color:#3b82f6"></i>
                <span id="schedule-info-text">Setiap keyword akan diposting <strong>sekali</strong> sesuai jadwal. Jika keyword habis, penjadwalan <strong>berhenti otomatis</strong>.</span>
              </div>
            </div>
          </div>
        </div>

        <button type="button" id="start-btn" class="btn btn-orange btn-block btn-lg" style="margin-top:1.25rem">
          <i class="fas fa-wand-magic-sparkles"></i> <span id="btn-label">Mulai Membuat Artikel</span>
        </button>
      </div>
    </div>

    <!-- Progress Container -->
    <div id="progress-container" style="display:none;margin-top:1.5rem">
      <div class="card">
        <div class="card-header">
          <h4 style="margin:0"><i class="fas fa-spinner fa-spin" style="color:var(--orange)"></i> <span id="progress-title">Proses Pembuatan Artikel</span></h4>
        </div>
        <div class="card-body">
          <div style="width:100%;height:12px;background:#e2e8f0;border-radius:10px;overflow:hidden;margin-bottom:1rem">
            <div id="progress-bar" style="width:0%;height:100%;background:linear-gradient(90deg,var(--navy) 0%,var(--orange) 100%);transition:width .4s ease"></div>
          </div>
          <div style="display:flex;justify-content:space-between;font-size:.85rem;font-weight:600;margin-bottom:1rem">
            <span id="status-text" style="color:var(--navy)">Menunggu...</span>
            <span id="percentage-text" style="color:var(--orange)">0%</span>
          </div>
          <div id="log-box" style="background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;height:240px;overflow-y:auto;font-family:monospace;font-size:.8rem;line-height:1.7;color:var(--text)">
            <div style="color:var(--text-light)">[SYSTEM] Menunggu proses...</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- TAB 2: Kelola Proyek -->
  <div id="tab-project" class="ai-tab-pane">
    <div class="card">
      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <h4 style="margin:0"><i class="fas fa-folder" style="color:var(--orange)"></i> Proyek AI</h4>
        <button type="button" class="btn btn-orange btn-sm" onclick="openNewProjectModal()">
          <i class="fas fa-plus"></i> Proyek Baru
        </button>
      </div>
      <?php if (empty($projects)): ?>
      <div style="padding:2.5rem;text-align:center;color:var(--text-muted)">
        <i class="fas fa-folder-open" style="font-size:3rem;margin-bottom:1rem;opacity:.4"></i>
        <p>Belum ada proyek. Buat proyek untuk mengelompokkan kampanye keyword Anda.</p>
        <button type="button" class="btn btn-orange btn-sm" onclick="openNewProjectModal()">
          <i class="fas fa-plus"></i> Buat Proyek Pertama
        </button>
      </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>Nama Proyek</th>
              <th>Target Situs</th>
              <th>Bahasa</th>
              <th>Dibuat</th>
              <th>Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php
            foreach ($projects as $proj):
              $siteName = '';
              if ($proj['site_id']) {
                $s = $db->prepare("SELECT name FROM wp_sites WHERE id=?");
                $s->execute([$proj['site_id']]);
                $sRow = $s->fetch();
                $siteName = $sRow['name'] ?? '';
              }
            ?>
            <tr>
              <td>
                <strong><?= sanitize($proj['name']) ?></strong>
                <?php if ($proj['description']): ?>
                <div style="font-size:.75rem;color:var(--text-muted);margin-top:.2rem"><?= sanitize(substr($proj['description'], 0, 80)) ?></div>
                <?php endif; ?>
              </td>
              <td><?= $siteName ? sanitize($siteName) : '<em style="color:var(--text-muted)">Lokal</em>' ?></td>
              <td><span class="badge badge-pending"><?= sanitize($proj['language']) ?></span></td>
              <td style="font-size:.8rem;color:var(--text-muted)"><?= formatDate($proj['created_at']) ?></td>
              <td>
                <a href="index.php?page=auto_posts&project_id=<?= $proj['id'] ?>" class="btn btn-outline btn-xs" title="Lihat Kampanye">
                  <i class="fas fa-eye"></i> Kampanye
                </a>
                <button type="button" class="btn btn-xs" style="background:#fef2f2;color:#ef4444;border:1px solid #fecaca"
                  onclick="deleteProject(<?= $proj['id'] ?>, '<?= sanitize($proj['name']) ?>')" title="Hapus">
                  <i class="fas fa-trash"></i>
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- Modal: Buat Proyek Baru -->
<div id="modal-new-project" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center">
  <div style="background:var(--surface);border-radius:var(--radius);padding:2rem;width:500px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem">
      <h3 style="margin:0"><i class="fas fa-folder-plus" style="color:var(--orange)"></i> Buat Proyek Baru</h3>
      <button type="button" onclick="closeNewProjectModal()" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:var(--text-muted)">&times;</button>
    </div>
    <div class="form-group">
      <label>Nama Proyek <span style="color:var(--danger)">*</span></label>
      <input type="text" id="new-project-name" class="form-control" placeholder="cth: Blog Teknologi Q3 2026">
    </div>
    <div class="form-group">
      <label>Deskripsi <span style="color:var(--text-muted);font-weight:400">(Opsional)</span></label>
      <textarea id="new-project-desc" class="form-control" rows="2" placeholder="Deskripsi singkat proyek..."></textarea>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
      <div class="form-group">
        <label>Target Situs</label>
        <select id="new-project-site" class="form-control form-select">
          <option value="">— Pilih Situs —</option>
          <?php foreach ($sites as $site): ?>
          <option value="<?= $site['id'] ?>"><?= sanitize($site['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Bahasa Default</label>
        <select id="new-project-lang" class="form-control form-select">
          <option value="indonesian">Bahasa Indonesia</option>
          <option value="english">English</option>
          <option value="japanese">Japanese</option>
          <option value="arabic">Arabic</option>
        </select>
      </div>
    </div>
    <div style="display:flex;gap:.75rem;justify-content:flex-end;margin-top:1rem">
      <button type="button" class="btn btn-outline" onclick="closeNewProjectModal()">Batal</button>
      <button type="button" class="btn btn-orange" id="save-project-btn" onclick="saveNewProject()">
        <i class="fas fa-save"></i> Simpan Proyek
      </button>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  // ---- Tab switch ----
  window.switchAiTab = (tabId) => {
    document.querySelectorAll('.ai-tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.ai-tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById(tabId).classList.add('active');
    const btnMap = { 'tab-generate': 'btn-generate', 'tab-project': 'btn-project' };
    document.getElementById(btnMap[tabId]).classList.add('active');
  };

  // ---- Modal ----
  window.openNewProjectModal = () => { document.getElementById('modal-new-project').style.display = 'flex'; };
  window.closeNewProjectModal = () => { document.getElementById('modal-new-project').style.display = 'none'; };

  window.saveNewProject = async () => {
    const name = document.getElementById('new-project-name').value.trim();
    if (!name) { alert('Nama proyek wajib diisi.'); return; }
    const btn = document.getElementById('save-project-btn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';
    try {
      const resp = await fetch('actions/ai_action.php?action=create_project', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name: name,
          description: document.getElementById('new-project-desc').value.trim(),
          site_id: document.getElementById('new-project-site').value,
          language: document.getElementById('new-project-lang').value
        })
      });
      const data = await resp.json();
      if (data.success) {
        // Add to project select dropdowns
        const opt = document.createElement('option');
        opt.value = data.project_id;
        opt.textContent = name;
        opt.selected = true;
        document.getElementById('project-select').appendChild(opt);
        closeNewProjectModal();
        alert('Proyek berhasil dibuat!');
        // Reload page to refresh project list tab
        location.reload();
      } else {
        alert('Gagal: ' + (data.error || 'Unknown error'));
      }
    } catch(e) { alert('Error: ' + e.message); }
    finally { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Simpan Proyek'; }
  };

  window.deleteProject = async (id, name) => {
    if (!confirm(`Hapus proyek "${name}"? Kampanye yang terhubung TIDAK dihapus, hanya tautan proyeknya.`)) return;
    const resp = await fetch('actions/ai_action.php?action=delete_project', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ project_id: id })
    });
    const data = await resp.json();
    if (data.success) { location.reload(); }
    else alert('Gagal hapus proyek: ' + (data.error || ''));
  };

  // ---- Schedule type toggle ----
  const scheduleType = document.getElementById('schedule-type');
  const scheduleDetails = document.getElementById('schedule-details');
  const fieldCustomHours = document.getElementById('field-custom-hours');
  const fieldWeeklyDay = document.getElementById('field-weekly-day');
  const fieldMonthlyDay = document.getElementById('field-monthly-day');
  const scheduleInfoText = document.getElementById('schedule-info-text');

  scheduleType.addEventListener('change', () => {
    const val = scheduleType.value;
    scheduleDetails.style.display = val === 'instant' ? 'none' : 'block';
    fieldCustomHours.style.display = val === 'custom' ? 'block' : 'none';
    fieldWeeklyDay.style.display = val === 'weekly' ? 'block' : 'none';
    fieldMonthlyDay.style.display = val === 'monthly' ? 'block' : 'none';
    if (val === 'once') {
      scheduleInfoText.innerHTML = 'Setiap keyword dijadwalkan <strong>sekali</strong> dengan jarak sesuai interval. Penjadwalan berhenti otomatis setelah semua keyword selesai.';
    } else if (val !== 'instant') {
      scheduleInfoText.innerHTML = 'Setiap keyword akan diposting <strong>sekali</strong> sesuai jadwal, dengan waktu yang di-stagger per keyword. Penjadwalan <strong>berhenti otomatis</strong> setelah semua keyword selesai.';
    }
    updateBtnLabel();
  });

  // ---- Keyword counter ----
  const topicTextarea = document.getElementById('topic');
  const kwBadge = document.getElementById('keyword-count-badge');

  function countKeywords() {
    return topicTextarea.value.split('\n').map(t => t.trim()).filter(t => t !== '').length;
  }

  function updateBtnLabel() {
    const n = countKeywords();
    const val = scheduleType.value;
    const label = document.getElementById('btn-label');
    kwBadge.textContent = n === 0 ? '0 keyword' : `${n} keyword${n > 1 ? 's' : ''}`;
    kwBadge.style.background = n > 0 ? 'var(--orange)' : '#94a3b8';
    if (val === 'instant') label.textContent = n > 1 ? `Buat ${n} Artikel Sekarang` : 'Mulai Membuat Artikel';
    else label.textContent = n > 0 ? `Jadwalkan ${n} Keyword` : 'Simpan Jadwal Auto-Post';
  }

  topicTextarea.addEventListener('input', updateBtnLabel);
  updateBtnLabel();

  // ---- Load AI Models ----
  async function loadAiModels() {
    try {
      const resp = await fetch('actions/ai_action.php?action=get_models');
      const data = await resp.json();
      if (data.success && data.models.length) {
        const sel = document.getElementById('ai-model');
        const defaultVal = sel.value;
        sel.innerHTML = '';
        data.models.forEach(m => {
          const opt = document.createElement('option');
          opt.value = m; opt.textContent = m;
          if (m === defaultVal) opt.selected = true;
          sel.appendChild(opt);
        });
      }
    } catch(e) { console.warn('Model load failed', e); }
    finally { document.getElementById('model-loading').style.display = 'none'; }
  }
  loadAiModels();

  // ---- Start Button ----
  const startBtn = document.getElementById('start-btn');
  const setupContainer = document.querySelector('#tab-generate .card:first-child');
  const progressContainer = document.getElementById('progress-container');
  const progressBar = document.getElementById('progress-bar');
  const statusText = document.getElementById('status-text');
  const percentageText = document.getElementById('percentage-text');
  const logBox = document.getElementById('log-box');

  function log(message, type = 'info') {
    const div = document.createElement('div');
    const time = new Date().toLocaleTimeString();
    let color = 'var(--text)';
    if (type === 'success') color = 'var(--success)';
    if (type === 'error') color = 'var(--danger)';
    if (type === 'warning') color = '#f59e0b';
    if (type === 'system') color = 'var(--text-light)';
    div.innerHTML = `<span style="color:var(--text-light)">[${time}]</span> <span style="color:${color}">${message}</span>`;
    logBox.appendChild(div);
    logBox.scrollTop = logBox.scrollHeight;
  }

  function updateProgress(pct, status) {
    progressBar.style.width = `${pct}%`;
    percentageText.textContent = `${pct}%`;
    statusText.textContent = status;
  }

  startBtn.addEventListener('click', async () => {
    const rawTopics = topicTextarea.value.split('\n').map(t => t.trim()).filter(t => t !== '');
    const rawRefs = document.getElementById('source-material').value.split('\n\n').map(r => r.trim());
    const siteId = document.getElementById('target-site').value;
    const schedType = scheduleType.value;
    const selectedModel = document.getElementById('ai-model').value;
    const selectedLanguage = document.getElementById('article-language').value;
    const projectId = document.getElementById('project-select').value;

    if (rawTopics.length === 0) { alert('Mohon masukkan minimal satu topik artikel.'); return; }

    // --- Scenario A: Schedule ---
    if (schedType !== 'instant') {
      const scheduledAt = document.getElementById('scheduled-at').value;
      if (!scheduledAt) { alert('Mohon tentukan waktu mulai pertama.'); return; }

      startBtn.disabled = true;
      startBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan Jadwal...';
      try {
        const intervalHours = parseInt(document.getElementById('interval-hours').value) || 24;
        let staggerMinutes = { instant:0, once:0, hourly:60, daily:1440, weekly:10080, monthly:43200, custom: intervalHours*60 }[schedType] || 1440;
        const baseTime = new Date(scheduledAt);
        let successCount = 0;

        for (let i = 0; i < rawTopics.length; i++) {
          const topic = rawTopics[i];
          const sourceMaterial = rawRefs.length > 1 ? (rawRefs[i] || '') : (rawRefs[0] || '');
          const keywordTime = new Date(baseTime.getTime() + i * staggerMinutes * 60 * 1000);
          const pad = n => String(n).padStart(2, '0');
          const staggeredAt = `${keywordTime.getFullYear()}-${pad(keywordTime.getMonth()+1)}-${pad(keywordTime.getDate())}T${pad(keywordTime.getHours())}:${pad(keywordTime.getMinutes())}`;

          const resp = await fetch('actions/ai_action.php?action=create_auto_job', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              topic, source_material: sourceMaterial, site_id: siteId,
              model: selectedModel, language: selectedLanguage,
              schedule_type: 'once', scheduled_at: staggeredAt,
              interval_hours: intervalHours,
              day_of_week: document.getElementById('day-of-week').value,
              day_of_month: document.getElementById('day-of-month').value,
              recur_until: null, recur_count: 1,
              project_id: projectId || null
            })
          });
          const resData = await resp.json();
          if (resData.success) successCount++;
        }
        alert(`Sukses! ${successCount} keyword dijadwalkan. Penjadwalan berhenti otomatis setelah semua keyword selesai.`);
        window.location.href = 'index.php?page=auto_posts' + (projectId ? '&project_id=' + projectId : '');
      } catch(err) {
        alert(err.message);
        startBtn.disabled = false;
        startBtn.innerHTML = '<i class="fas fa-wand-magic-sparkles"></i> <span id="btn-label">Jadwalkan Keyword</span>';
      }
      return;
    }

    // --- Scenario B: Instant Generation ---
    setupContainer.style.display = 'none';
    progressContainer.style.display = 'block';
    logBox.innerHTML = '';
    log(`Memulai pembuatan ${rawTopics.length} artikel...`, 'warning');
    log(`Model: "${selectedModel}" | Bahasa: "${selectedLanguage}"`, 'system');

    try {
      let successCount = 0, failedCount = 0;
      for (let i = 0; i < rawTopics.length; i++) {
        const topic = rawTopics[i];
        const sourceMaterial = rawRefs.length > 1 ? (rawRefs[i] || '') : (rawRefs[0] || '');
        log(`<hr style="border-top:1px dashed var(--border);margin:.75rem 0">`, 'system');
        log(`<strong>[ARTIKEL ${i+1}/${rawTopics.length}]</strong> "${topic}"`, 'info');
        updateProgress(Math.round((i / rawTopics.length) * 100), `Membuat artikel ${i+1}/${rawTopics.length}...`);
        log(`Menghasilkan outline & konten...`, 'info');

        const response = await fetch('actions/ai_action.php?action=write_full_post_auto', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ topic, source_material: sourceMaterial, site_id: siteId, model: selectedModel, language: selectedLanguage, project_id: projectId || null })
        });
        const data = await response.json();

        if (data.success) {
          successCount++;
          log(`✓ Selesai! Judul: "<strong>${data.title}</strong>"`, 'success');
          if (siteId) log(`✓ Terbit: <a href="${data.link}" target="_blank" style="color:var(--orange);font-weight:700">Lihat Postingan <i class="fas fa-arrow-up-right-from-square"></i></a>`, 'success');
          else log(`✓ Disimpan sebagai Draf Lokal.`, 'success');
        } else {
          failedCount++;
          log(`✗ Gagal: ${data.error}`, 'error');
        }
      }

      updateProgress(100, `Selesai: ${successCount} berhasil, ${failedCount} gagal.`);
      document.getElementById('progress-title').textContent = 'Proses Selesai!';
      log(`<hr style="border-top:1px solid var(--border);margin:.75rem 0">`, 'system');
      if (failedCount > 0) {
        log(`<strong>[SELESAI DENGAN PERINGATAN]</strong> ${successCount} berhasil, ${failedCount} gagal.`, 'warning');
      } else {
        log(`<strong>[SELESAI]</strong> Semua artikel berhasil. Mengalihkan...`, 'success');
        setTimeout(() => { window.location.href = 'index.php?page=auto_posts'; }, 3000);
      }

      // Show redirect button
      const closeBtn = document.createElement('button');
      closeBtn.className = 'btn btn-orange btn-block'; closeBtn.style.marginTop = '1rem';
      closeBtn.innerHTML = '<i class="fas fa-calendar-days"></i> Lihat Kampanye & Riwayat';
      closeBtn.onclick = () => { window.location.href = 'index.php?page=auto_posts'; };
      logBox.after(closeBtn);

    } catch(err) {
      log(`ERROR: ${err.message}`, 'error');
      updateProgress(0, 'Proses gagal.');
      const retryBtn = document.createElement('button');
      retryBtn.className = 'btn btn-outline btn-block'; retryBtn.style.marginTop = '1rem';
      retryBtn.innerHTML = '<i class="fas fa-undo"></i> Coba Lagi';
      retryBtn.onclick = () => { setupContainer.style.display = 'block'; progressContainer.style.display = 'none'; retryBtn.remove(); };
      logBox.after(retryBtn);
    }
  });
});
</script>
<?php
});
