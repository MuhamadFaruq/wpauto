<?php
// ================================================
// config.php — Database & app configuration
// ================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'wp_dashboard');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Encryption key for storing WP app passwords (change to random 32-char string)
define('ENCRYPT_KEY', 'ch4ng3_th1s_t0_a_r4nd0m_32ch4r!!');

// App settings
define('APP_NAME', 'WP Dashboard');
define('APP_URL', 'http://localhost/WP');
define('UPLOAD_MAX_SIZE', 10 * 1024 * 1024); // 10MB
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_URL', APP_URL . '/uploads/');

// Session
define('SESSION_LIFETIME', 3600 * 8); // 8 hours

// ------------------------------------------------
// Database connection (PDO singleton)
// ------------------------------------------------
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

// ------------------------------------------------
// Encryption helpers for app passwords
// ------------------------------------------------
function encryptPassword(string $plain): string {
    $key   = substr(hash('sha256', ENCRYPT_KEY, true), 0, 32);
    $iv    = random_bytes(16);
    $encrypted = openssl_encrypt($plain, 'AES-256-CBC', $key, 0, $iv);
    return base64_encode($iv . $encrypted);
}

function decryptPassword(string $encrypted): string {
    $key  = substr(hash('sha256', ENCRYPT_KEY, true), 0, 32);
    $data = base64_decode($encrypted);
    $iv   = substr($data, 0, 16);
    $enc  = substr($data, 16);
    return openssl_decrypt($enc, 'AES-256-CBC', $key, 0, $iv);
}

// ------------------------------------------------
// Activity logger
// ------------------------------------------------
function logActivity(string $action, string $details = '', ?int $userId = null): void {
    try {
        $uid = $userId ?? ($_SESSION['user_id'] ?? null);
        $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
        db()->prepare(
            "INSERT INTO activity_log (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)"
        )->execute([$uid, $action, $details, $ip]);
    } catch (Throwable $e) {
        // Non-fatal
    }
}

// ------------------------------------------------
// Flash messages
// ------------------------------------------------
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// ------------------------------------------------
// Helpers
// ------------------------------------------------
function sanitize(string $s): string {
    return htmlspecialchars(trim($s), ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never {
    header("Location: $url");
    exit;
}

function isPost(): bool {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
}

function timeAgo(string $datetime): string {
    $now  = new DateTime();
    $past = new DateTime($datetime);
    $diff = $now->diff($past);

    if ($diff->y > 0) return $diff->y . ' tahun lalu';
    if ($diff->m > 0) return $diff->m . ' bulan lalu';
    if ($diff->d > 0) return $diff->d . ' hari lalu';
    if ($diff->h > 0) return $diff->h . ' jam lalu';
    if ($diff->i > 0) return $diff->i . ' menit lalu';
    return 'baru saja';
}

function formatDate(string $datetime, string $format = 'd M Y, H:i'): string {
    return (new DateTime($datetime))->format($format);
}

// Load dynamic setting helper
function getSetting(string $key, string $default = ''): string {
    static $settings = null;
    if ($settings === null) {
        $settings = [];
        try {
            $db = db();
            $db->exec("CREATE TABLE IF NOT EXISTS `app_settings` (
                `key`        VARCHAR(100) NOT NULL PRIMARY KEY,
                `value`      TEXT DEFAULT NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $rows = $db->query("SELECT `key`, `value` FROM app_settings")->fetchAll();
            foreach ($rows as $row) {
                $settings[$row['key']] = $row['value'];
            }
        } catch (Throwable $e) {
            // Safe fallback
        }
    }
    return isset($settings[$key]) ? (string)$settings[$key] : $default;
}

// AI Writer Settings
define('AI_API_URL', getSetting('ai_api_url', 'https://router.penglaris.com/v1'));
define('AI_API_KEY', getSetting('ai_api_key', 'sk-6b3ac6ef8e3b70c9-u4746y-51424853'));
define('AI_MODEL', getSetting('ai_model', 'oc/deepseek-v4-flash-free'));
define('AI_BASIC_USER', getSetting('ai_basic_user', 'admin'));
define('AI_BASIC_PASS', getSetting('ai_basic_pass', 'sQRjROl1XpZq3tuF'));

// Pexels Image Search Settings
define('PEXELS_API_KEY', getSetting('pexels_api_key', 'InKuNGezvQ0EONo4nBdxLymBSFTkBpn9GwbWY9sWhV0dKo6bLzKMiOHA'));

function getPexelsImage(string $query): ?string {
    $url = 'https://api.pexels.com/v1/search?query=' . urlencode($query) . '&per_page=1';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . PEXELS_API_KEY
        ]
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    
    if ($resp) {
        $data = json_decode($resp, true);
        if (!empty($data['photos'][0]['src']['large'])) {
            return $data['photos'][0]['src']['large'];
        }
    }
    return null;
}

function downloadImageToTemp(string $url): ?array {
    $ch = curl_init($url);
    $tempFile = tempnam(sys_get_temp_dir(), 'pexels_');
    if (!$tempFile) return null;
    
    $fp = fopen($tempFile, 'wb');
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    
    if ($httpCode === 200) {
        return [
            'path' => $tempFile,
            'name' => 'pexels_' . time() . '.jpg',
            'mime' => 'image/jpeg'
        ];
    }
    @unlink($tempFile);
    return null;
}

function getLanguageName(string $code): string {
    $map = [
        'indonesian' => 'Bahasa Indonesia',
        'english'    => 'English',
        'japanese'   => 'Japanese (日本語)',
        'arabic'     => 'Arabic (العربية)',
        'spanish'    => 'Spanish (Español)',
        'french'     => 'French (Français)',
        'german'     => 'German (Deutsch)'
    ];
    return $map[$code] ?? 'Bahasa Indonesia';
}

function cleanJsonString(string $string): string {
    $string = trim($string);
    
    // Try matching code blocks first (without anchoring to start/end of string)
    if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $string, $matches)) {
        $string = trim($matches[1]);
    }
    
    // Find the first '{' and the last '}' and extract
    $start = strpos($string, '{');
    $end = strrpos($string, '}');
    if ($start !== false && $end !== false && $end > $start) {
        return substr($string, $start, $end - $start + 1);
    }
    
    return $string;
}

