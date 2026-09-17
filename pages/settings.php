<?php
// ================================================
// pages/settings.php — App Settings (Admin Only)
// ================================================
require_once __DIR__ . '/../includes/layout.php';
requireAdmin();

$db = db();

// Ensure settings table exists (graceful degradation)
try {
    $db->query("CREATE TABLE IF NOT EXISTS `app_settings` (
        `key`        VARCHAR(100) NOT NULL PRIMARY KEY,
        `value`      TEXT DEFAULT NULL,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    // table may already exist
}

// Fetch all settings
$settingsRaw = $db->query("SELECT `key`, `value` FROM app_settings")->fetchAll();
$settings = [];
foreach ($settingsRaw as $row) {
    $settings[$row['key']] = $row['value'];
}

// Defaults
$appName  = $settings['app_name']  ?? APP_NAME;
$timezone = $settings['timezone']  ?? 'Asia/Jakarta';
$postsPerPage = $settings['posts_per_page'] ?? '20';
$defaultStatus = $settings['default_post_status'] ?? 'draft';

// Common timezones (Asia-centric)
$timezones = [
    'Asia/Jakarta'     => 'WIB — Asia/Jakarta (UTC+7)',
    'Asia/Makassar'    => 'WITA — Asia/Makassar (UTC+8)',
    'Asia/Jayapura'    => 'WIT — Asia/Jayapura (UTC+9)',
    'Asia/Singapore'   => 'SGT — Asia/Singapore (UTC+8)',
    'Asia/Kuala_Lumpur'=> 'MYT — Asia/Kuala_Lumpur (UTC+8)',
    'Asia/Bangkok'     => 'ICT — Asia/Bangkok (UTC+7)',
    'Asia/Tokyo'       => 'JST — Asia/Tokyo (UTC+9)',
    'Asia/Shanghai'    => 'CST — Asia/Shanghai (UTC+8)',
    'Asia/Kolkata'     => 'IST — Asia/Kolkata (UTC+5:30)',
    'Europe/London'    => 'GMT — Europe/London (UTC+0)',
    'Europe/Paris'     => 'CET — Europe/Paris (UTC+1)',
    'America/New_York' => 'EST — America/New_York (UTC-5)',
    'UTC'              => 'UTC (UTC+0)',
];

pageWrap('settings', 'Pengaturan', 'Konfigurasi global aplikasi', function()
    use ($settings, $appName, $timezone, $postsPerPage, $defaultStatus, $timezones) {
?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:1.5rem;align-items:start">

  <!-- ============================================================ -->
  <!-- MAIN SETTINGS FORM                                            -->
  <!-- ============================================================ -->
  <div>
    <form method="POST" action="actions/user_action.php" data-loading>
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="save_settings">

      <!-- General Settings -->
      <div class="card" style="margin-bottom:1.25rem">
        <div class="card-header">
          <h3><i class="fas fa-sliders" style="color:var(--navy);margin-right:.4rem"></i> Pengaturan Umum</h3>
        </div>
        <div class="card-body">

          <div class="form-group">
            <label class="form-label">Nama Aplikasi</label>
            <input type="text" name="app_name" class="form-control"
                   value="<?= sanitize($appName) ?>"
                   placeholder="WP Dashboard">
            <div class="form-hint">Ditampilkan di judul tab browser dan sidebar</div>
          </div>

          <div class="form-group">
            <label class="form-label">Zona Waktu</label>
            <select name="timezone" class="form-control form-select">
              <?php foreach ($timezones as $tz => $label): ?>
              <option value="<?= $tz ?>" <?= $timezone === $tz ? 'selected' : '' ?>>
                <?= htmlspecialchars($label) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <div class="form-hint">
              Waktu server saat ini: <strong><?= date('d M Y H:i:s') ?></strong>
              (<?= date_default_timezone_get() ?>)
            </div>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
            <div class="form-group">
              <label class="form-label">Artikel per Halaman</label>
              <input type="number" name="posts_per_page" class="form-control"
                     value="<?= (int)$postsPerPage ?>" min="5" max="100" step="5">
            </div>
            <div class="form-group">
              <label class="form-label">Status Post Default</label>
              <select name="default_post_status" class="form-control form-select">
                <option value="draft"   <?= $defaultStatus === 'draft'   ? 'selected' : '' ?>>Draft</option>
                <option value="publish" <?= $defaultStatus === 'publish' ? 'selected' : '' ?>>Publish Langsung</option>
              </select>
            </div>
          </div>

        </div>
      </div>
      <!-- Integrasi API LLM & Pexels -->
      <div class="card" style="margin-bottom:1.25rem">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;padding:.85rem 1rem">
          <h3 style="margin:0;font-size:.95rem"><i class="fas fa-brain" style="color:var(--orange);margin-right:.4rem"></i> Integrasi API LLM & Pexels</h3>
          <button type="button" id="btn-test-llm" class="btn btn-sm btn-outline" style="padding:.25rem .5rem;font-size:.78rem">
            <i class="fas fa-plug-circle-bolt"></i> Test Koneksi LLM
          </button>
        </div>
        <div class="card-body">
          <div id="test-llm-alert" class="alert" style="display:none;margin-bottom:1rem;padding:.75rem 1rem;font-size:0.875rem"></div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
            <div class="form-group">
              <label class="form-label">API URL Router LLM</label>
              <input type="text" name="ai_api_url" id="ai_api_url" class="form-control"
                     value="<?= sanitize($settings['ai_api_url'] ?? '') ?>"
                     placeholder="https://router.penglaris.com/v1">
            </div>
            <div class="form-group">
              <label class="form-label">API Key Router LLM</label>
              <input type="password" name="ai_api_key" id="ai_api_key" class="form-control"
                     value="<?= sanitize($settings['ai_api_key'] ?? '') ?>"
                     placeholder="sk-...">
            </div>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:1rem">
            <div class="form-group">
              <label class="form-label">Default Model LLM</label>
              <input type="text" name="ai_model" id="ai_model" class="form-control"
                     value="<?= sanitize($settings['ai_model'] ?? '') ?>"
                     placeholder="oc/deepseek-v4-flash-free">
            </div>
            <div class="form-group">
              <label class="form-label">Basic Auth User</label>
              <input type="text" name="ai_basic_user" id="ai_basic_user" class="form-control"
                     value="<?= sanitize($settings['ai_basic_user'] ?? '') ?>"
                     placeholder="admin">
            </div>
            <div class="form-group">
              <label class="form-label">Basic Auth Password</label>
              <input type="password" name="ai_basic_pass" id="ai_basic_pass" class="form-control"
                     value="<?= sanitize($settings['ai_basic_pass'] ?? '') ?>"
                     placeholder="pass...">
            </div>
          </div>

          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Pexels API Key</label>
            <input type="password" name="pexels_api_key" id="pexels_api_key" class="form-control"
                   value="<?= sanitize($settings['pexels_api_key'] ?? '') ?>"
                   placeholder="ewrBI...">
          </div>
        </div>
      </div>
      <div class="card" style="margin-bottom:1.25rem">
        <div class="card-header">
          <h3><i class="fas fa-shield-halved" style="color:var(--orange);margin-right:.4rem"></i> Keamanan</h3>
        </div>
        <div class="card-body">

          <div class="form-group">
            <label class="form-label">Encryption Key</label>
            <div style="display:flex;gap:.5rem;align-items:center">
              <input type="text" class="form-control" style="font-family:monospace;font-size:.8rem;letter-spacing:.05em"
                     value="<?= str_repeat('•', 24) . substr(ENCRYPT_KEY, -8) ?>"
                     readonly style="background:var(--surface-alt);cursor:not-allowed">
              <button type="button" class="btn btn-outline btn-sm" title="Salin (tidak bisa dari sini)"
                      onclick="alert('Kunci enkripsi hanya bisa diubah langsung di file config.php')">
                <i class="fas fa-info-circle"></i>
              </button>
            </div>
            <div class="form-hint">
              <i class="fas fa-triangle-exclamation" style="color:var(--warning)"></i>
              Kunci enkripsi disimpan di <code>config.php</code>. Mengubahnya akan membuat semua password situs tidak bisa di-decrypt.
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Session Lifetime</label>
            <input type="text" class="form-control"
                   value="<?= (SESSION_LIFETIME / 3600) ?> jam (<?= SESSION_LIFETIME ?> detik)"
                   readonly style="background:var(--surface-alt)">
            <div class="form-hint">Ubah nilai <code>SESSION_LIFETIME</code> di <code>config.php</code></div>
          </div>

        </div>
      </div>

      <div style="text-align:right">
        <button type="submit" class="btn btn-orange">
          <i class="fas fa-save"></i> Simpan Pengaturan
        </button>
      </div>
    </form>
  </div>

  <!-- ============================================================ -->
  <!-- SIDEBAR INFO PANEL                                            -->
  <!-- ============================================================ -->
  <div>
    <!-- System Info -->
    <div class="card" style="margin-bottom:1.25rem">
      <div class="card-header">
        <h3><i class="fas fa-server" style="color:var(--navy);margin-right:.4rem"></i> Informasi Sistem</h3>
      </div>
      <div class="card-body" style="padding-top:.5rem">
        <?php
        $info = [
            'PHP Version'      => phpversion(),
            'Server Software'  => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'Database'         => 'MySQL / MariaDB',
            'APP_URL'          => APP_URL,
            'UPLOAD_DIR'       => UPLOAD_DIR,
            'Memory Limit'     => ini_get('memory_limit'),
            'Max Upload Size'  => ini_get('upload_max_filesize'),
            'Post Max Size'    => ini_get('post_max_size'),
        ];
        foreach ($info as $k => $v): ?>
        <div style="display:flex;justify-content:space-between;padding:.4rem 0;border-bottom:1px solid var(--border);font-size:.8rem;gap:.5rem">
          <span style="color:var(--text-muted);white-space:nowrap"><?= $k ?></span>
          <span style="font-weight:600;word-break:break-all;text-align:right"><?= sanitize((string)$v) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- DB Stats -->
    <?php
    try {
        $dbStats = [
            'Total User'     => db()->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'Situs WordPress'=> db()->query("SELECT COUNT(*) FROM wp_sites")->fetchColumn(),
            'Total Artikel'  => db()->query("SELECT COUNT(*) FROM posts")->fetchColumn(),
            'Log Aktivitas'  => db()->query("SELECT COUNT(*) FROM activity_log")->fetchColumn(),
        ];
    } catch (Throwable $e) {
        $dbStats = [];
    }
    if ($dbStats): ?>
    <div class="card">
      <div class="card-header">
        <h3><i class="fas fa-database" style="color:var(--orange);margin-right:.4rem"></i> Statistik DB</h3>
      </div>
      <div class="card-body" style="padding-top:.5rem">
        <?php foreach ($dbStats as $k => $v): ?>
        <div style="display:flex;justify-content:space-between;padding:.4rem 0;border-bottom:1px solid var(--border);font-size:.875rem">
          <span style="color:var(--text-muted)"><?= $k ?></span>
          <span style="font-weight:700"><?= number_format((int)$v) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const btnTestLlm = document.getElementById('btn-test-llm');
  const alertTestLlm = document.getElementById('test-llm-alert');

  if (btnTestLlm) {
    btnTestLlm.addEventListener('click', async () => {
      btnTestLlm.disabled = true;
      btnTestLlm.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menghubungkan...';
      alertTestLlm.style.display = 'none';

      try {
        const payload = {
          ai_api_url: document.getElementById('ai_api_url').value,
          ai_api_key: document.getElementById('ai_api_key').value,
          ai_model: document.getElementById('ai_model').value,
          ai_basic_user: document.getElementById('ai_basic_user').value,
          ai_basic_pass: document.getElementById('ai_basic_pass').value
        };

        const resp = await fetch('actions/ai_action.php?action=test_llm', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });

        const resData = await resp.json();
        if (resData.success) {
          alertTestLlm.className = 'alert alert-success';
          alertTestLlm.innerHTML = `<strong>Koneksi Sukses!</strong> API terhubung dengan baik.<br>Model terdeteksi: <code>${resData.model}</code>`;
        } else {
          throw new Error(resData.error || 'Gagal terhubung ke API.');
        }
      } catch (err) {
        alertTestLlm.className = 'alert alert-danger';
        alertTestLlm.innerHTML = `<strong>Koneksi Gagal:</strong> ${err.message}`;
      } finally {
        alertTestLlm.style.display = 'block';
        btnTestLlm.disabled = false;
        btnTestLlm.innerHTML = '<i class="fas fa-plug-circle-bolt"></i> Test Koneksi LLM';
      }
    });
  }
});
</script>

