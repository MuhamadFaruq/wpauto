<?php
// ================================================
// actions/ai_action.php — AI Writer API calls
// ================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../api/wordpress.php';
startSession();
requireLogin();

// Prevent script execution timeout for long-running AI generations
set_time_limit(0);

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Helper to parse SSE stream formats if returned
function parseStreamResponse(string $response): string {
    $response = trim($response);
    if (strpos($response, '{') === 0 || strpos($response, '[') === 0) {
        return $response;
    }
    
    $lines = explode("\n", $response);
    $fullContent = '';
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        if (strpos($line, 'data: ') === 0) {
            $dataStr = trim(substr($line, 6));
            if ($dataStr === '[DONE]') continue;
            
            $data = json_decode($dataStr, true);
            if ($data) {
                $deltaContent = $data['choices'][0]['delta']['content'] ?? $data['choices'][0]['message']['content'] ?? '';
                $fullContent .= $deltaContent;
            }
        }
    }
    return $fullContent;
}

// Helper to make call to AI REST API
function callAiApi(array $messages, bool $jsonMode = false, string $customModel = ''): array {
    $url = rtrim(AI_API_URL, '/') . '/chat/completions';
    
    $model = !empty($customModel) ? $customModel : AI_MODEL;
    
    $payload = [
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => 0.7,
    ];
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERPWD        => AI_BASIC_USER . ':' . AI_BASIC_PASS, // Bypass restricted gate
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-API-Key: ' . AI_API_KEY // Use X-API-Key to avoid Authorization conflicts
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => 'cURL error: ' . $error];
    }
    
    if ($httpCode >= 200 && $httpCode < 300) {
        $response = trim($response);
        if (strpos($response, 'data: [DONE]') !== false) {
            $response = trim(str_replace('data: [DONE]', '', $response));
        }
        $data = json_decode($response, true);
        if ($data && isset($data['choices'][0]['message']['content'])) {
            return ['success' => true, 'text' => trim($data['choices'][0]['message']['content'])];
        }
        
        // Parse SSE stream format
        $parsed = parseStreamResponse($response);
        if (!empty($parsed)) {
            return ['success' => true, 'text' => trim($parsed)];
        }
        
        return ['success' => false, 'error' => 'Format output AI tidak dapat diurai (JSON/SSE kosong).'];
    }
    
    $data = json_decode($response, true);
    $errMsg = $data['error']['message'] ?? 'HTTP ' . $httpCode;
    return ['success' => false, 'error' => $errMsg];
}

// 0. AJAX: Get Available Models from Router
if ($action === 'get_models') {
    $url = rtrim(AI_API_URL, '/') . '/models';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERPWD        => AI_BASIC_USER . ':' . AI_BASIC_PASS,
        CURLOPT_HTTPHEADER     => [
            'X-API-Key: ' . AI_API_KEY
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        $models = [];
        if (isset($data['data']) && is_array($data['data'])) {
            foreach ($data['data'] as $m) {
                if (isset($m['id'])) {
                    $models[] = $m['id'];
                }
            }
        }
        // Filter or sort models if needed. Sort alphabetically for clean display.
        sort($models);
        echo json_encode(['success' => true, 'models' => $models]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Gagal mengambil model dari AI Router (HTTP ' . $httpCode . ')']);
    }
    exit;
}

// 1. AJAX: Generate Outline
if ($action === 'generate_outline') {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    $topic = trim($data['topic'] ?? '');
    $model = trim($data['model'] ?? '');
    $language = trim($data['language'] ?? 'indonesian');
    $langName = getLanguageName($language);

    if (empty($topic)) {
        echo json_encode(['success' => false, 'error' => 'Topik artikel kosong.']);
        exit;
    }

    $sourceMaterial = trim($data['source_material'] ?? '');

    $systemPrompt = getOutlineSystemPrompt($langName);
    
    $userPrompt = "Rancang struktur artikel untuk topik: \"$topic\"";
    if (!empty($sourceMaterial)) {
        $userPrompt .= "\n\nGunakan materi referensi berikut sebagai sumber utama:\n\"$sourceMaterial\"";
    }
    
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt]
    ];

    $res = callAiApi($messages, true, $model);

    if ($res['success']) {
        $cleanText = cleanJsonString($res['text']);
        $json = json_decode($cleanText, true);
        if ($json && isset($json['title']) && isset($json['outline'])) {
            echo json_encode(array_merge(['success' => true], $json));
        } else {
            echo json_encode([
                'success' => false,
                'error'   => 'Format output AI tidak sesuai JSON. Output asli: ' . substr($res['text'], 0, 200)
            ]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => $res['error']]);
    }
    exit;
}

