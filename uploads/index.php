<?php
// Prevent directory listing
if (!defined('APP_NAME')) {
    http_response_code(403);
    exit('Forbidden');
}
