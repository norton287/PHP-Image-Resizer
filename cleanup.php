<?php
/**
 * cleanup.php — Manual / cron-triggered maintenance script.
 *
 * Purges stale files from all temp directories (uploads, resized, zip).
 * Uses the same .env configuration as the main application.
 *
 * Protect this endpoint: restrict access to localhost or require a secret key
 * via the CLEANUP_SECRET environment variable.
 *
 * Usage:
 *   CLI:  php cleanup.php
 *   HTTP: GET /cleanup.php?secret=<CLEANUP_SECRET>
 *   Cron: 0 * * * * php /var/www/html/cleanup.php >> /var/www/html/logs/cleanup.log 2>&1
 */

// ─── Load .env ────────────────────────────────────────────────────────────────

if (file_exists(__DIR__ . '/.env')) {
    $env = @parse_ini_file(__DIR__ . '/.env');
    if (is_array($env)) {
        foreach ($env as $k => $v) {
            $_ENV[$k] = $v;
        }
    }
}

date_default_timezone_set($_ENV['TIMEZONE'] ?? 'America/Chicago');

// ─── Constants (mirrors index.php) ───────────────────────────────────────────

define('LOG_DIR',       __DIR__ . '/logs');
define('LOG_FILE',      LOG_DIR . '/app.log');
define('MAX_LOG_SIZE',  (int) ($_ENV['MAX_LOG_SIZE_MB']       ?? 10)  * 1024 * 1024);
define('UPLOAD_DIR',    __DIR__ . '/uploads/');
define('RESIZED_DIR',   __DIR__ . '/resized/');
define('ZIP_DIR',       __DIR__ . '/zip/');
define('PURGE_MAX_AGE', (int) ($_ENV['PURGE_MAX_AGE_MINUTES'] ?? 15)  * 60);

// ─── Auth ─────────────────────────────────────────────────────────────────────

if (PHP_SAPI !== 'cli') {
    $secret = getenv('CLEANUP_SECRET') ?: ($_ENV['CLEANUP_SECRET'] ?? '');
    if ($secret && ($_GET['secret'] ?? '') !== $secret) {
        http_response_code(403);
        echo 'Forbidden.';
        exit;
    }

    if (!$secret) {
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!in_array($remoteIp, ['127.0.0.1', '::1'], true)) {
            http_response_code(403);
            echo 'Forbidden — set CLEANUP_SECRET to enable remote access.';
            exit;
        }
    }
}

// ─── Logging ──────────────────────────────────────────────────────────────────

function logMessage(string $message, string $level = 'INFO'): void
{
    if (!is_dir(LOG_DIR)) {
        mkdir(LOG_DIR, 0755, true);
    }

    // Protect log directory from web access
    $htaccess = LOG_DIR . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }

    // Rotate if over size limit
    if (file_exists(LOG_FILE) && filesize(LOG_FILE) > MAX_LOG_SIZE) {
        rename(LOG_FILE, LOG_FILE . '.' . date('Ymd-His'));
    }

    $entry = sprintf('[%s] [CLEANUP] [%-5s] %s%s', date('Y-m-d H:i:s'), $level, $message, PHP_EOL);
    file_put_contents(LOG_FILE, $entry, FILE_APPEND | LOCK_EX);

    // Also write to stdout so CLI and cron redirects capture it
    echo $entry;
}

// ─── Purge ────────────────────────────────────────────────────────────────────

$maxAgeSeconds = PURGE_MAX_AGE;
$maxAgeMinutes = $maxAgeSeconds / 60;

// Primary directories
$directories = [
    UPLOAD_DIR,
    RESIZED_DIR,
    ZIP_DIR,
];

// Legacy directory (may or may not exist)
$legacyDirs = [
    __DIR__ . '/rotated/',
];

$now     = time();
$removed = 0;
$skipped = 0;
$errors  = 0;
$scanned = 0;

logMessage("Starting cleanup. Max age: {$maxAgeMinutes} min.");

foreach (array_merge($directories, $legacyDirs) as $dir) {
    if (!is_dir($dir)) {
        logMessage("SKIP $dir — directory does not exist.", 'WARN');
        continue;
    }

    $files = scandir($dir);
    if ($files === false) {
        logMessage("ERR  Cannot read directory: $dir", 'ERROR');
        $errors++;
        continue;
    }

    foreach ($files as $file) {
        if ($file === '.' || $file === '..' || $file === '.htaccess' || $file === '.gitkeep') {
            continue;
        }

        $path = $dir . $file;

        if (!is_file($path)) {
            continue;
        }

        $scanned++;
        $age = $now - filemtime($path);

        if ($age > $maxAgeSeconds) {
            if (unlink($path)) {
                logMessage(sprintf('DEL  %s (age %ds / %.1fm)', $path, $age, $age / 60));
                $removed++;
            } else {
                logMessage("ERR  Failed to delete: $path", 'ERROR');
                $errors++;
            }
        } else {
            $skipped++;
        }
    }
}

$summary = sprintf(
    'Cleanup complete — scanned: %d, removed: %d, kept: %d, errors: %d.',
    $scanned,
    $removed,
    $skipped,
    $errors
);

logMessage($summary);

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

exit($errors > 0 ? 1 : 0);