<?php
// ---- Detect server paths for cron instruction ----
$phpBin     = PHP_BINARY ?: '/usr/bin/php';
$cronScript = realpath(__DIR__ . '/../cron/scheduler.php');
$logPath    = realpath(__DIR__ . '/../cron/') . '/scheduler.log';
$cronToken  = 'wp_cron_' . md5('auto_post_scheduler_2026');
$cronHttpUrl = rtrim(APP_URL, '/') . '/cron/scheduler.php?token=' . $cronToken;
?>

<!-- ============================================================ -->
<!-- CRON / SCHEDULER SETUP                                       -->
<!-- ============================================================ -->
<div class="card" style="margin-top:1.5rem">
  <div class="card-header">
    <h3><i class="fas fa-terminal" style="color:var(--navy);margin-right:.4rem"></i> Setup Cron (Auto-Post Scheduler)</h3>
  </div>
  <div class="card-body">

    <div class="alert alert-info" style="margin-bottom:1.25rem">
      <i class="fas fa-circle-info"></i>
      Agar artikel AI terjadwal terbit otomatis <strong>tanpa perlu browser terbuka</strong>, Anda perlu menjalankan scheduler setiap menit via cron di VPS.
    </div>

    <!-- VPS Cron Command -->
    <div class="form-group">
      <label class="form-label">Perintah Cron (tambahkan ke <code>crontab -e</code> di VPS)</label>
      <div style="position:relative">
        <textarea id="cron-cmd" class="form-control" rows="2" readonly
          style="font-family:monospace;font-size:.82rem;background:var(--surface2);resize:none;user-select:all"
        >* * * * * <?= htmlspecialchars($phpBin) ?> <?= htmlspecialchars($cronScript) ?> >> <?= htmlspecialchars($logPath) ?> 2>&1</textarea>
        <button type="button" onclick="copyCron()"
          style="position:absolute;top:.5rem;right:.5rem;background:var(--navy);color:#fff;border:none;border-radius:6px;padding:.3rem .75rem;font-size:.78rem;cursor:pointer">
          <i class="fas fa-copy" id="copy-icon"></i> Salin
        </button>
      </div>
      <div class="form-hint">Cron ini berjalan setiap menit. Hanya akan memproses jadwal yang waktunya sudah tiba.</div>
    </div>

    <!-- Alternative: HTTP URL (fallback jika tidak bisa edit crontab) -->
    <div class="form-group" style="margin-top:1rem">
      <label class="form-label">Alternatif: URL Trigger (untuk cron berbasis URL / cPanel / external cron service)</label>
      <div style="position:relative">
        <input type="text" id="cron-url" class="form-control" readonly
          style="font-family:monospace;font-size:.78rem;background:var(--surface2);padding-right:80px"
          value="<?= htmlspecialchars($cronHttpUrl) ?>">
        <button type="button" onclick="copyCronUrl()"
          style="position:absolute;top:50%;right:.5rem;transform:translateY(-50%);background:var(--navy);color:#fff;border:none;border-radius:6px;padding:.3rem .75rem;font-size:.78rem;cursor:pointer">
          <i class="fas fa-copy"></i> Salin
        </button>
      </div>
      <div class="form-hint">URL ini aman — sudah dilindungi token rahasia. Bisa dipakai di cPanel Cron Jobs atau layanan seperti <a href="https://cron-job.org" target="_blank">cron-job.org</a>.</div>
    </div>

    <!-- Test Run Button -->
    <div style="display:flex;gap:.75rem;align-items:center;margin-top:1.25rem;flex-wrap:wrap">
      <button type="button" id="btn-test-cron" class="btn btn-outline"
        onclick="testCronNow()">
        <i class="fas fa-play-circle"></i> Jalankan Sekarang (Manual Test)
      </button>
      <a href="cron/scheduler.log" target="_blank" class="btn btn-outline btn-sm">
        <i class="fas fa-file-lines"></i> Buka Log File
      </a>
      <span id="cron-test-result" style="font-size:.85rem;display:none"></span>
    </div>

    <!-- Instructions -->
    <div style="margin-top:1.25rem;padding:1rem;background:var(--surface2);border-radius:var(--radius);border:1px solid var(--border)">
      <div style="font-weight:700;font-size:.85rem;margin-bottom:.75rem;color:var(--navy)"><i class="fas fa-list-ol"></i> Cara Setup di VPS</div>
      <ol style="margin:0;padding-left:1.25rem;font-size:.85rem;line-height:2">
        <li>SSH ke VPS: <code>ssh user@server_ip</code></li>
        <li>Buka crontab: <code>crontab -e</code></li>
        <li>Tempel perintah cron di atas pada baris baru</li>
        <li>Simpan dan keluar (<kbd>Ctrl+X</kbd> → <kbd>Y</kbd> → <kbd>Enter</kbd> untuk nano)</li>
        <li>Verifikasi: <code>crontab -l</code> — pastikan cron muncul</li>
        <li>Klik tombol <strong>Jalankan Sekarang</strong> di atas untuk test manual pertama kali</li>
      </ol>
    </div>

  </div>
