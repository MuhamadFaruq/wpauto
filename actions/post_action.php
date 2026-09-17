<?php
// ================================================
// actions/post_action.php — Post CRUD + Publish
// ================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../api/wordpress.php';
startSession();
requireLogin();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user   = currentUser();

// Helper: ensure upload dir exists
function ensureUploadDir(): string {
    $dir = UPLOAD_DIR . 'images/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}

// Helper: handle featured image upload or URL
function handleFeaturedImage(int $siteId): array {
    $result = ['url' => null, 'wp_media_id' => null];

    // File upload takes priority
    if (!empty($_FILES['featured_image_file']['tmp_name'])) {
        $file = $_FILES['featured_image_file'];
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file['type'], $allowed)) return $result;

        $ext  = pathinfo($file['name'], PATHINFO_EXTENSION);
        $name = uniqid('img_', true) . '.' . $ext;
        $path = ensureUploadDir() . $name;

        if (move_uploaded_file($file['tmp_name'], $path)) {
            $result['url'] = UPLOAD_URL . 'images/' . $name;

            // Upload to WordPress Media Library
            if ($siteId) {
                $api      = getWpApi($siteId);
                $uploaded = $api ? $api->uploadMedia($path, $name, $file['type']) : null;
                if ($uploaded && $uploaded['success']) {
                    $result['wp_media_id'] = $uploaded['media_id'];
                    $result['url']         = $uploaded['source_url'];
                }
            }
        }
    } elseif (!empty($_POST['featured_image_url'])) {
        $result['url'] = trim($_POST['featured_image_url']);
    }

    return $result;
}

// Helper: publish post to WordPress
function publishToWordPress(array $post, array $schedule = []): array {
    $siteId = (int)$post['site_id'];
    if (!$siteId) return ['success' => false, 'error' => 'Site ID kosong'];

    $api = getWpApi($siteId);
    if (!$api) return ['success' => false, 'error' => 'Site tidak ditemukan atau nonaktif'];

    $authorId = currentUser()['id'];

    // Get WP author mapping
    $stmt = db()->prepare("SELECT wp_author_id FROM wp_author_map WHERE user_id=? AND site_id=?");
    $stmt->execute([$authorId, $siteId]);
    $mapping = $stmt->fetch();
    $wpAuthorId = $mapping ? $mapping['wp_author_id'] : null;

    // Determine WP post status
    $wpStatus = 'publish';
    if (!empty($schedule) && $schedule['schedule_type'] !== 'now') {
        $wpStatus = 'future';
    }

    // Resolve tags to WP tag IDs
    $tagIds = [];
    $tagsJson = $post['tags'] ?? '[]';
    $tagNames = json_decode($tagsJson, true) ?: [];
    foreach ($tagNames as $tagName) {
        $id = $api->ensureTag($tagName);
        if ($id) $tagIds[] = $id;
    }

    $wpData = [
        'title'           => $post['title'],
        'content'         => $post['content'],
        'excerpt'         => $post['excerpt'] ?? '',
        'status'          => $wpStatus,
        'categories'      => json_decode($post['categories'] ?? '[]', true) ?: [],
        'tags'            => $tagIds,
    ];

    if ($wpAuthorId) $wpData['author'] = (int)$wpAuthorId;

    // Handle featured image: upload if not already uploaded to WordPress
    $wpMediaId = $post['featured_image_wp_id'];
    if (!$wpMediaId && !empty($post['featured_image_url'])) {
        $imageUrl = $post['featured_image_url'];
        
        // 1. Try local file path first if URL is from our local uploads directory
        $localPath = null;
        $isLocal = false;
        if (strpos($imageUrl, UPLOAD_URL) === 0) {
            $fileName = basename($imageUrl);
            $localPath = UPLOAD_DIR . 'images/' . $fileName;
            if (file_exists($localPath)) {
                $isLocal = true;
            }
        }

        if ($isLocal) {
            $mime = mime_content_type($localPath) ?: 'image/jpeg';
            $uploaded = $api->uploadMedia($localPath, basename($localPath), $mime);
            if ($uploaded && $uploaded['success']) {
                $wpMediaId = $uploaded['media_id'];
                // Update local database immediately
                db()->prepare("UPDATE posts SET featured_image_wp_id=? WHERE id=?")->execute([$wpMediaId, $post['id']]);
            }
        } else {
            // 2. Fallback: download external URL (e.g. Pexels) to temp file and upload
            $tempImg = downloadImageToTemp($imageUrl);
            if ($tempImg) {
                $uploaded = $api->uploadMedia($tempImg['path'], $tempImg['name'], $tempImg['mime']);
                if ($uploaded && $uploaded['success']) {
                    $wpMediaId = $uploaded['media_id'];
                    // Update local database immediately
                    db()->prepare("UPDATE posts SET featured_image_wp_id=? WHERE id=?")->execute([$wpMediaId, $post['id']]);
                }
                @unlink($tempImg['path']);
            }
        }
    }

    if ($wpMediaId) {
        $wpData['featured_media'] = (int)$wpMediaId;
    } else {
        $wpData['featured_media'] = 0; // 0 clears featured image in WordPress REST API
    }

    if ($wpStatus === 'future' && !empty($schedule['scheduled_at'])) {
        // WordPress expects ISO 8601 in site local time
        $wpData['date'] = (new DateTime($schedule['scheduled_at']))->format('Y-m-d\TH:i:s');
    }

    if (!empty($post['slug'])) $wpData['slug'] = $post['slug'];

    // Update if already published, otherwise publish new
    if (!empty($post['wp_post_id'])) {
        return $api->updatePost((int)$post['wp_post_id'], $wpData);
    }

    return $api->publishPost($wpData);
}

