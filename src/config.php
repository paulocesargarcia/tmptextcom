<?php

define('BASE_DIR', dirname(__DIR__));
define('STORAGE_DIR', BASE_DIR . '/storage');
define('FILES_DIR', STORAGE_DIR . '/files');
define('META_DIR', STORAGE_DIR . '/meta');
define('LOGS_DIR', STORAGE_DIR . '/logs');
define('RATE_LIMITS_DIR', STORAGE_DIR . '/rate_limits');

define('EXPIRATION_DAYS', 7);
define('RATE_LIMIT_MAX', 10);
define('RATE_LIMIT_WINDOW', 60); // seconds

// Ensure directories exist
$dirs = [FILES_DIR, META_DIR, LOGS_DIR, RATE_LIMITS_DIR];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}
