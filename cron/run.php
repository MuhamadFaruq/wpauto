<?php
// ================================================
// cron/run.php — HTTP-accessible Scheduler Trigger
// ================================================
// Panggil via URL: https://yourdomain.com/WP/cron/run.php?token=CRON_SECRET
// Atau tambahkan ke hosting cron: GET https://... setiap menit
// Atau tambahkan ke wp-config.php WordPress target sebagai hook
// ================================================

// Security: token wajib cocok
define('CRON_SECRET', 'wp_cron_' . md5('auto_post_scheduler_2026'));

if (php_sapi_name() !== 'cli') {
    $token = $_GET['token'] ?? '';
    if ($token !== CRON_SECRET) {
        http_response_code(403);
        die(json_encode(['error' => 'Forbidden']));
    }
}

// Allow long execution
set_time_limit(300);
ignore_user_abort(true);

define('SCHEDULER_RUN', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/wordpress.php';

// Return JSON for HTTP calls, plain text for CLI
$isHttp = php_sapi_name() !== 'cli';
$output = [];

function cronLog(string $msg): void {
    global $output, $isHttp;
    if (!$isHttp) echo $msg . "\n";
    $output[] = $msg;
}

$now = new DateTime();
cronLog("[" . $now->format('Y-m-d H:i:s') . "] Scheduler running (HTTP mode)...");

// Find all due schedules
$stmt = db()->query(
    "SELECT ps.*, p.*, p.id as post_id, ps.id as schedule_id FROM post_schedules ps
     JOIN posts p ON ps.post_id = p.id
     WHERE ps.is_active = 1
       AND ps.scheduled_at <= NOW()
       AND p.status IN ('scheduled','draft','auto_template')
     ORDER BY ps.scheduled_at ASC
     LIMIT 10"  // limit 10 per hit agar tidak timeout di shared hosting
);

$schedules = $stmt->fetchAll();
cronLog("Found " . count($schedules) . " item(s) to process.");

if (empty($schedules)) {
    if ($isHttp) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'processed' => 0, 'log' => $output]);
    }
    exit;
}

// Include scheduler logic
require_once __DIR__ . '/scheduler.php';

if ($isHttp) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'processed' => count($schedules), 'log' => $output]);
}