// ---- AJAX: Get categories for a site ----
if ($action === 'get_categories') {
    header('Content-Type: application/json');
    $siteId = (int)($_GET['site_id'] ?? 0);
    $api = getWpApi($siteId);
    if (!$api) { echo json_encode(['success' => false, 'categories' => []]); exit; }
    $cats = $api->getCategories();
    echo json_encode(['success' => true, 'categories' => $cats]);
    exit;
}

// ---- Create Post ----
if ($action === 'create' && isPost()) {
    verifyCsrf();

    $title      = trim($_POST['title'] ?? '');
    $content    = $_POST['content'] ?? '';
    $excerpt    = trim($_POST['excerpt'] ?? '');
    $slug       = trim($_POST['slug'] ?? '');
    $siteId     = (int)($_POST['site_id'] ?? 0);
    $categories = $_POST['categories'] ?? '';
    if (empty($categories) && !empty($_POST['category_id'])) {
        $categories = json_encode([(int)$_POST['category_id']]);
    }
    if (empty($categories)) {
        $categories = '[]';
    }
    if (!is_string($categories)) $categories = json_encode($categories);
    $tags       = $_POST['tags'] ?? '[]';
    $schedType  = $_POST['schedule_type'] ?? 'draft';

    if (!$title || !$content) {
        setFlash('error', 'Judul dan konten wajib diisi.'); redirect(APP_URL . '/index.php?page=new_post');
    }

    // Handle image
    $imgData = handleFeaturedImage($siteId);

    // Determine post status
    $status = match($schedType) {
        'draft'   => 'draft',
        'now'     => 'pending_publish',
        default   => 'scheduled',
    };

    // Insert post
    $stmt = db()->prepare(
        "INSERT INTO posts (title,content,excerpt,slug,status,author_id,site_id,categories,tags,
                           featured_image_url,featured_image_wp_id,created_at,updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())"
    );
    $stmt->execute([
        $title, $content, $excerpt, $slug ?: null, $status, $user['id'],
        $siteId ?: null, $categories, $tags,
        $imgData['url'], $imgData['wp_media_id'],
    ]);
    $postId = (int)db()->lastInsertId();

    // Save schedule
    if ($schedType !== 'draft' && $schedType !== 'now' && !empty($_POST['scheduled_at'])) {
        $scheduledAt  = $_POST['scheduled_at'] ?? '';
        $intervalHrs  = (int)($_POST['interval_hours'] ?? 0);
        $dayOfWeek    = isset($_POST['day_of_week'])   ? (int)$_POST['day_of_week']   : null;
        $dayOfMonth   = isset($_POST['day_of_month'])  ? (int)$_POST['day_of_month']  : null;
        $recurUntil   = $_POST['recur_until']   ?? null;
        $recurCount   = isset($_POST['recur_count']) && $_POST['recur_count'] !== '' ? (int)$_POST['recur_count'] : null;

        $validTypes = ['once','hourly','daily','weekly','monthly','custom'];
        if (!in_array($schedType, $validTypes)) $schedType = 'once';

        db()->prepare(
            "INSERT INTO post_schedules (post_id,schedule_type,scheduled_at,interval_hours,day_of_week,day_of_month,recur_until,recur_count)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([$postId, $schedType, $scheduledAt, $intervalHrs ?: null, $dayOfWeek, $dayOfMonth, $recurUntil ?: null, $recurCount]);
    }

    logActivity('post_create', "Artikel dibuat: {$title}");

    // Publish now if requested
    if ($schedType === 'now') {
        $post = db()->prepare("SELECT * FROM posts WHERE id=?")->execute([$postId]) ? null : null;
        $postStmt = db()->prepare("SELECT * FROM posts WHERE id=?"); $postStmt->execute([$postId]);
        $fullPost = $postStmt->fetch();

        $res = publishToWordPress($fullPost);
        if ($res['success']) {
            $wpPostId  = $res['data']['id'];
            $wpPostUrl = $res['data']['link'];
            db()->prepare(
                "UPDATE posts SET status='published', wp_post_id=?, wp_post_url=?, published_at=NOW() WHERE id=?"
            )->execute([$wpPostId, $wpPostUrl, $postId]);
            logActivity('post_publish', "Artikel diterbitkan ke WordPress: {$title}");
            setFlash('success', "Artikel berhasil diterbitkan ke WordPress!");
        } else {
            db()->prepare("UPDATE posts SET status='failed', error_message=? WHERE id=?")->execute([$res['error'], $postId]);
            setFlash('error', "Artikel gagal diterbitkan: " . ($res['error'] ?? 'Unknown error'));
        }
    } elseif ($schedType === 'draft') {
        setFlash('success', 'Artikel disimpan sebagai draft.');
    } else {
        setFlash('success', 'Artikel dijadwalkan untuk dipublikasikan.');
    }

    redirect(APP_URL . '/index.php?page=posts');
}

