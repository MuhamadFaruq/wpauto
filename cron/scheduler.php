<?php
// ================================================
// cron/scheduler.php — Scheduled Post Processor
// ================================================
// Setup VPS cron: * * * * * /usr/bin/php /path/to/WP/cron/scheduler.php >> /path/to/WP/cron/scheduler.log 2>&1
// Runs every minute, processes all due schedules
// ================================================

// ---- Critical: Allow unlimited execution time (AI generation can take minutes) ----
set_time_limit(0);
ignore_user_abort(true); // Keep running even if HTTP connection drops

// Prevent concurrent runs (file lock)
$lockFile = sys_get_temp_dir() . '/wp_scheduler.lock';
$lockFp   = fopen($lockFile, 'c');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] Another scheduler instance is already running. Exiting.\n";
    fclose($lockFp);
    exit;
}

define('SCHEDULER_RUN', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/wordpress.php';

// Prevent direct browser access (token required if called via HTTP)
if (php_sapi_name() !== 'cli') {
    $expectedToken = 'wp_cron_' . md5('auto_post_scheduler_2026');
    $givenToken    = $_GET['token'] ?? '';
    if ($givenToken !== $expectedToken) {
        http_response_code(403);
        die(json_encode(['error' => 'Forbidden. Cron access only.']));
    }
}

// Add fail_count column if not exists
try {
    db()->exec("ALTER TABLE post_schedules ADD COLUMN IF NOT EXISTS `fail_count` TINYINT NOT NULL DEFAULT 0");
} catch (Throwable $e) {}

$now = new DateTime();
echo "[" . $now->format('Y-m-d H:i:s') . "] Scheduler running...\n";

// Find all active scheduled posts where scheduled_at <= now
$stmt = db()->query(
    "SELECT ps.*, p.*, p.id as post_id, ps.id as schedule_id FROM post_schedules ps
     JOIN posts p ON ps.post_id = p.id
     WHERE ps.is_active = 1
       AND ps.scheduled_at <= NOW()
       AND p.status IN ('scheduled','draft','auto_template')
     ORDER BY ps.scheduled_at ASC
     LIMIT 50"
);

$schedules = $stmt->fetchAll();
echo "Found " . count($schedules) . " scheduled post(s) to process.\n";

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

// AI API Call Helper in CLI environment
function callAiApiInCron(array $messages, bool $jsonMode = false, string $customModel = ''): array {
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
        CURLOPT_USERPWD        => AI_BASIC_USER . ':' . AI_BASIC_PASS,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-API-Key: ' . AI_API_KEY
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => 'cURL: ' . $error];
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

// Handle rescheduling update
function handleScheduleNextRun(array $sched, int $schedId): void {
    $runCount = $sched['run_count'] + 1;
    db()->prepare("UPDATE post_schedules SET run_count=? WHERE id=?")->execute([$runCount, $schedId]);

    $schedType = $sched['schedule_type'];
    $deactivate = true;

    if ($schedType !== 'once') {
        $reachedCount = $sched['recur_count'] !== null && $runCount >= (int)$sched['recur_count'];
        $reachedUntil = $sched['recur_until'] && new DateTime() >= new DateTime($sched['recur_until']);

        if (!$reachedCount && !$reachedUntil) {
            $nextTime = computeNextRun($schedType, $sched);
            if ($nextTime) {
                db()->prepare(
                    "UPDATE post_schedules SET scheduled_at=?, is_active=1 WHERE id=?"
                )->execute([$nextTime->format('Y-m-d H:i:s'), $schedId]);
                $deactivate = false;
                echo "  Next run scheduled: " . $nextTime->format('Y-m-d H:i:s') . "\n";
            }
        }
    }

    if ($deactivate) {
        db()->prepare("UPDATE post_schedules SET is_active=0 WHERE id=?")->execute([$schedId]);
        echo "  Schedule completed/deactivated.\n";
    }
}

foreach ($schedules as $sched) {
    $postId   = $sched['post_id'];
    $schedId  = $sched['schedule_id'];
    $title    = $sched['title'];

    // ----------------------------------------------------
    // Scenario 1: AI Auto Writer Job (auto_template)
    // ----------------------------------------------------
    if ($sched['status'] === 'auto_template') {
        $topic = $sched['title'];
        $sourceMaterial = $sched['content']; // Source URL or text is stored in the content column
        
        // Parse campaign settings from excerpt JSON
        $model = AI_MODEL;
        $language = 'indonesian';
        $excerptData = json_decode($sched['excerpt'], true);
        if ($excerptData) {
            $model = $excerptData['model'] ?? AI_MODEL;
            $language = $excerptData['language'] ?? 'indonesian';
        } else {
            if (!empty($sched['excerpt'])) {
                $model = $sched['excerpt'];
            }
        }
        $langName = getLanguageName($language);

        echo "Processing AI Auto Writer Job #{$postId}: \"{$topic}\" (Model: {$model}, Lang: {$langName})\n";

        // Step 1: Create outline, suggested title, tags, and category suggest
        $systemPrompt = "Anda adalah editor artikel SEO berpengalaman yang bertugas merancang struktur konten berkualitas tinggi. Buat judul artikel yang menarik dan SEO-friendly, beserta daftar sub-judul (outline) yang mengalir secara alami.\n\nATURAN WAJIB untuk outline:\n- Setiap sub-judul harus ditulis seperti judul bab buku — singkat, jelas, dan informatif (BUKAN pertanyaan retoris)\n- DILARANG menggunakan awalan seperti 'Pendahuluan:', 'Kesimpulan:', 'Bab:', 'Bagian:', 'Part:' atau sejenisnya di awal sub-judul\n- Gunakan sub-judul yang langsung menggambarkan isi konten, misalnya 'Faktor yang Mempengaruhi Gaji Programmer' bukan 'Pendahuluan: Faktor-Faktor yang...'\n- Buat 6-8 sub-judul yang mencakup aspek pembuka, isi utama, dan penutup secara alami\n\nOutput HARUS berupa objek JSON valid dengan struktur: { \"title\": \"Judul Artikel\", \"tags\": [\"tag1\", \"tag2\"], \"category_suggestion\": \"Nama Kategori\", \"outline\": [\"Sub-judul 1\", \"Sub-judul 2\"], \"pexels_query\": \"Short English keyword for Pexels image search\" }\n\nKunci JSON HARUS tetap dalam bahasa Inggris. Nilai (judul, tag, outline) ditulis dalam " . $langName . ".";
        
        $userPrompt = "Rancang struktur artikel untuk topik: \"$topic\"";
        if (!empty($sourceMaterial)) {
            $userPrompt .= "\n\nGunakan materi referensi berikut sebagai sumber utama:\n\"$sourceMaterial\"";
        }
        
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ];

        $outlineRes = callAiApiInCron($messages, true, $model);
        if (!$outlineRes['success']) {
            $failCount = (int)$sched['fail_count'] + 1;
            echo "  ERROR: AI Outline generation failed (attempt #{$failCount}): " . $outlineRes['error'] . "\n";
            if ($failCount >= 3) {
                // 3 consecutive failures — deactivate to stop wasting resources
                db()->prepare("UPDATE post_schedules SET is_active=0, fail_count=? WHERE id=?")->execute([$failCount, $schedId]);
                db()->prepare("UPDATE posts SET status='failed', error_message=? WHERE id=?")->execute(['Gagal generate setelah 3 percobaan: ' . $outlineRes['error'], $postId]);
                echo "  Schedule deactivated after 3 failed attempts.\n";
            } else {
                // Retry in 30 minutes
                $retryTime = (new DateTime())->modify('+30 minutes')->format('Y-m-d H:i:s');
                db()->prepare("UPDATE post_schedules SET fail_count=?, scheduled_at=? WHERE id=?")->execute([$failCount, $retryTime, $schedId]);
                echo "  Will retry at {$retryTime} (attempt {$failCount}/3).\n";
            }
            continue;
        }

        $cleanText = cleanJsonString($outlineRes['text']);
        $json = json_decode($cleanText, true);
        if (!$json || !isset($json['title']) || !isset($json['outline'])) {
            $failCount = (int)$sched['fail_count'] + 1;
            echo "  ERROR: AI Output JSON structure was invalid (attempt #{$failCount}).\n";
            if ($failCount >= 3) {
                db()->prepare("UPDATE post_schedules SET is_active=0, fail_count=? WHERE id=?")->execute([$failCount, $schedId]);
                db()->prepare("UPDATE posts SET status='failed', error_message='Format JSON outline tidak valid setelah 3 percobaan' WHERE id=?")->execute([$postId]);
                echo "  Schedule deactivated after 3 failed attempts.\n";
            } else {
                $retryTime = (new DateTime())->modify('+30 minutes')->format('Y-m-d H:i:s');
                db()->prepare("UPDATE post_schedules SET fail_count=?, scheduled_at=? WHERE id=?")->execute([$failCount, $retryTime, $schedId]);
                echo "  Will retry at {$retryTime} (attempt {$failCount}/3).\n";
            }
            continue;
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

        echo "  Generating " . count($outline) . " outline section(s) via AI...\n";
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
            
            $secRes = callAiApiInCron($secMsg, false, $model);
            $cleanHeading = preg_replace('/^[^:]{1,40}:\s*/u', '', $sectionName);
            if ($secRes['success']) {
                $fullContent .= "<h2>{$cleanHeading}</h2>\n" . cleanArticleHtml($secRes['text']);
            } else {
                $fullContent .= "<h2>{$cleanHeading}</h2>\n<p><em>(Gagal memuat bagian ini otomatis)</em></p>";
            }
        }

        // Final clean pass on the whole assembled article
        $fullContent = cleanArticleHtml($fullContent);

        // Step 3: Connect API and publish if site is selected, otherwise save locally
        $siteId = $sched['site_id'] ? (int)$sched['site_id'] : 0;
        $api = $siteId ? getWpApi($siteId) : null;
        
        if ($api) {
            // Fetch categories to match suggested
            $wpCategoryId = null;
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
            $mapStmt = db()->prepare("SELECT wp_author_id FROM wp_author_map WHERE user_id=? AND site_id=?");
            $mapStmt->execute([$sched['author_id'], $siteId]);
            $mapping = $mapStmt->fetch();
            $wpAuthorId = $mapping ? $mapping['wp_author_id'] : null;

            // Resolve tags
            $tagIds = [];
            foreach ($tags as $tagName) {
                $id = $api->ensureTag($tagName);
                if ($id) $tagIds[] = $id;
            }

            // Auto search and upload featured image from Pexels
            $featuredImageWpId = null;
            $featuredImageUrl  = null;
            if (!empty($pexelsQuery)) {
                echo "  Searching Pexels for: \"$pexelsQuery\"...\n";
                $imageUrl = getPexelsImage($pexelsQuery);
                if ($imageUrl) {
                    echo "  Downloading image from Pexels...\n";
                    $tempImg = downloadImageToTemp($imageUrl);
                    if ($tempImg) {
                        echo "  Uploading image to WordPress Media Library...\n";
                        $uploadRes = $api->uploadMedia($tempImg['path'], $tempImg['name'], $tempImg['mime']);
                        if ($uploadRes['success']) {
                            $featuredImageWpId = $uploadRes['media_id'];
                            $featuredImageUrl  = $imageUrl;
                            echo "  Featured Image uploaded: WP ID " . $featuredImageWpId . "\n";
                        } else {
                            echo "  ERROR: Image upload failed: " . $uploadRes['error'] . "\n";
                        }
                        @unlink($tempImg['path']);
                    }
                } else {
                    echo "  Pexels image not found for query.\n";
                }
            }

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
                
                // Save the newly generated article in local DB
                $ins = db()->prepare(
                    "INSERT INTO posts (title, content, excerpt, slug, status, author_id, site_id, wp_post_id, wp_post_url, categories, tags, featured_image_url, featured_image_wp_id, created_at, updated_at, published_at)
                     VALUES (?, ?, ?, ?, 'published', ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())"
                );
                $ins->execute([
                    $generatedTitle, $fullContent, $wpData['excerpt'], 
                    strtolower(trim(preg_replace('/[^a-zA-Z0-9\-]+/', '-', $generatedTitle), '-')),
                    $sched['author_id'], $siteId, $wpPostId, $wpPostUrl,
                    json_encode($wpCategoryId ? [$wpCategoryId] : []), json_encode($tags),
                    $featuredImageUrl, $featuredImageWpId
                ]);
                
                // Reset fail_count on success
                db()->prepare("UPDATE post_schedules SET fail_count=0 WHERE id=?")->execute([$schedId]);
                echo "  SUCCESS: Generated and published \"{$generatedTitle}\" (WP ID: {$wpPostId})\n";
                logActivity('post_publish', "Artikel otomatis diterbitkan via AI: {$generatedTitle}", $sched['author_id']);
            } else {
                $failCount = (int)$sched['fail_count'] + 1;
                $errMsg = $result['error'] ?? 'Unknown error';
                echo "  ERROR: Publishing to WordPress failed (attempt #{$failCount}): {$errMsg}\n";
                if ($failCount >= 3) {
                    db()->prepare("UPDATE post_schedules SET is_active=0, fail_count=? WHERE id=?")->execute([$failCount, $schedId]);
                    db()->prepare("UPDATE posts SET status='failed', error_message=? WHERE id=?")->execute(['Gagal publish ke WordPress setelah 3 percobaan: ' . $errMsg, $postId]);
                    echo "  Schedule deactivated after 3 failed publish attempts.\n";
                } else {
                    $retryTime = (new DateTime())->modify('+30 minutes')->format('Y-m-d H:i:s');
                    db()->prepare("UPDATE post_schedules SET fail_count=?, scheduled_at=? WHERE id=?")->execute([$failCount, $retryTime, $schedId]);
                    echo "  Will retry publish at {$retryTime} (attempt {$failCount}/3).\n";
                }
            }
        } else {
            // Local saving only (if no site selected)
            $excerpt = substr(strip_tags($fullContent), 0, 240) . '...';
            
            // Auto search featured image from Pexels
            $featuredImageUrl = null;
            if (!empty($pexelsQuery)) {
                echo "  Searching Pexels for: \"$pexelsQuery\"...\n";
                $imageUrl = getPexelsImage($pexelsQuery);
                if ($imageUrl) {
                    $featuredImageUrl = $imageUrl;
                    echo "  Featured Image resolved from Pexels: " . $featuredImageUrl . "\n";
                }
            }

            $ins = db()->prepare(
                "INSERT INTO posts (title, content, excerpt, slug, status, author_id, site_id, wp_post_id, wp_post_url, categories, tags, featured_image_url, featured_image_wp_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'draft', ?, NULL, NULL, NULL, ?, ?, ?, NULL, NOW(), NOW())"
            );
            $ins->execute([
                $generatedTitle, $fullContent, $excerpt, 
                strtolower(trim(preg_replace('/[^a-zA-Z0-9\-]+/', '-', $generatedTitle), '-')),
                $sched['author_id'],
                json_encode([]), json_encode($tags),
                $featuredImageUrl
            ]);
            
            echo "  SUCCESS: Generated and saved draft locally: \"{$generatedTitle}\"\n";
            logActivity('post_create', "Draf artikel otomatis dibuat secara lokal: {$generatedTitle}", $sched['author_id']);
        }

        handleScheduleNextRun($sched, $schedId);
        continue;
    }

    // ----------------------------------------------------
    // Scenario 2: Standard Post (scheduled / draft)
    // ----------------------------------------------------
    echo "Processing static post #{$postId}: {$title}\n";

    $post = [
        'id'                   => $postId,
        'title'                => $sched['title'],
        'content'              => $sched['content'],
        'excerpt'              => $sched['excerpt'],
        'slug'                 => $sched['slug'],
        'site_id'              => $sched['site_id'],
        'author_id'            => $sched['author_id'],
        'categories'           => $sched['categories'],
        'tags'                 => $sched['tags'],
        'featured_image_url'   => $sched['featured_image_url'],
        'featured_image_wp_id' => $sched['featured_image_wp_id'],
        'wp_post_id'           => $sched['wp_post_id'],
    ];

    $mapStmt = db()->prepare("SELECT wp_author_id FROM wp_author_map WHERE user_id=? AND site_id=?");
    $mapStmt->execute([$post['author_id'], $post['site_id']]);
    $mapping = $mapStmt->fetch();
    $wpAuthorId = $mapping ? $mapping['wp_author_id'] : null;

    $api = getWpApi((int)$post['site_id']);
    if (!$api) {
        echo "  ERROR: Site not found or inactive.\n";
        db()->prepare("UPDATE posts SET status='failed', error_message='Site tidak ditemukan atau nonaktif' WHERE id=?")->execute([$postId]);
        db()->prepare("UPDATE post_schedules SET is_active=0 WHERE id=?")->execute([$schedId]);
        continue;
    }

    $tagIds   = [];
    $tagNames = json_decode($post['tags'] ?? '[]', true) ?: [];
    foreach ($tagNames as $tagName) {
        $id = $api->ensureTag($tagName);
        if ($id) $tagIds[] = $id;
    }

    $wpData = [
        'title'      => $post['title'],
        'content'    => $post['content'],
        'excerpt'    => $post['excerpt'] ?? '',
        'status'     => 'publish',
        'categories' => json_decode($post['categories'] ?? '[]', true) ?: [],
        'tags'       => $tagIds,
    ];
    if ($wpAuthorId) $wpData['author'] = (int)$wpAuthorId;

    // Handle featured image: upload if not already uploaded
    $featuredImageWpId = $post['featured_image_wp_id'];
    if (!$featuredImageWpId && !empty($post['featured_image_url'])) {
        echo "  Found featured image URL but no WordPress Media ID. Uploading now...\n";
        $tempImg = downloadImageToTemp($post['featured_image_url']);
        if ($tempImg) {
            $uploadRes = $api->uploadMedia($tempImg['path'], $tempImg['name'], $tempImg['mime']);
            if ($uploadRes['success']) {
                $featuredImageWpId = $uploadRes['media_id'];
                db()->prepare("UPDATE posts SET featured_image_wp_id=? WHERE id=?")->execute([$featuredImageWpId, $postId]);
                echo "  Featured Image uploaded: WP ID " . $featuredImageWpId . "\n";
            } else {
                echo "  ERROR: Featured image upload failed: " . ($uploadRes['error'] ?? 'Unknown error') . "\n";
            }
            @unlink($tempImg['path']);
        }
    }

    if ($featuredImageWpId) $wpData['featured_media'] = (int)$featuredImageWpId;
    if (!empty($post['slug'])) $wpData['slug'] = $post['slug'];

    if ($post['wp_post_id']) {
        $result = $api->updatePost((int)$post['wp_post_id'], $wpData);
    } else {
        $result = $api->publishPost($wpData);
    }

    if ($result['success']) {
        $wpPostId  = $result['data']['id'];
        $wpPostUrl = $result['data']['link'];

        db()->prepare(
            "UPDATE posts SET status='published', wp_post_id=?, wp_post_url=?, published_at=NOW(), error_message=NULL WHERE id=?"
        )->execute([$wpPostId, $wpPostUrl, $postId]);

        echo "  SUCCESS: Published as WP post #{$wpPostId}\n";
        logActivity('post_publish', "Artikel dijadwalkan diterbitkan: {$title}", $post['author_id']);
        
        handleScheduleNextRun($sched, $schedId);
    } else {
        $error = $result['error'] ?? 'Unknown error';
        db()->prepare("UPDATE posts SET status='failed', error_message=? WHERE id=?")->execute([$error, $postId]);
        db()->prepare("UPDATE post_schedules SET is_active=0 WHERE id=?")->execute([$schedId]);
        echo "  FAILED: {$error}\n";
        logActivity('post_fail', "Artikel gagal diterbitkan: {$title} — {$error}", $post['author_id']);
    }
}

echo "Scheduler done.\n";

// Release file lock
flock($lockFp, LOCK_UN);
fclose($lockFp);

// ---- Compute next run time ----
function computeNextRun(string $type, array $sched): ?DateTime {
    $base = new DateTime($sched['scheduled_at']);
    $now  = new DateTime();

    switch ($type) {
        case 'hourly':
            $next = clone $base;
            while ($next <= $now) $next->modify('+1 hour');
            return $next;

        case 'daily':
            $next = clone $base;
            while ($next <= $now) $next->modify('+1 day');
            return $next;

        case 'weekly':
            $next = clone $base;
            while ($next <= $now) $next->modify('+1 week');
            return $next;

        case 'monthly':
            $next = clone $base;
            while ($next <= $now) $next->modify('+1 month');
            return $next;

        case 'custom':
            $hours = (int)($sched['interval_hours'] ?? 24);
            if ($hours < 1) $hours = 24;
            $next = clone $base;
            while ($next <= $now) $next->modify("+{$hours} hours");
            return $next;

        default:
            return null;
    }
}
