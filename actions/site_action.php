<?php
// ================================================
// actions/site_action.php — WordPress site management
// ================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../api/wordpress.php';
startSession();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ---- AJAX: Test connection ----
if ($action === 'test') {
    header('Content-Type: application/json');
    requireAdmin();
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    $apiUrl  = trim($data['api_base_url'] ?? '');
    $user    = trim($data['app_username'] ?? '');
    $pass    = trim($data['app_password'] ?? '');

    if (!$apiUrl || !$user || !$pass) {
        echo json_encode(['success' => false, 'message' => 'Data tidak lengkap.']);
        exit;
    }

    // Temporary site array (not in DB yet)
    $tempSite = [
        'api_base_url' => $apiUrl,
        'app_username' => $user,
        'app_password' => encryptPassword($pass),
    ];
    $api    = new WordPressAPI($tempSite);
    $result = $api->testConnection();

    if ($result['success']) {
        $siteName = $result['data']['name'] ?? 'WordPress Site';
        echo json_encode(['success' => true, 'message' => "✓ Koneksi berhasil! Situs: {$siteName}"]);
    } else {
        echo json_encode(['success' => false, 'message' => '✗ Gagal: ' . ($result['error'] ?? 'Unknown error')]);
    }
    exit;
}

// ---- AJAX: Get WP authors ----
if ($action === 'get_authors') {
    header('Content-Type: application/json');
    requireLogin();
    $siteId = (int)($_GET['site_id'] ?? 0);
    $api    = getWpApi($siteId);
    if (!$api) {
        echo json_encode(['success' => false, 'authors' => []]);
        exit;
    }
    $authors = $api->getAuthors();
    echo json_encode(['success' => true, 'authors' => $authors]);
    exit;
}

// ---- AJAX: Get WP categories ----
if ($action === 'get_categories') {
    header('Content-Type: application/json');
    requireLogin();
    $siteId = (int)($_GET['site_id'] ?? 0);
    $api    = getWpApi($siteId);
    if (!$api) {
        echo json_encode(['success' => false, 'categories' => []]);
        exit;
    }
    $cats = $api->getCategories();
    echo json_encode(['success' => true, 'categories' => $cats]);
    exit;
}

// ---- AJAX: Get live site overview stats ----
if ($action === 'get_live_stats') {
    header('Content-Type: application/json');
    requireLogin();
    $siteId = (int)($_GET['site_id'] ?? 0);
    $api    = getWpApi($siteId);
    if (!$api) {
        echo json_encode(['success' => false, 'message' => 'Situs tidak aktif atau tidak ditemukan.']);
        exit;
    }

    $detailsResult = $api->testConnection();
    if (!$detailsResult['success']) {
        echo json_encode(['success' => false, 'message' => $detailsResult['error'] ?? 'Offline']);
        exit;
    }

    $name = $detailsResult['data']['name'] ?? 'WordPress Site';
    $desc = $detailsResult['data']['description'] ?? '';
    
    // Get live post count from headers
    $metadata = $api->getSiteMetadata();
    $totalPosts = $metadata['success'] ? $metadata['total_posts'] : 0;

    // Get categories count
    $cats = $api->getCategories();
    $catCount = count($cats);

    echo json_encode([
        'success'     => true,
        'name'        => $name,
        'description' => $desc,
        'total_posts' => $totalPosts,
        'categories'  => $catCount
    ]);
    exit;
}