</div>

<script>
function copyCron() {
  const el = document.getElementById('cron-cmd');
  el.select(); document.execCommand('copy');
  const icon = document.getElementById('copy-icon');
  icon.className = 'fas fa-check'; icon.parentElement.innerHTML = '<i class="fas fa-check"></i> Disalin!';
  setTimeout(() => { icon.parentElement.innerHTML = '<i class="fas fa-copy" id="copy-icon"></i> Salin'; }, 2000);
}
function copyCronUrl() {
  const el = document.getElementById('cron-url'); el.select(); document.execCommand('copy');
}
async function testCronNow() {
  const btn = document.getElementById('btn-test-cron');
  const result = document.getElementById('cron-test-result');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menjalankan...';
  result.style.display = 'inline';
  result.style.color = 'var(--text-muted)';
  result.textContent = 'Menghubungi scheduler...';
  try {
    const resp = await fetch('<?= htmlspecialchars($cronHttpUrl) ?>', { signal: AbortSignal.timeout(120000) });
    const data = await resp.json();
    if (data.success !== undefined) {
      result.style.color = 'var(--success)';
      result.textContent = `✓ Berhasil! ${data.processed} jadwal diproses.`;
    } else {
      result.style.color = 'var(--danger)';
      result.textContent = '✗ Error: ' + (data.error || 'Unknown');
    }
  } catch(e) {
    result.style.color = 'var(--danger)';
    result.textContent = '✗ Gagal terhubung: ' + e.message;
  }
  btn.disabled = false;
  btn.innerHTML = '<i class="fas fa-play-circle"></i> Jalankan Sekarang (Manual Test)';
}
</script>

<?php
});