// ---- Update Post ----
if ($action === 'update' && isPost()) {
    verifyCsrf();

    $postId = (int)($_POST['post_id'] ?? 0);

    // Load post and check ownership
    $postStmt = db()->prepare("SELECT * FROM posts WHERE id=?"); $postStmt->execute([$postId]);
    $post = $postStmt->fetch();
    if (!$post) { setFlash('error', 'Artikel tidak ditemukan.'); redirect(APP_URL . '/index.php?page=posts'); }
    if (!isAdmin() && $post['author_id'] != $user['id']) {
        setFlash('error', 'Akses ditolak.'); redirect(APP_URL . '/index.php?page=posts');
    }

    $title      = trim($_POST['title'] ?? '');
    $content    = $_POST['content'] ?? '';
    $excerpt    = trim($_POST['excerpt'] ?? '');
    $slug       = trim($_POST['slug'] ?? '');
    $siteId     = (int)($_POST['site_id'] ?? 0);
    $categories = $_POST['categories'] ?? '';
    if (empty($categories) && !empty($_POST['category_id'])) {
        $categories = json_encode([(int)$_POST['category_id']]);
    }
    if (empty($categories)) {
        $categories = '[]';
    }
    if (!is_string($categories)) $categories = json_encode($categories);
    $tags       = $_POST['tags'] ?? '[]';
    $schedType  = $_POST['schedule_type'] ?? 'draft';

    if (!$title || !$content) {
        setFlash('error', 'Judul dan konten wajib diisi.'); redirect(APP_URL . '/index.php?page=edit_post&id=' . $postId);
    }

    // Handle image (keep existing if no new upload/url, unless cleared)
    $clearImage = ($_POST['clear_image'] ?? '0') === '1';
    $imgData    = handleFeaturedImage($siteId);

    if ($clearImage) {
        $imgUrl  = null;
        $imgWpId = null;
    } else {
        $imgUrl  = $imgData['url'] ?: $post['featured_image_url'];
        $imgWpId = $imgData['wp_media_id'] ?: $post['featured_image_wp_id'];
    }

    $status = match($schedType) {
        'draft'   => 'draft',
        'now'     => 'pending_publish',
        default   => 'scheduled',
    };

    db()->prepare(
        "UPDATE posts SET title=?,content=?,excerpt=?,slug=?,status=?,site_id=?,categories=?,tags=?,
                         featured_image_url=?,featured_image_wp_id=?,updated_at=NOW() WHERE id=?"
    )->execute([$title, $content, $excerpt, $slug ?: null, $status, $siteId ?: null,
                $categories, $tags, $imgUrl, $imgWpId ?: null, $postId]);

    // Update schedule
    db()->prepare("DELETE FROM post_schedules WHERE post_id=?")->execute([$postId]);
    if ($schedType !== 'draft' && $schedType !== 'now' && !empty($_POST['scheduled_at'])) {
        $scheduledAt = $_POST['scheduled_at'];
        $intervalHrs = (int)($_POST['interval_hours'] ?? 0);
        $dayOfWeek   = isset($_POST['day_of_week'])  ? (int)$_POST['day_of_week']  : null;
        $dayOfMonth  = isset($_POST['day_of_month']) ? (int)$_POST['day_of_month'] : null;
        $recurUntil  = $_POST['recur_until']  ?? null;
        $recurCount  = isset($_POST['recur_count']) && $_POST['recur_count'] !== '' ? (int)$_POST['recur_count'] : null;
        $validTypes  = ['once','hourly','daily','weekly','monthly','custom'];
        if (!in_array($schedType, $validTypes)) $schedType = 'once';

        db()->prepare(
            "INSERT INTO post_schedules (post_id,schedule_type,scheduled_at,interval_hours,day_of_week,day_of_month,recur_until,recur_count)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([$postId, $schedType, $scheduledAt, $intervalHrs ?: null, $dayOfWeek, $dayOfMonth, $recurUntil ?: null, $recurCount]);
    }

    logActivity('post_update', "Artikel diperbarui: {$title}");

    if ($schedType === 'now') {
        $freshStmt = db()->prepare("SELECT * FROM posts WHERE id=?"); $freshStmt->execute([$postId]);
        $fullPost  = $freshStmt->fetch();

        // Call publishToWordPress which handles both new publish and update dynamically
        $res = publishToWordPress($fullPost);

        if ($res['success']) {
            $wpPostId  = $res['data']['id'];
            $wpPostUrl = $res['data']['link'];
            db()->prepare(
                "UPDATE posts SET status='published',wp_post_id=?,wp_post_url=?,published_at=NOW() WHERE id=?"
            )->execute([$wpPostId, $wpPostUrl, $postId]);
            logActivity('post_publish', "Artikel diterbitkan: {$title}");
            setFlash('success', 'Artikel berhasil diterbitkan ke WordPress!');
        } else {
            db()->prepare("UPDATE posts SET status='failed',error_message=? WHERE id=?")->execute([$res['error'], $postId]);
            setFlash('error', 'Gagal menerbitkan: ' . ($res['error'] ?? 'Unknown'));
        }
    } elseif ($schedType === 'draft') {
        setFlash('success', 'Draft berhasil disimpan.');
    } else {
        setFlash('success', 'Jadwal posting diperbarui.');
    }

    redirect(APP_URL . '/index.php?page=posts');
}

// ---- Delete Post ----
if ($action === 'delete') {
    // Validate CSRF token (supports GET from link or POST from form)
    $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }

    $postId = (int)($_POST['post_id'] ?? $_GET['id'] ?? 0);
    $postStmt = db()->prepare("SELECT * FROM posts WHERE id=?"); $postStmt->execute([$postId]);
    $post = $postStmt->fetch();

    if (!$post) { setFlash('error', 'Artikel tidak ditemukan.'); redirect(APP_URL . '/index.php?page=posts'); }
    if (!isAdmin() && $post['author_id'] != $user['id']) {
        setFlash('error', 'Akses ditolak.'); redirect(APP_URL . '/index.php?page=posts');
    }

    // If published, optionally delete from WP
    $deleteFromWp = isset($_POST['delete_from_wp']) || isset($_GET['delete_from_wp']);
    if ($post['wp_post_id'] && $post['site_id'] && $deleteFromWp) {
        $api = getWpApi((int)$post['site_id']);
        if ($api) $api->deletePost((int)$post['wp_post_id']);
    }

    db()->prepare("DELETE FROM posts WHERE id=?")->execute([$postId]);
    logActivity('post_delete', "Artikel dihapus: {$post['title']}");
    setFlash('success', 'Artikel berhasil dihapus.');
    redirect(APP_URL . '/index.php?page=posts');
}