// ---- GET: Sync posts from WordPress site ----
if ($action === 'sync') {
    requireLogin();
    $siteId = (int)($_GET['site_id'] ?? 0);
    $api = getWpApi($siteId);
    if (!$api) {
        setFlash('error', 'Situs tidak ditemukan atau tidak aktif.');
        redirect(APP_URL . '/index.php?page=dashboard');
    }

    $posts = $api->getRecentPosts(100);
    if (empty($posts)) {
        setFlash('warning', 'Tidak ada artikel yang dapat disinkronkan atau API gagal merespons.');
        redirect(APP_URL . '/index.php?page=dashboard');
    }

    $db = db();
    $syncedCount = 0;
    $authorId = currentUser()['id'];

    foreach ($posts as $wpPost) {
        $wpPostId = (int)($wpPost['id'] ?? 0);
        if (!$wpPostId) continue;

        $title = $wpPost['title']['rendered'] ?? '';
        $content = $wpPost['content']['rendered'] ?? '';
        $excerpt = $wpPost['excerpt']['rendered'] ?? '';
        $slug = $wpPost['slug'] ?? '';
        $link = $wpPost['link'] ?? '';
        $publishedAt = isset($wpPost['date']) ? date('Y-m-d H:i:s', strtotime($wpPost['date'])) : date('Y-m-d H:i:s');
        $updatedAt = isset($wpPost['modified']) ? date('Y-m-d H:i:s', strtotime($wpPost['modified'])) : date('Y-m-d H:i:s');
        $wpCategories = $wpPost['categories'] ?? [];
        $categoriesJson = json_encode($wpCategories);

        // Check if post already exists locally
        $check = $db->prepare("SELECT id FROM posts WHERE site_id = ? AND wp_post_id = ?");
        $check->execute([$siteId, $wpPostId]);
        $existing = $check->fetch();

        if ($existing) {
            // Update
            $update = $db->prepare(
                "UPDATE posts 
                 SET title = ?, content = ?, excerpt = ?, slug = ?, wp_post_url = ?, categories = ?, status = 'published', updated_at = ?
                 WHERE id = ?"
            );
            $update->execute([$title, $content, $excerpt, $slug, $link, $categoriesJson, $updatedAt, $existing['id']]);
        } else {
            // Insert
            $insert = $db->prepare(
                "INSERT INTO posts (title, content, excerpt, slug, status, author_id, site_id, wp_post_id, wp_post_url, categories, created_at, updated_at, published_at)
                 VALUES (?, ?, ?, ?, 'published', ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $insert->execute([$title, $content, $excerpt, $slug, $authorId, $siteId, $wpPostId, $link, $categoriesJson, $publishedAt, $updatedAt, $publishedAt]);
            $syncedCount++;
        }
    }

    logActivity('posts_sync', "Sinkronisasi selesai untuk situs ID {$siteId}. {$syncedCount} artikel baru ditambahkan.");
    setFlash('success', "Sinkronisasi berhasil! " . ($syncedCount > 0 ? "{$syncedCount} artikel baru diimpor." : "Semua artikel sudah mutakhir."));
    redirect(APP_URL . '/index.php?page=dashboard');
}

// ---- All mutations require admin + CSRF ----
requireAdmin();

// ---- Add Site ----
if ($action === 'add' && isPost()) {
    verifyCsrf();
    $name       = trim($_POST['name'] ?? '');
    $url        = rtrim(trim($_POST['url'] ?? ''), '/');
    $apiBase    = rtrim(trim($_POST['api_base_url'] ?? ''), '/');
    $appUser    = trim($_POST['app_username'] ?? '');
    $appPass    = trim($_POST['app_password'] ?? '');
    $status     = $_POST['status'] ?? 'active';

    if (!$name || !$url || !$apiBase || !$appUser || !$appPass) {
        setFlash('error', 'Semua field wajib diisi.');
        redirect(APP_URL . '/index.php?page=sites');
    }

    $encPass = encryptPassword($appPass);
    $userId  = currentUser()['id'];

    db()->prepare(
        "INSERT INTO wp_sites (name, url, api_base_url, app_username, app_password, status, added_by) VALUES (?,?,?,?,?,?,?)"
    )->execute([$name, $url, $apiBase, $appUser, $encPass, $status, $userId]);

    logActivity('site_add', "Situs ditambahkan: {$name}");
    setFlash('success', "Situs \"{$name}\" berhasil ditambahkan.");
    redirect(APP_URL . '/index.php?page=sites');
}

// ---- Edit Site ----
if ($action === 'edit' && isPost()) {
    verifyCsrf();
    $id      = (int)($_POST['site_id'] ?? 0);
    $name    = trim($_POST['name'] ?? '');
    $url     = rtrim(trim($_POST['url'] ?? ''), '/');
    $apiBase = rtrim(trim($_POST['api_base_url'] ?? ''), '/');
    $appUser = trim($_POST['app_username'] ?? '');
    $appPass = trim($_POST['app_password'] ?? '');
    $status  = $_POST['status'] ?? 'active';

    if (!$id || !$name || !$url || !$apiBase || !$appUser) {
        setFlash('error', 'Data tidak valid.');
        redirect(APP_URL . '/index.php?page=sites');
    }

    if ($appPass) {
        $encPass = encryptPassword($appPass);
        db()->prepare(
            "UPDATE wp_sites SET name=?,url=?,api_base_url=?,app_username=?,app_password=?,status=?,updated_at=NOW() WHERE id=?"
        )->execute([$name, $url, $apiBase, $appUser, $encPass, $status, $id]);
    } else {
        db()->prepare(
            "UPDATE wp_sites SET name=?,url=?,api_base_url=?,app_username=?,status=?,updated_at=NOW() WHERE id=?"
        )->execute([$name, $url, $apiBase, $appUser, $status, $id]);
    }

    logActivity('site_edit', "Situs diedit: {$name}");
    setFlash('success', "Situs \"{$name}\" berhasil diperbarui.");
    redirect(APP_URL . '/index.php?page=sites');
}

// ---- Delete Site ----
if ($action === 'delete' && isPost()) {
    verifyCsrf();
    $id = (int)($_POST['site_id'] ?? 0);
    if (!$id) { setFlash('error', 'ID tidak valid.'); redirect(APP_URL . '/index.php?page=sites'); }

    $site = db()->prepare("SELECT name FROM wp_sites WHERE id=?");
    $site->execute([$id]);
    $siteName = $site->fetchColumn();

    db()->prepare("DELETE FROM wp_sites WHERE id=?")->execute([$id]);
    logActivity('site_delete', "Situs dihapus: {$siteName}");
    setFlash('success', "Situs berhasil dihapus.");
    redirect(APP_URL . '/index.php?page=sites');
}

// ---- Save Author Mapping ----
if ($action === 'save_mapping' && isPost()) {
    verifyCsrf();
    $mapId       = (int)($_POST['map_id'] ?? 0);
    $userId      = (int)($_POST['user_id'] ?? 0);
    $siteId      = (int)($_POST['site_id'] ?? 0);
    $wpAuthorId  = (int)($_POST['wp_author_id'] ?? 0);
    $wpAuthorName= trim($_POST['wp_author_name'] ?? '');

    if (!$userId || !$siteId || !$wpAuthorId || !$wpAuthorName) {
        setFlash('error', 'Semua field mapping wajib diisi.');
        redirect(APP_URL . '/index.php?page=author_map');
    }

    if ($mapId) {
        db()->prepare(
            "UPDATE wp_author_map SET user_id=?,site_id=?,wp_author_id=?,wp_author_name=? WHERE id=?"
        )->execute([$userId, $siteId, $wpAuthorId, $wpAuthorName, $mapId]);
        $msg = 'Mapping berhasil diperbarui.';
    } else {
        // Insert or update on duplicate
        db()->prepare(
            "INSERT INTO wp_author_map (user_id,site_id,wp_author_id,wp_author_name) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE wp_author_id=VALUES(wp_author_id), wp_author_name=VALUES(wp_author_name)"
        )->execute([$userId, $siteId, $wpAuthorId, $wpAuthorName]);
        $msg = 'Mapping berhasil disimpan.';
    }

    logActivity('author_map', "Mapping author: user {$userId} → WP author {$wpAuthorName} pada site {$siteId}");
    setFlash('success', $msg);
    redirect(APP_URL . '/index.php?page=author_map');
}

// ---- Delete Author Mapping ----
if ($action === 'delete_mapping' && isPost()) {
    verifyCsrf();
    $id = (int)($_POST['map_id'] ?? 0);
    db()->prepare("DELETE FROM wp_author_map WHERE id=?")->execute([$id]);
    setFlash('success', 'Mapping dihapus.');
    redirect(APP_URL . '/index.php?page=author_map');
}

redirect(APP_URL . '/index.php?page=sites');