/**
 * Returns the master system prompt for the AI outline generator.
 */
function getOutlineSystemPrompt(string $langName): string {
    return "Anda adalah editor artikel SEO berpengalaman yang bertugas merancang struktur konten berkualitas tinggi. Buat judul artikel yang menarik dan SEO-friendly, beserta daftar sub-judul (outline) yang mengalir secara alami.\n\nATURAN WAJIB untuk outline:\n- Setiap sub-judul harus ditulis seperti judul bab buku — singkat, jelas, dan informatif (BUKAN pertanyaan retoris)\n- DILARANG menggunakan awalan seperti 'Pendahuluan:', 'Kesimpulan:', 'Bab:', 'Bagian:', 'Part:' atau sejenisnya di awal sub-judul\n- Gunakan sub-judul yang langsung menggambarkan isi konten, misalnya 'Faktor yang Mempengaruhi Gaji Programmer' bukan 'Pendahuluan: Faktor-Faktor yang...'\n- Buat 6-8 sub-judul yang mencakup aspek pembuka, isi utama, dan penutup secara alami\n\nATURAN UNTUK FIELD summary:\n- Tulis ringkasan artikel dalam 2-3 kalimat padat yang langsung menggambarkan isi artikel\n- Ditulis seperti paragraf pembuka alami — BUKAN kalimat seperti 'Artikel ini membahas...' atau 'Dalam panduan ini...'\n- Cocok digunakan sebagai meta description SEO dan paragraf pertama artikel\n- Gunakan bahasa {$langName}\n\nOutput HARUS berupa objek JSON valid dengan struktur:\n{ \"title\": \"Judul Artikel\", \"summary\": \"Ringkasan 2-3 kalimat pembuka artikel.\", \"tags\": [\"tag1\", \"tag2\"], \"category_suggestion\": \"Nama Kategori\", \"outline\": [\"Sub-judul 1\", \"Sub-judul 2\"], \"pexels_query\": \"Short English keyword for Pexels image search\" }\n\nKunci JSON HARUS tetap dalam bahasa Inggris. Nilai (judul, summary, tag, outline) ditulis dalam {$langName}.";
}

/**
 * Returns the master system prompt for the AI section/content writer.
 * Rules: no <strong> bold, short paragraphs (20-40 words each), blank line after h2, mobile-friendly.
 */
