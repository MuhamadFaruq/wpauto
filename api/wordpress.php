<?php
// ================================================
// api/wordpress.php — WordPress REST API Client
// ================================================

require_once __DIR__ . '/../config.php';

class WordPressAPI {
    private string $apiBase;
    private string $username;
    private string $password;
    private string $authHeader;

    public function __construct(array $site) {
        $this->apiBase   = rtrim($site['api_base_url'], '/');
        $this->username  = $site['app_username'];
        $this->password  = decryptPassword($site['app_password']);
        $this->authHeader = 'Basic ' . base64_encode($this->username . ':' . $this->password);
    }

    // ---- Core HTTP methods ----

    private function request(string $method, string $endpoint, array $data = [], array $extraHeaders = []): array {
        $url = $this->apiBase . $endpoint;
        $ch  = curl_init($url);

        $headers = array_merge([
            'Authorization: ' . $this->authHeader,
            'Content-Type: application/json',
            'Accept: application/json',
        ], $extraHeaders);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => false, // Allow self-signed on local WP installs
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        ]);

        if (!empty($data) && in_array(strtoupper($method), ['POST','PUT','PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => 'cURL error: ' . $error, 'code' => 0];
        }

        $body = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'data' => $body, 'code' => $httpCode];
        }

        $errMsg = $body['message'] ?? $body['error'] ?? 'HTTP ' . $httpCode;
        return ['success' => false, 'error' => $errMsg, 'code' => $httpCode, 'raw' => $body];
    }

    // ---- Test connection ----
    public function testConnection(): array {
        return $this->request('GET', '/');
    }

    // ---- Get recent posts list from WP ----
    public function getRecentPosts(int $count = 100): array {
        $result = $this->request('GET', '/posts?per_page=' . $count . '&context=edit');
        if (!$result['success']) {
            $result = $this->request('GET', '/posts?per_page=' . $count);
        }
        if ($result['success']) {
            return $result['data'];
        }
        return [];
    }

    // ---- Get authors list from WP ----
    public function getAuthors(): array {
        $result = $this->request('GET', '/users?per_page=100&context=edit');
        if ($result['success']) {
            return array_map(fn($u) => [
                'id'   => $u['id'],
                'name' => $u['name'],
                'slug' => $u['slug'],
            ], $result['data']);
        }
        return [];
    }

    // ---- Get categories ----
    public function getCategories(): array {
        $result = $this->request('GET', '/categories?per_page=100');
        if ($result['success']) {
            return array_map(fn($c) => [
                'id'   => $c['id'],
                'name' => $c['name'],
            ], $result['data']);
        }
        return [];
    }

    // ---- Get tags ----
    public function getTags(string $search = ''): array {
        $qs = '/tags?per_page=50' . ($search ? '&search=' . urlencode($search) : '');
        $result = $this->request('GET', $qs);
        if ($result['success']) {
            return $result['data'];
        }
        return [];
    }

    // ---- Create or get tag by name ----
    public function ensureTag(string $name): ?int {
        // Try to find existing
        $result = $this->request('GET', '/tags?search=' . urlencode($name) . '&per_page=5');
        if ($result['success'] && !empty($result['data'])) {
            foreach ($result['data'] as $tag) {
                if (strtolower($tag['name']) === strtolower($name)) {
                    return $tag['id'];
                }
            }
        }
        // Create new
        $create = $this->request('POST', '/tags', ['name' => $name]);
        if ($create['success']) {
            return $create['data']['id'] ?? null;
        }
        return null;
    }

    // ---- Upload image to WP Media Library ----
    public function uploadMedia(string $filePath, string $fileName, string $mimeType): array {
        $url = $this->apiBase . '/media';
        $ch  = curl_init($url);

        $fileData = file_get_contents($filePath);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $this->authHeader,
                'Content-Type: ' . $mimeType,
                'Content-Disposition: attachment; filename="' . $fileName . '"',
            ],
            CURLOPT_POSTFIELDS     => $fileData,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $body = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success'    => true,
                'media_id'   => $body['id'],
                'source_url' => $body['source_url'],
            ];
        }
        return ['success' => false, 'error' => $body['message'] ?? 'Upload failed'];
    }

    // ---- Publish / schedule a post ----
    public function publishPost(array $postData): array {
        /*
         * $postData keys:
         *   title, content, excerpt, status ('publish'|'future'),
         *   date_gmt (for scheduled, ISO8601),
         *   author (WP user ID),
         *   categories (array of IDs),
         *   tags (array of IDs),
         *   featured_media (WP media ID)
         */
        return $this->request('POST', '/posts', $postData);
    }

    // ---- Update an existing post ----
    public function updatePost(int $wpPostId, array $postData): array {
        return $this->request('PUT', '/posts/' . $wpPostId, $postData);
    }

    // ---- Get total posts count from WP headers ----
    public function getSiteMetadata(): array {
        $url = $this->apiBase . '/posts?per_page=1';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $this->authHeader,
            ],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300 && $response) {
            $headers = substr($response, 0, $headerSize);
            $totalPosts = 0;
            if (preg_match('/[Xx]-[Ww][Pp]-[Tt]otal:\s*(\d+)/', $headers, $matches)) {
                $totalPosts = (int)$matches[1];
            }
            return ['success' => true, 'total_posts' => $totalPosts];
        }
        return ['success' => false, 'total_posts' => 0];
    }
}

// ---- Factory: load site from DB and return API instance ----
function getWpApi(int $siteId): ?WordPressAPI {
    $stmt = db()->prepare("SELECT * FROM wp_sites WHERE id = ? AND status = 'active'");
    $stmt->execute([$siteId]);
    $site = $stmt->fetch();
    if (!$site) return null;
    return new WordPressAPI($site);
}
