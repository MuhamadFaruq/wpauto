// ================================================
// app.js — Main JavaScript for WP Dashboard
// ================================================

document.addEventListener('DOMContentLoaded', () => {

  // ---- Flash auto-hide ----
  const flash = document.querySelector('.alert[data-auto-hide]');
  if (flash) setTimeout(() => flash.classList.add('hidden'), 4000);

  // ---- Sidebar mobile toggle ----
  const sidebarToggle = document.getElementById('sidebar-toggle');
  const sidebar = document.querySelector('.sidebar');
  if (sidebarToggle && sidebar) {
    sidebarToggle.addEventListener('click', () => sidebar.classList.toggle('open'));
    document.addEventListener('click', (e) => {
      if (sidebar.classList.contains('open') &&
          !sidebar.contains(e.target) &&
          !sidebarToggle.contains(e.target)) {
        sidebar.classList.remove('open');
      }
    });
  }

  // ---- Modals ----
  document.querySelectorAll('[data-modal-open]').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-modal-open');
      const overlay = document.getElementById(id);
      if (overlay) {
        overlay.classList.add('show');
        document.body.style.overflow = 'hidden';
      }
    });
  });

  function closeModal(overlay) {
    if (!overlay) return;
    overlay.classList.remove('show');
    document.body.style.overflow = '';
  }

  document.querySelectorAll('[data-modal-close], .modal-close').forEach(btn => {
    btn.addEventListener('click', () => {
      const overlay = btn.closest('.modal-overlay');
      closeModal(overlay);
    });
  });

  document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) closeModal(overlay);
    });
  });

  // ---- Confirm Delete ----
  document.querySelectorAll('[data-confirm]').forEach(btn => {
    btn.addEventListener('click', (e) => {
      const msg = btn.getAttribute('data-confirm') || 'Yakin ingin menghapus?';
      if (!confirm(msg)) e.preventDefault();
    });
  });

  // ---- Schedule Toggle ----
  const scheduleType = document.getElementById('schedule_type');
  const scheduleOpts = document.getElementById('schedule-options');
  const scheduledFields = document.getElementById('scheduled-fields');
  const recurFields = document.getElementById('recur-fields');

  if (scheduleType) {
    function updateScheduleUI() {
      const val = scheduleType.value;
      if (scheduleOpts) scheduleOpts.classList.toggle('show', val !== 'now' && val !== 'draft');
      if (scheduledFields) scheduledFields.classList.toggle('hidden', val === 'now' || val === 'draft');
      if (recurFields) recurFields.classList.toggle('hidden', val === 'once' || val === 'now' || val === 'draft');
    }
    scheduleType.addEventListener('change', updateScheduleUI);
    updateScheduleUI();
  }

  // ---- Image Upload Preview ----
  const imageInput = document.getElementById('featured_image_file');
  const imageUrlInput = document.getElementById('featured_image_url');
  const imagePreview = document.getElementById('image-preview');
  const uploadArea = document.getElementById('upload-area');
  const removeImg = document.getElementById('remove-image');

  function showPreview(src) {
    if (!imagePreview) return;
    imagePreview.innerHTML = `<img src="${src}" alt="Preview">
      <div class="remove-img" id="remove-image"><i class="fas fa-times"></i></div>`;
    imagePreview.style.display = 'block';
    if (uploadArea) uploadArea.style.display = 'none';
    // Re-attach remove handler
    document.getElementById('remove-image')?.addEventListener('click', clearImage);
  }

  function clearImage() {
    if (imageInput) imageInput.value = '';
    if (imageUrlInput) imageUrlInput.value = '';
    if (imagePreview) { imagePreview.innerHTML = ''; imagePreview.style.display = 'none'; }
    if (uploadArea) uploadArea.style.display = 'block';
  }

  if (imageInput) {
    imageInput.addEventListener('change', () => {
      const file = imageInput.files[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = (e) => showPreview(e.target.result);
        reader.readAsDataURL(file);
        if (imageUrlInput) imageUrlInput.value = '';
      }
    });
  }

  if (imageUrlInput) {
    let urlTimer;
    imageUrlInput.addEventListener('input', () => {
      clearTimeout(urlTimer);
      const url = imageUrlInput.value.trim();
      if (!url) { clearImage(); return; }
      urlTimer = setTimeout(() => {
        if (url.match(/^https?:\/\/.+\.(jpg|jpeg|png|gif|webp)(\?.*)?$/i)) {
          showPreview(url);
          if (imageInput) imageInput.value = '';
        }
      }, 600);
    });
  }

  if (uploadArea) {
    uploadArea.addEventListener('click', () => imageInput?.click());
    uploadArea.addEventListener('dragover', (e) => { e.preventDefault(); uploadArea.classList.add('dragover'); });
    uploadArea.addEventListener('dragleave', () => uploadArea.classList.remove('dragover'));
    uploadArea.addEventListener('drop', (e) => {
      e.preventDefault();
      uploadArea.classList.remove('dragover');
      const file = e.dataTransfer.files[0];
      if (file && file.type.startsWith('image/') && imageInput) {
        const dt = new DataTransfer();
        dt.items.add(file);
        imageInput.files = dt.files;
        const reader = new FileReader();
        reader.onload = (ev) => showPreview(ev.target.result);
        reader.readAsDataURL(file);
      }
    });
  }

  // ---- Tag Input ----
  const tagInput = document.getElementById('tag-input');
  const tagContainer = document.getElementById('tag-container');
  const tagsHidden = document.getElementById('tags-hidden');

  if (tagInput && tagContainer) {
    let tags = tagsHidden?.value ? JSON.parse(tagsHidden.value) : [];

    function renderTags() {
      tagContainer.querySelectorAll('.tag-chip').forEach(el => el.remove());
      tags.forEach((tag, i) => {
        const chip = document.createElement('span');
        chip.className = 'tag-chip';
        chip.innerHTML = `${tag} <i class="fas fa-times" data-idx="${i}"></i>`;
        chip.querySelector('i').addEventListener('click', () => {
          tags.splice(i, 1);
          renderTags();
        });
        tagContainer.insertBefore(chip, tagInput);
      });
      if (tagsHidden) tagsHidden.value = JSON.stringify(tags);
    }

    tagInput.addEventListener('keydown', (e) => {
      if ((e.key === 'Enter' || e.key === ',') && tagInput.value.trim()) {
        e.preventDefault();
        const tag = tagInput.value.trim().replace(/,/g, '');
        if (tag && !tags.includes(tag)) { tags.push(tag); renderTags(); }
        tagInput.value = '';
      }
      if (e.key === 'Backspace' && !tagInput.value && tags.length) {
        tags.pop(); renderTags();
      }
    });

    renderTags();
  }

  // ---- Site test connection ----
  const testBtn = document.getElementById('test-connection-btn');
  if (testBtn) {
    testBtn.addEventListener('click', async () => {
      const apiUrl  = document.getElementById('api_base_url')?.value.trim();
      const user    = document.getElementById('app_username')?.value.trim();
      const pass    = document.getElementById('app_password')?.value.trim();
      const result  = document.getElementById('connection-result');

      if (!apiUrl || !user || !pass) {
        if (result) { result.className = 'alert alert-warning mt-2'; result.textContent = 'Isi semua field terlebih dahulu.'; result.classList.remove('hidden'); }
        return;
      }

      testBtn.disabled = true;
      testBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menguji...';
      if (result) result.classList.add('hidden');

      try {
        const resp = await fetch('actions/site_action.php?action=test', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ api_base_url: apiUrl, app_username: user, app_password: pass })
        });
        const data = await resp.json();
        if (result) {
          result.className = data.success ? 'alert alert-success mt-2' : 'alert alert-danger mt-2';
          result.textContent = data.message;
          result.classList.remove('hidden');
        }
      } catch(e) {
        if (result) { result.className = 'alert alert-danger mt-2'; result.textContent = 'Gagal menghubungi server.'; result.classList.remove('hidden'); }
      }

      testBtn.disabled = false;
      testBtn.innerHTML = '<i class="fas fa-plug"></i> Test Koneksi';
    });
  }

  // ---- Fetch WP authors for mapping ----
  const siteSelectMap = document.getElementById('map-site-select');
  const authorSelect  = document.getElementById('map-wp-author');
  if (siteSelectMap && authorSelect) {
    siteSelectMap.addEventListener('change', async () => {
      const siteId = siteSelectMap.value;
      if (!siteId) { authorSelect.innerHTML = '<option value="">-- Pilih Situs Dulu --</option>'; return; }
      authorSelect.innerHTML = '<option value="">Memuat...</option>';
      try {
        const resp = await fetch(`actions/site_action.php?action=get_authors&site_id=${siteId}`);
        const data = await resp.json();
        if (data.success && data.authors.length) {
          authorSelect.innerHTML = '<option value="">-- Pilih Author --</option>' +
            data.authors.map(a => `<option value="${a.id}" data-name="${a.name}">${a.name} (ID: ${a.id})</option>`).join('');
          authorSelect.addEventListener('change', () => {
            const opt = authorSelect.selectedOptions[0];
            const nameField = document.getElementById('map-wp-author-name');
            if (nameField && opt) nameField.value = opt.getAttribute('data-name') || '';
          });
        } else {
          authorSelect.innerHTML = '<option value="">Tidak ada author ditemukan</option>';
        }
      } catch(e) {
        authorSelect.innerHTML = '<option value="">Gagal memuat author</option>';
      }
    });
  }

  // ---- Loading overlay ----
  document.querySelectorAll('form[data-loading]').forEach(form => {
    form.addEventListener('submit', () => {
      const overlay = document.getElementById('loading-overlay');
      if (overlay) overlay.classList.add('show');
    });
  });

  // ---- Auto-generate slug from title ----
  const titleInput = document.getElementById('post-title');
  const slugInput  = document.getElementById('post-slug');
  if (titleInput && slugInput && !slugInput.value) {
    titleInput.addEventListener('input', () => {
      slugInput.value = titleInput.value
        .toLowerCase()
        .replace(/[^a-z0-9\s-]/g, '')
        .trim()
        .replace(/\s+/g, '-');
    });
  }

});