function getWriterSystemPrompt(string $langName): string {
    return "Kamu adalah penulis konten profesional dengan gaya penulisan seperti jurnalis teknologi senior — lugas, informatif, dan terasa natural seperti tulisan manusia nyata.\n\nBAHASA: Tulis sepenuhnya dalam {$langName}.\n\nFORMAT HTML (WAJIB DIIKUTI KETAT):\n- Gunakan <p> untuk setiap paragraf\n- SETIAP paragraf MAKSIMAL 30-40 kata — jika kalimat bertambah panjang, pecah menjadi paragraf baru dengan tag <p> baru\n- Gunakan <ul>/<li> atau <ol>/<li> untuk daftar poin jika diperlukan\n- JANGAN gunakan <strong> atau <b> untuk menebalkan teks — tulis biasa saja tanpa bold\n- Boleh pakai <table> jika data perbandingan perlu disajikan dalam bentuk tabel\n- JANGAN tulis ulang sub-judul bagian di awal teks\n- JANGAN tambahkan tag <html>, <head>, <body>\n\nGAYA PENULISAN (WAJIB DIIKUTI):\n1. Mulai setiap bagian dengan kalimat pembuka yang langsung masuk ke inti topik — BUKAN dengan kalimat klise seperti 'Dalam artikel ini...', 'Pada bagian ini kita akan...'\n2. Gunakan kalimat aktif, hindari kalimat pasif berlebihan\n3. Variasikan panjang kalimat — padukan kalimat pendek yang kuat dengan kalimat penjelas\n4. Sisipkan data, angka spesifik, atau contoh nyata untuk menambah kredibilitas\n5. Tulis minimal 4-5 paragraf pendek per bagian (bukan 2-3 paragraf panjang)\n6. Akhiri bagian dengan kalimat yang berkesan — BUKAN rangkuman klise\n\nHAL YANG DILARANG:\n- Jangan tulis frasa robot seperti: 'Tentu saja', 'Perlu dicatat bahwa', 'Penting untuk dipahami', 'Dengan demikian dapat disimpulkan'\n- Jangan gunakan 'Dalam konteks ini...' atau 'Berkaitan dengan hal tersebut...' berulang kali\n- Jangan isi konten dengan basa-basi yang tidak informatif\n- JANGAN gunakan <strong> atau <b> sama sekali";
}

/**
 * Post-process AI-generated article HTML to normalize spacing and remove bold tags.
 *
 * Rules enforced deterministically (not relying on AI consistency):
 * - Strip all <strong> and <b> tags (keep inner text)
 * - Collapse consecutive empty lines to at most ONE empty line
 * - Ensure exactly ONE \n between </p> and the next <p> or <h2>
 * - Ensure </h2> is followed by exactly ONE \n before <p>
 * - Remove lines that are pure whitespace
 */
function cleanArticleHtml(string $html): string {
    // 1. Strip bold tags, preserve inner text
    $html = preg_replace('/<strong[^>]*>(.*?)<\/strong>/is', '$1', $html);
    $html = preg_replace('/<b[^>]*>(.*?)<\/b>/is', '$1', $html);

    // 2. Normalize line endings
    $html = str_replace(["\r\n", "\r"], "\n", $html);

    // 3. Trim every line
    $lines = explode("\n", $html);
    $trimmedLines = array_map('trim', $lines);

    // 4. Filter out empty lines
    $nonEmptyLines = array_filter($trimmedLines, function($line) {
        return $line !== '';
    });

    // 5. Reconstruct with deterministic spacing
    $finalContent = '';
    $prevLine = '';
    foreach ($nonEmptyLines as $line) {
        if ($finalContent === '') {
            $finalContent .= $line;
        } else {
            // Check if we should insert a blank line.
            // We do NOT want a blank line before or after <li>, or after <ul> / <ol> / <table> opening tags, or before closing list/table tags.
            $noBlankBefore = preg_match('/^<\/(?:ul|ol|li|table|tr|thead|tbody)>$/i', $line) ||
                             preg_match('/^<(?:li|tr|thead|tbody|th|td)/i', $line);
                             
            $noBlankAfter = preg_match('/^<(?:ul|ol|table|tr|thead|tbody)/i', $prevLine) ||
                            preg_match('/^<(?:li|tr|thead|tbody|th|td)/i', $prevLine);

            if ($noBlankBefore || $noBlankAfter) {
                $finalContent .= "\n" . $line;
            } else {
                $finalContent .= "\n\n" . $line;
            }
        }
        $prevLine = $line;
    }

    return $finalContent;
}