// ---- Bulk Delete ----
if ($action === 'bulk_delete' && isPost()) {
    verifyCsrf();
    $ids = $_POST['post_ids'] ?? $_POST['selected_ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        setFlash('warning', 'Tidak ada artikel yang dipilih.'); redirect(APP_URL . '/index.php?page=posts');
    }

    $ids = array_map('intval', $ids);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $params = $ids;
    if (!isAdmin()) {
        $query = "DELETE FROM posts WHERE id IN ({$placeholders}) AND author_id=?";
        $params[] = $user['id'];
    } else {
        $query = "DELETE FROM posts WHERE id IN ({$placeholders})";
    }

    db()->prepare($query)->execute($params);
    logActivity('post_bulk_delete', count($ids) . ' artikel dihapus');
    setFlash('success', count($ids) . ' artikel berhasil dihapus.');
    redirect(APP_URL . '/index.php?page=posts');
}

// ---- Export Activity CSV (Admin) ----
if ($action === 'export_activity') {
    requireAdmin();
    $rows = db()->query(
        "SELECT a.created_at, u.full_name, a.action, a.details, a.ip_address
         FROM activity_log a LEFT JOIN users u ON a.user_id=u.id ORDER BY a.created_at DESC"
    )->fetchAll();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="activity_log_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Waktu','User','Aksi','Detail','IP']);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

// ---- Toggle Campaign Schedule Active State ----
if ($action === 'toggle_schedule') {
    $scheduleId = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare("SELECT * FROM post_schedules WHERE id=?");
    $stmt->execute([$scheduleId]);
    $schedule = $stmt->fetch();
    
    if ($schedule) {
        $newActive = $schedule['is_active'] ? 0 : 1;
        db()->prepare("UPDATE post_schedules SET is_active=? WHERE id=?")->execute([$newActive, $scheduleId]);
        logActivity('auto_job_toggle', "Status kampanye ID {$scheduleId} diubah ke " . ($newActive ? 'Aktif' : 'Nonaktif'));
        setFlash('success', 'Status kampanye berhasil diperbarui.');
    } else {
        setFlash('error', 'Kampanye tidak ditemukan.');
    }
    redirect(APP_URL . '/index.php?page=auto_posts');
}

// ---- Delete Campaign Schedule ----
if ($action === 'delete_schedule') {
    $scheduleId = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare("SELECT * FROM post_schedules WHERE id=?");
    $stmt->execute([$scheduleId]);
    $schedule = $stmt->fetch();
    
    if ($schedule) {
        db()->prepare("DELETE FROM posts WHERE id=? AND status='auto_template'")->execute([$schedule['post_id']]);
        db()->prepare("DELETE FROM post_schedules WHERE id=?")->execute([$scheduleId]);
        logActivity('auto_job_delete', "Kampanye ID {$scheduleId} dihapus");
        setFlash('success', 'Kampanye berhasil dihapus.');
    } else {
        setFlash('error', 'Kampanye tidak ditemukan.');
    }
    redirect(APP_URL . '/index.php?page=auto_posts');
}

redirect(APP_URL . '/index.php?page=posts');