// 2. AJAX: Generate Section Content
if ($action === 'generate_section') {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    
    $title       = trim($data['title'] ?? '');
    $sectionName = trim($data['section'] ?? '');
    $topic       = trim($data['topic'] ?? '');
    $model       = trim($data['model'] ?? '');
    $language    = trim($data['language'] ?? 'indonesian');
    $langName    = getLanguageName($language);

    if (empty($title) || empty($sectionName)) {
        echo json_encode(['success' => false, 'error' => 'Judul atau sub-judul kosong.']);
        exit;
    }

    $systemPrompt = getWriterSystemPrompt($langName);
    
    $sourceMaterial = trim($data['source_material'] ?? '');
    $userPrompt = "Tulis bagian artikel dengan sub-judul: \"$sectionName\"\nArtikel berjudul: \"$title\"\nTopik utama: \"$topic\"\n\nIngat: langsung mulai paragraf pertama tanpa menulis ulang sub-judul tersebut. JANGAN gunakan <strong> atau <b>.";
    if (!empty($sourceMaterial)) {
        $userPrompt .= "\n\nGunakan materi referensi berikut sebagai bahan penulisan:\n\"$sourceMaterial\"";
    }

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt]
    ];

    $res = callAiApi($messages, false, $model);

    if ($res['success']) {
        echo json_encode(['success' => true, 'content' => cleanArticleHtml($res['text'])]);
    } else {
        echo json_encode(['success' => false, 'error' => $res['error']]);
    }
    exit;
}

// 3. AJAX: Save Draft to Session for Editor
if ($action === 'save_session') {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    $title      = trim($data['title'] ?? '');
    $content    = trim($data['content'] ?? '');
    $tags       = $data['tags'] ?? [];
    $catSuggest = trim($data['category_suggestion'] ?? '');
    $siteId     = (int)($data['site_id'] ?? 0);

    if (empty($title) || empty($content)) {
        echo json_encode(['success' => false, 'error' => 'Konten draf kosong.']);
        exit;
    }

    $_SESSION['ai_draft'] = [
        'title'               => $title,
        'content'             => $content,
        'tags'                => $tags,
        'category_suggestion' => $catSuggest,
        'site_id'             => $siteId
    ];

    echo json_encode(['success' => true]);
    exit;
}

// 4. AJAX: Create Recurring Auto Writer Job
if ($action === 'create_auto_job') {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    $topic          = trim($data['topic'] ?? '');
    $sourceMaterial = trim($data['source_material'] ?? '');
    $siteId         = (int)($data['site_id'] ?? 0);
    $schedType      = trim($data['schedule_type'] ?? 'daily');
    $scheduledAt    = trim($data['scheduled_at'] ?? '');
    $intervalHrs    = (int)($data['interval_hours'] ?? 0);
    $dayOfWeek      = isset($data['day_of_week']) ? (int)$data['day_of_week'] : null;
    $dayOfMonth     = isset($data['day_of_month']) ? (int)$data['day_of_month'] : null;
    $recurUntil     = !empty($data['recur_until']) ? $data['recur_until'] : null;
    $recurCount     = isset($data['recur_count']) && $data['recur_count'] !== '' ? (int)$data['recur_count'] : null;
    $model          = trim($data['model'] ?? '');
    $language       = trim($data['language'] ?? 'indonesian');

    if (empty($topic)) {
        echo json_encode(['success' => false, 'error' => 'Topik artikel kosong.']);
        exit;
    }
    if (empty($scheduledAt)) {
        echo json_encode(['success' => false, 'error' => 'Waktu mulai penjadwalan wajib diisi.']);
        exit;
    }

    $projectId = isset($data['project_id']) && $data['project_id'] !== '' ? (int)$data['project_id'] : null;

    $db = db();

    // Dynamically ensure 'auto_template' is allowed in the ENUM status column
    try {
        $db->exec("ALTER TABLE posts MODIFY COLUMN status ENUM('draft','pending_publish','published','failed','scheduled','auto_template') NOT NULL DEFAULT 'draft'");
    } catch (Exception $e) {
        // Safe to ignore if already altered
    }

    $authorId = currentUser()['id'];

    // Store campaign settings in posts.excerpt as JSON
    $campaignSettings = json_encode([
        'model'    => $model ?: AI_MODEL,
        'language' => $language
    ]);

    // Insert auto job template into posts
    $stmt = $db->prepare(
        "INSERT INTO posts (title, content, excerpt, slug, status, author_id, site_id, project_id, ai_generated, created_at, updated_at)
         VALUES (?, ?, ?, 'ai-auto-template', 'auto_template', ?, ?, ?, 1, NOW(), NOW())"
    );
    $stmt->execute([$topic, $sourceMaterial, $campaignSettings, $authorId, $siteId ?: null, $projectId]);
    $postId = (int)$db->lastInsertId();

    // Create schedule in post_schedules
    $schedStmt = $db->prepare(
        "INSERT INTO post_schedules (post_id, schedule_type, scheduled_at, interval_hours, day_of_week, day_of_month, recur_until, recur_count, project_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $schedStmt->execute([$postId, $schedType, $scheduledAt, $intervalHrs ?: null, $dayOfWeek, $dayOfMonth, $recurUntil, $recurCount, $projectId]);

    logActivity('auto_job_create', "Job Auto Writer dijadwalkan: \"{$topic}\" ({$schedType})", $authorId);

    echo json_encode(['success' => true]);
    exit;
}

// 5. AJAX: Complete AI Generation + Pexels Featured Image + WP Publish (Used for sequential bulk generator)
if ($action === 'write_full_post_auto') {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    $topic          = trim($data['topic'] ?? '');
    $sourceMaterial = trim($data['source_material'] ?? '');
    $siteId         = (int)($data['site_id'] ?? 0);
    $model          = trim($data['model'] ?? '');
    $language       = trim($data['language'] ?? 'indonesian');
    $langName       = getLanguageName($language);
    $projectId      = isset($data['project_id']) && $data['project_id'] !== '' ? (int)$data['project_id'] : null;

    if (empty($topic)) {
        echo json_encode(['success' => false, 'error' => 'Topik artikel kosong.']);
        exit;
    }

    // Step 1: Generate title, tags, category suggest, and outline
    $systemPrompt = getOutlineSystemPrompt($langName);
    
    $userPrompt = "Rancang struktur artikel untuk topik: \"$topic\"";
    if (!empty($sourceMaterial)) {
        $userPrompt .= "\n\nGunakan materi referensi berikut sebagai sumber utama:\n\"$sourceMaterial\"";
    }
    
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt]
    ];

    $res = callAiApi($messages, true, $model);
    if (!$res['success']) {
        echo json_encode(['success' => false, 'error' => 'Gagal membuat outline: ' . $res['error']]);
        exit;
    }

    $cleanText = cleanJsonString($res['text']);
    $json = json_decode($cleanText, true);
    if (!$json || !isset($json['title']) || !isset($json['outline'])) {
        echo json_encode(['success' => false, 'error' => 'Struktur JSON outline tidak valid.']);
        exit;
    }

    $generatedTitle = $json['title'];
    $outline        = $json['outline'];
    $summary        = trim($json['summary'] ?? '');
    $tags           = $json['tags'] ?? [];
    $catSuggest     = $json['category_suggestion'] ?? '';
    $pexelsQuery    = $json['pexels_query'] ?? '';

    // Step 2: Build intro paragraph + generate content per section
    $fullContent = '';

    // Prepend summary as opening paragraph if available
    if (!empty($summary)) {
        $fullContent .= '<p class="article-intro">' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '</p>' . "\n";
    }

    foreach ($outline as $sectionName) {
        $secSystem = getWriterSystemPrompt($langName);
        $secUser = "Tulis bagian artikel dengan sub-judul: \"$sectionName\"\nArtikel berjudul: \"$generatedTitle\"\nTopik utama: \"$topic\"\n\nIngat: langsung mulai paragraf pertama tanpa menulis ulang sub-judul tersebut. JANGAN gunakan <strong> atau <b>.";
        if (!empty($sourceMaterial)) {
            $secUser .= "\n\nGunakan materi referensi berikut sebagai bahan penulisan:\n\"$sourceMaterial\"";
        }
        
        $secMsg = [
            ['role' => 'system', 'content' => $secSystem],
            ['role' => 'user', 'content' => $secUser]
        ];
        
        $secRes = callAiApi($secMsg, false, $model);
        $cleanHeading = preg_replace('/^[^:]{1,40}:\s*/u', '', $sectionName);
        if ($secRes['success']) {
            $fullContent .= "<h2>{$cleanHeading}</h2>\n" . cleanArticleHtml($secRes['text']);
        } else {
            $fullContent .= "<h2>{$cleanHeading}</h2>\n<p><em>(Gagal memuat bagian ini otomatis)</em></p>";
        }
    }

    // Final clean pass on the whole assembled article
    $fullContent = cleanArticleHtml($fullContent);

    // Step 3: Handle site upload and publish
    $api = getWpApi($siteId);
    $wpCategoryId = null;
    $wpAuthorId = null;
    $tagIds = [];
    $featuredImageWpId = null;
    $featuredImageUrl  = null;

    if ($api) {
        // Fetch categories to match suggested
        if (!empty($catSuggest)) {
            $cats = $api->getCategories();
            $normalizedSuggest = strtolower(trim($catSuggest));
            foreach ($cats as $c) {
                $optText = strtolower(trim($c['name']));
                if (strpos($optText, $normalizedSuggest) !== false || strpos($normalizedSuggest, $optText) !== false) {
                    $wpCategoryId = $c['id'];
                    break;
                }
            }
        }

        // Fetch WP Author Mapping
        $authorId = currentUser()['id'];
        $mapStmt = db()->prepare("SELECT wp_author_id FROM wp_author_map WHERE user_id=? AND site_id=?");
        $mapStmt->execute([$authorId, $siteId]);
        $mapping = $mapStmt->fetch();
        $wpAuthorId = $mapping ? $mapping['wp_author_id'] : null;

        // Resolve tags
        foreach ($tags as $tagName) {
            $id = $api->ensureTag($tagName);
            if ($id) $tagIds[] = $id;
        }

        // Auto search and upload featured image from Pexels
        if (!empty($pexelsQuery)) {
            $imageUrl = getPexelsImage($pexelsQuery);
            if ($imageUrl) {
                $tempImg = downloadImageToTemp($imageUrl);
                if ($tempImg) {
                    $uploadRes = $api->uploadMedia($tempImg['path'], $tempImg['name'], $tempImg['mime']);
                    if ($uploadRes['success']) {
                        $featuredImageWpId = $uploadRes['media_id'];
                        $featuredImageUrl  = $imageUrl;
                    }
                    @unlink($tempImg['path']);
                }
            }
        }

        // Publish to WP
        $wpData = [
            'title'      => $generatedTitle,
            'content'    => $fullContent,
            'excerpt'    => substr(strip_tags($fullContent), 0, 240) . '...',
            'status'     => 'publish',
            'categories' => $wpCategoryId ? [$wpCategoryId] : [],
            'tags'       => $tagIds,
        ];
        if ($wpAuthorId) $wpData['author'] = (int)$wpAuthorId;
        if ($featuredImageWpId) $wpData['featured_media'] = (int)$featuredImageWpId;

        $result = $api->publishPost($wpData);
        if ($result['success']) {
            $wpPostId  = $result['data']['id'];
            $wpPostUrl = $result['data']['link'];
            
            // Save locally
            $ins = db()->prepare(
                "INSERT INTO posts (title, content, excerpt, slug, status, author_id, site_id, wp_post_id, wp_post_url, categories, tags, featured_image_url, featured_image_wp_id, created_at, updated_at, published_at)
                 VALUES (?, ?, ?, ?, 'published', ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())"
            );
            $ins->execute([
                $generatedTitle, $fullContent, $wpData['excerpt'], 
                strtolower(trim(preg_replace('/[^a-zA-Z0-9\-]+/', '-', $generatedTitle), '-')),
                $authorId, $siteId, $wpPostId, $wpPostUrl,
                json_encode($wpCategoryId ? [$wpCategoryId] : []), json_encode($tags),
                $featuredImageUrl, $featuredImageWpId
            ]);

            logActivity('post_publish', "Artikel otomatis diterbitkan via AI: {$generatedTitle}", $authorId);
            echo json_encode(['success' => true, 'title' => $generatedTitle, 'link' => $wpPostUrl]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Gagal publish ke WordPress: ' . ($result['error'] ?? 'Unknown error')]);
        }
    } else {
        // Local saving only (if no site selected)
        $authorId = currentUser()['id'];
        $wpData = [
            'excerpt' => substr(strip_tags($fullContent), 0, 240) . '...',
        ];

        if (!empty($pexelsQuery)) {
            $imageUrl = getPexelsImage($pexelsQuery);
            if ($imageUrl) {
                $featuredImageUrl = $imageUrl;
            }
        }

        $ins = db()->prepare(
            "INSERT INTO posts (title, content, excerpt, slug, status, author_id, site_id, wp_post_id, wp_post_url, categories, tags, featured_image_url, featured_image_wp_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'draft', ?, NULL, NULL, NULL, ?, ?, ?, NULL, NOW(), NOW())"
        );
        $ins->execute([
            $generatedTitle, $fullContent, $wpData['excerpt'], 
            strtolower(trim(preg_replace('/[^a-zA-Z0-9\-]+/', '-', $generatedTitle), '-')),
            $authorId,
            json_encode([]), json_encode($tags),
            $featuredImageUrl
        ]);

        logActivity('post_create', "Draf artikel otomatis dibuat secara lokal: {$generatedTitle}", $authorId);
        echo json_encode(['success' => true, 'title' => $generatedTitle, 'link' => 'index.php?page=posts']);
    }
    exit;
}

// 6. AJAX: Test LLM Connection using arbitrary inputs
if ($action === 'test_llm') {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    $url    = rtrim(trim($data['ai_api_url'] ?? ''), '/');
    $key    = trim($data['ai_api_key'] ?? '');
    $model  = trim($data['ai_model'] ?? '');
    $user   = trim($data['ai_basic_user'] ?? '');
    $pass   = trim($data['ai_basic_pass'] ?? '');

    if (empty($url) || empty($key)) {
        echo json_encode(['success' => false, 'error' => 'API URL dan API Key wajib diisi untuk pengujian.']);
        exit;
    }

    $ch = curl_init($url . '/chat/completions');
    $payload = [
        'model' => $model ?: 'oc/deepseek-v4-flash-free',
        'messages' => [
            ['role' => 'user', 'content' => 'Respond with the word "Connected" and nothing else.']
        ],
        'max_tokens' => 5
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERPWD        => $user . ':' . $pass,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-API-Key: ' . $key
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        echo json_encode(['success' => false, 'error' => 'cURL Error: ' . $error]);
        exit;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        $resData = json_decode($response, true);
        if ($resData && isset($resData['choices'][0]['message']['content'])) {
            echo json_encode([
                'success' => true,
                'model'   => $resData['model'] ?? $model
            ]);
            exit;
        }
        
        $parsed = parseStreamResponse($response);
        if (!empty($parsed)) {
            echo json_encode([
                'success' => true,
                'model'   => $model
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'error' => 'Respon API kosong atau tidak valid: ' . substr($response, 0, 200)]);
    } else {
        $resData = json_decode($response, true);
        $errMsg = $resData['error']['message'] ?? 'HTTP ' . $httpCode;
        echo json_encode(['success' => false, 'error' => $errMsg]);
    }
    exit;
}

// ============================================================
// Action: Create AI Project
// ============================================================
if ($action === 'create_project') {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    $name     = trim($data['name'] ?? '');
    $desc     = trim($data['description'] ?? '');
    $siteId   = isset($data['site_id']) && $data['site_id'] !== '' ? (int)$data['site_id'] : null;
    $language = trim($data['language'] ?? 'indonesian');
    $model    = trim($data['model'] ?? '');

    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'Nama proyek wajib diisi.']);
        exit;
    }

    $authorId = currentUser()['id'];
    $db = db();

    // Ensure table exists
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `ai_projects` (
            `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name`        VARCHAR(255) NOT NULL,
            `description` TEXT DEFAULT NULL,
            `site_id`     INT UNSIGNED DEFAULT NULL,
            `model`       VARCHAR(200) DEFAULT NULL,
            `language`    VARCHAR(50) NOT NULL DEFAULT 'indonesian',
            `author_id`   INT UNSIGNED NOT NULL,
            `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}

    $stmt = $db->prepare(
        "INSERT INTO ai_projects (name, description, site_id, model, language, author_id) VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$name, $desc ?: null, $siteId, $model ?: null, $language, $authorId]);
    $projectId = (int)$db->lastInsertId();

    logActivity('project_create', "Proyek AI dibuat: \"{$name}\"", $authorId);
    echo json_encode(['success' => true, 'project_id' => $projectId]);
    exit;
}

// ============================================================
// Action: Delete AI Project
// ============================================================
if ($action === 'delete_project') {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    $projectId = (int)($data['project_id'] ?? 0);
    if (!$projectId) {
        echo json_encode(['success' => false, 'error' => 'ID proyek tidak valid.']);
        exit;
    }

    $authorId = currentUser()['id'];
    $db = db();

    // Only project owner or admin can delete
    $proj = $db->prepare("SELECT author_id FROM ai_projects WHERE id=?");
    $proj->execute([$projectId]);
    $row = $proj->fetch();

    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Proyek tidak ditemukan.']);
        exit;
    }
    if ($row['author_id'] != $authorId && !isAdmin()) {
        echo json_encode(['success' => false, 'error' => 'Tidak memiliki izin untuk menghapus proyek ini.']);
        exit;
    }

    // Unlink posts and schedules (don't delete actual posts, just remove project reference)
    $db->prepare("UPDATE posts SET project_id=NULL WHERE project_id=?")->execute([$projectId]);
    $db->prepare("UPDATE post_schedules SET project_id=NULL WHERE project_id=?")->execute([$projectId]);
    $db->prepare("DELETE FROM ai_projects WHERE id=?")->execute([$projectId]);

    logActivity('project_delete', "Proyek AI dihapus ID #{$projectId}", $authorId);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Aksi tidak valid.']);
exit;


// ============================================================
// END OF FILE — action handlers below are unreachable stubs
// kept here for reference only
// ============================================================
