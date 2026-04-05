<?php
/**
 * convert.php — Standalone image format converter.
 * Converts uploaded images to any PHP-GD-supported output format
 * without requiring a resize step. Supports batch conversion.
 */
session_start();

// ─── Shared bootstrap ────────────────────────────────────────────────────────
if (file_exists(__DIR__ . '/.env')) {
    $env = @parse_ini_file(__DIR__ . '/.env');
    if (is_array($env)) {
        foreach ($env as $k => $v) {
            $_ENV[$k] = $v;
        }
    }
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; " .
    "script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://umami.spindlecrank.com; " .
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
    "font-src 'self' https://fonts.gstatic.com; " .
    "img-src 'self' data: blob:; connect-src 'none'; frame-ancestors 'none';");

date_default_timezone_set($_ENV['TIMEZONE'] ?? 'America/Chicago');

define('LOG_DIR',       __DIR__ . '/logs');
define('LOG_FILE',      LOG_DIR . '/app.log');
define('MAX_LOG_SIZE',  (int) ($_ENV['MAX_LOG_SIZE_MB']       ?? 10) * 1024 * 1024);
define('UPLOAD_DIR',    __DIR__ . '/uploads/');
define('CONVERTED_DIR', __DIR__ . '/converted/');
define('ZIP_DIR',       __DIR__ . '/zip/');
define('MAX_FILE_SIZE', (int) ($_ENV['MAX_FILE_SIZE_MB']      ?? 20) * 1024 * 1024);
define('MAX_FILES',     (int) ($_ENV['MAX_FILES']             ?? 20));
define('PURGE_MAX_AGE', (int) ($_ENV['PURGE_MAX_AGE_MINUTES'] ?? 15) * 60);
define('JPEG_QUALITY',  (int) ($_ENV['DEFAULT_JPEG_QUALITY']  ?? 90));
define('RATE_LIMIT_MAX',    (int) ($_ENV['RATE_LIMIT_MAX']    ?? 10));
define('RATE_LIMIT_WINDOW', (int) ($_ENV['RATE_LIMIT_WINDOW'] ?? 60));
define('IMAGICK_AVAILABLE', extension_loaded('imagick'));
define('EXIF_AVAILABLE',    extension_loaded('exif'));

const ALLOWED_INPUT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'bmp', 'webp', 'tiff', 'heic', 'gif'];
const ALLOWED_MIME_TYPES = [
    'image/jpeg', 'image/png', 'image/bmp', 'image/x-bmp', 'image/x-ms-bmp',
    'image/webp', 'image/tiff', 'image/heic', 'image/heif', 'image/gif',
];

// ─── Output formats supported by GD + Imagick ────────────────────────────────
// Each entry: label, extension, requires_imagick, quality_applicable
const OUTPUT_FORMATS = [
    'jpeg' => ['label' => 'JPEG (.jpg)',  'ext' => 'jpg',  'imagick' => false, 'quality' => true],
    'png'  => ['label' => 'PNG (.png)',   'ext' => 'png',  'imagick' => false, 'quality' => false],
    'webp' => ['label' => 'WebP (.webp)', 'ext' => 'webp', 'imagick' => false, 'quality' => true],
    'bmp'  => ['label' => 'BMP (.bmp)',   'ext' => 'bmp',  'imagick' => false, 'quality' => false],
    'gif'  => ['label' => 'GIF (.gif)',   'ext' => 'gif',  'imagick' => false, 'quality' => false],
    'tiff' => ['label' => 'TIFF (.tiff)', 'ext' => 'tiff', 'imagick' => true,  'quality' => false],
    'heic' => ['label' => 'HEIC (.heic)', 'ext' => 'heic', 'imagick' => true,  'quality' => true],
    'avif' => ['label' => 'AVIF (.avif)', 'ext' => 'avif', 'imagick' => true,  'quality' => true],
];

// ─── Logging ─────────────────────────────────────────────────────────────────
function logMessage(string $message, string $level = 'INFO'): void
{
    if (!is_dir(LOG_DIR)) {
        mkdir(LOG_DIR, 0755, true);
    }
    $htaccess = LOG_DIR . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }
    if (file_exists(LOG_FILE) && filesize(LOG_FILE) > MAX_LOG_SIZE) {
        rename(LOG_FILE, LOG_FILE . '.' . date('Ymd-His'));
    }
    $entry = sprintf('[%s] [%-5s] [convert] %s%s', date('Y-m-d H:i:s'), $level, $message, PHP_EOL);
    file_put_contents(LOG_FILE, $entry, FILE_APPEND | LOCK_EX);
}

function redirectWithError(string $message): never
{
    logMessage($message, 'ERROR');
    header('Location: error.php?error=' . urlencode($message));
    exit;
}

// ─── Directory bootstrap ─────────────────────────────────────────────────────
foreach ([UPLOAD_DIR, CONVERTED_DIR, ZIP_DIR, LOG_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// ─── Purge old files ─────────────────────────────────────────────────────────
function purgeOldFiles(): void
{
    $now = time();
    foreach ([ZIP_DIR, UPLOAD_DIR, CONVERTED_DIR] as $dir) {
        if (!is_dir($dir)) continue;
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $path = $dir . $f;
            if (is_file($path) && ($now - filemtime($path)) > PURGE_MAX_AGE) {
                unlink($path);
            }
        }
    }
}

// ─── CSRF ────────────────────────────────────────────────────────────────────
function generateCsrfToken(): string
{
    if (empty($_SESSION['csrf_token_convert'])) {
        $_SESSION['csrf_token_convert'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token_convert'];
}

function validateCsrfToken(): bool
{
    $s = $_POST['csrf_token'] ?? '';
    return $s !== '' && isset($_SESSION['csrf_token_convert'])
        && hash_equals($_SESSION['csrf_token_convert'], $s);
}

// ─── Rate limiting ────────────────────────────────────────────────────────────
function checkRateLimit(): void
{
    $now = time();
    $key = 'rate_limit_convert';
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'window_start' => $now];
    }
    if ($now - $_SESSION[$key]['window_start'] > RATE_LIMIT_WINDOW) {
        $_SESSION[$key] = ['count' => 0, 'window_start' => $now];
    }
    $_SESSION[$key]['count']++;
    if ($_SESSION[$key]['count'] > RATE_LIMIT_MAX) {
        http_response_code(429);
        redirectWithError('Too many requests. Please wait before trying again.');
    }
}

// ─── Upload error messages ────────────────────────────────────────────────────
function uploadErrorMessage(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server temporary folder is missing.',
        UPLOAD_ERR_CANT_WRITE => 'Server failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
        default               => "Unknown upload error (code {$code}).",
    };
}

// ─── Imagick orientation helper (compatible with all Imagick builds) ─────────
function imagickAutoOrient(Imagick $image): void
{
    if (method_exists($image, 'autoOrientImage')) {
        $image->autoOrientImage();
        return;
    }
    try {
        $orientation = $image->getImageOrientation();
    } catch (ImagickException $e) {
        return;
    }
    $bg = new ImagickPixel('none');
    switch ($orientation) {
        case Imagick::ORIENTATION_TOPRIGHT:
            $image->flopImage();    break;
        case Imagick::ORIENTATION_BOTTOMRIGHT:
            $image->rotateImage($bg, 180);    break;
        case Imagick::ORIENTATION_BOTTOMLEFT:
            $image->flipImage();    break;
        case Imagick::ORIENTATION_LEFTTOP:
            $image->rotateImage($bg, -90);  $image->flopImage();    break;
        case Imagick::ORIENTATION_RIGHTTOP:
            $image->rotateImage($bg, 90);   break;
        case Imagick::ORIENTATION_RIGHTBOTTOM:
            $image->rotateImage($bg, 90);   $image->flopImage();    break;
        case Imagick::ORIENTATION_LEFTBOTTOM:
            $image->rotateImage($bg, -90);  break;
    }
    $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
}

// ─── Core converter ──────────────────────────────────────────────────────────
/**
 * Convert a single image file to the target format.
 * Returns the path to the converted file.
 * Throws RuntimeException on any failure.
 */
function convertImage(string $sourcePath, string $targetFormat, int $quality): string
{
    $srcExt = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
    $outMeta = OUTPUT_FORMATS[$targetFormat] ?? null;
    if (!$outMeta) {
        throw new RuntimeException("Unknown target format: {$targetFormat}");
    }
    $outExt  = $outMeta['ext'];
    $stem    = pathinfo(basename($sourcePath), PATHINFO_FILENAME);
    $outPath = CONVERTED_DIR . $stem . '.' . $outExt;

    // ── Imagick path ──────────────────────────────────────────────────────────
    if (IMAGICK_AVAILABLE) {
        try {
            $image = new Imagick($sourcePath);
            imagickAutoOrient($image);   // compatible with all Imagick builds
            $image->stripImage();
            $imgFormat = ($outExt === 'jpg') ? 'jpeg' : $outExt;
            $image->setImageFormat($imgFormat);
            if ($outMeta['quality']) {
                $image->setImageCompressionQuality($quality);
            }
            $image->writeImage($outPath);
            $image->destroy();
            logMessage("Converted (Imagick): {$stem}.{$srcExt} → {$outExt}");
            return $outPath;
        } catch (\ImagickException $e) {
            throw new RuntimeException('Imagick error: ' . $e->getMessage());
        }
    }

    // ── GD path ───────────────────────────────────────────────────────────────
    if ($outMeta['imagick']) {
        throw new RuntimeException("Converting to {$outExt} requires Imagick, which is not available on this server.");
    }

    // EXIF auto-orient for JPEG sources
    $exifOrientation = 1;
    if (EXIF_AVAILABLE && in_array($srcExt, ['jpg', 'jpeg'], true)) {
        $exif = @exif_read_data($sourcePath);
        $exifOrientation = (int) ($exif['Orientation'] ?? 1);
    }

    $srcImage = match ($srcExt) {
        'jpg', 'jpeg' => imagecreatefromjpeg($sourcePath),
        'png'         => imagecreatefrompng($sourcePath),
        'bmp'         => imagecreatefrombmp($sourcePath),
        'webp'        => imagecreatefromwebp($sourcePath),
        'gif'         => imagecreatefromgif($sourcePath),
        default       => throw new RuntimeException("GD cannot read source format: {$srcExt}. Install Imagick for wider format support."),
    };
    if ($srcImage === false) {
        $hint = ($srcExt === 'bmp')
            ? ' GD only supports 24-bit uncompressed BMPs. Install Imagick for full BMP support, or convert to PNG/JPEG first.'
            : '';
        throw new RuntimeException('GD failed to load image: ' . basename($sourcePath) . '.' . $hint);
    }

    // Auto-orient
    if ($exifOrientation !== 1) {
        $oriented = match ($exifOrientation) {
            3 => imagerotate($srcImage, 180, 0),
            6 => imagerotate($srcImage, -90, 0),
            8 => imagerotate($srcImage,  90, 0),
            default => false,
        };
        if ($oriented !== false) {
            imagedestroy($srcImage);
            $srcImage = $oriented;
        }
    }

    $w = imagesx($srcImage);
    $h = imagesy($srcImage);

    // Create output canvas with appropriate transparency handling
    $outImage = imagecreatetruecolor($w, $h);
    if ($outImage === false) {
        imagedestroy($srcImage);
        throw new RuntimeException('GD: failed to allocate canvas.');
    }

    if (in_array($outExt, ['png', 'webp', 'gif'], true)) {
        imagealphablending($outImage, false);
        imagesavealpha($outImage, true);
        $transparent = imagecolorallocatealpha($outImage, 0, 0, 0, 127);
        imagefill($outImage, 0, 0, $transparent);
    } else {
        // Fill white background for JPEG/BMP (no alpha)
        $white = imagecolorallocate($outImage, 255, 255, 255);
        imagefill($outImage, 0, 0, $white);
    }

    imagecopy($outImage, $srcImage, 0, 0, 0, 0, $w, $h);
    imagedestroy($srcImage);

    $saved = match ($outExt) {
        'jpg'  => imagejpeg($outImage, $outPath, $quality),
        'png'  => imagepng($outImage, $outPath),
        'webp' => imagewebp($outImage, $outPath, $quality),
        'bmp'  => imagebmp($outImage, $outPath),
        'gif'  => imagegif($outImage, $outPath),
        default => false,
    };

    imagedestroy($outImage);

    if ($saved === false) {
        throw new RuntimeException("GD: failed to save as {$outExt}.");
    }

    logMessage("Converted (GD): {$stem}.{$srcExt} → {$outExt}");
    return $outPath;
}

// ─── PHP limit helpers ────────────────────────────────────────────────────────
function phpIniBytes(string $key): int
{
    $raw  = ini_get($key);
    $num  = (int) $raw;
    $unit = strtoupper(substr(trim($raw), -1));
    return match ($unit) {
        'G' => $num * 1024 * 1024 * 1024,
        'M' => $num * 1024 * 1024,
        'K' => $num * 1024,
        default => $num,
    };
}

$phpUploadLimit   = min(phpIniBytes('upload_max_filesize'), phpIniBytes('post_max_size'));
$effectiveLimitMB = round(min($phpUploadLimit, MAX_FILE_SIZE) / 1024 / 1024, 1);

// ─── Request handling ─────────────────────────────────────────────────────────
$resp   = '';
$errors = [];

purgeOldFiles();
logMessage('Request from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!validateCsrfToken()) {
        redirectWithError('Invalid security token. Please refresh and try again.');
    }
    checkRateLimit();
    if (!extension_loaded('gd')) {
        redirectWithError('GD library is not available on this server.');
    }

    $targetFormat = $_POST['target_format'] ?? '';
    if (!array_key_exists($targetFormat, OUTPUT_FORMATS)) {
        redirectWithError('Invalid target format selected.');
    }

    $quality = filter_var(
        $_POST['quality'] ?? JPEG_QUALITY,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 100]]
    );
    if ($quality === false) {
        $quality = JPEG_QUALITY;
    }

    if (empty($_FILES['images']['tmp_name'][0])) {
        redirectWithError('No files were uploaded.');
    }
    $fileCount = count($_FILES['images']['tmp_name']);
    if ($fileCount > MAX_FILES) {
        redirectWithError('Too many files. Maximum ' . MAX_FILES . ' per batch.');
    }

    $isSingleFile = ($fileCount === 1);
    $successCount = 0;
    $toDelete     = [];

    if ($isSingleFile) {
        $file = [
            'name'     => $_FILES['images']['name'][0],
            'tmp_name' => $_FILES['images']['tmp_name'][0],
            'error'    => $_FILES['images']['error'][0],
            'size'     => $_FILES['images']['size'][0],
        ];
        try {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException(uploadErrorMessage($file['error']));
            }
            if ($file['size'] > MAX_FILE_SIZE) {
                throw new RuntimeException('File too large.');
            }
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ALLOWED_INPUT_EXTENSIONS, true)) {
                throw new RuntimeException("Unsupported input format: {$ext}");
            }
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($file['tmp_name']);
            if (!in_array($mime, ALLOWED_MIME_TYPES, true)) {
                throw new RuntimeException("File content ({$mime}) is not an allowed image type.");
            }

            $safeName   = bin2hex(random_bytes(8)) . '.' . $ext;
            $uploadPath = UPLOAD_DIR . $safeName;
            if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
                throw new RuntimeException('Failed to save uploaded file.');
            }
            $toDelete[] = $uploadPath;

            $convertedPath = convertImage($uploadPath, $targetFormat, $quality);
            $outExt        = OUTPUT_FORMATS[$targetFormat]['ext'];
            $serveName     = bin2hex(random_bytes(8)) . '_' . pathinfo($file['name'], PATHINFO_FILENAME) . '.' . $outExt;
            $servePath     = ZIP_DIR . $serveName;
            rename($convertedPath, $servePath);

            $successCount  = 1;
            $downloadHref  = 'download.php?file=' . urlencode('zip/' . $serveName);
            $downloadLabel = htmlspecialchars(basename($serveName), ENT_QUOTES);

        } catch (RuntimeException $e) {
            $errors[] = htmlspecialchars(basename($file['name']), ENT_QUOTES) . ': ' . htmlspecialchars($e->getMessage(), ENT_QUOTES);
        }

    } else {
        // Multiple files — ZIP them
        $zipPath = 'zip/' . bin2hex(random_bytes(8)) . '.zip';
        $zip     = new ZipArchive();
        if ($zip->open(__DIR__ . '/' . $zipPath, ZipArchive::CREATE) !== true) {
            redirectWithError('Failed to create ZIP archive.');
        }

        foreach ($_FILES['images']['tmp_name'] as $key => $tmpName) {
            $file = [
                'name'     => $_FILES['images']['name'][$key],
                'tmp_name' => $tmpName,
                'error'    => $_FILES['images']['error'][$key],
                'size'     => $_FILES['images']['size'][$key],
            ];
            try {
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException(uploadErrorMessage($file['error']));
                }
                if ($file['size'] > MAX_FILE_SIZE) {
                    throw new RuntimeException('File too large.');
                }
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ALLOWED_INPUT_EXTENSIONS, true)) {
                    throw new RuntimeException("Unsupported input format: {$ext}");
                }
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($file['tmp_name']);
                if (!in_array($mime, ALLOWED_MIME_TYPES, true)) {
                    throw new RuntimeException("File content ({$mime}) is not allowed.");
                }

                $safeName   = bin2hex(random_bytes(8)) . '.' . $ext;
                $uploadPath = UPLOAD_DIR . $safeName;
                if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
                    throw new RuntimeException('Failed to save uploaded file.');
                }
                $toDelete[] = $uploadPath;

                $convertedPath = convertImage($uploadPath, $targetFormat, $quality);
                $toDelete[]    = $convertedPath;

                $outExt    = OUTPUT_FORMATS[$targetFormat]['ext'];
                $zipEntry  = pathinfo($file['name'], PATHINFO_FILENAME) . '_' . $key . '.' . $outExt;
                $zip->addFile($convertedPath, $zipEntry);
                $successCount++;

            } catch (RuntimeException $e) {
                logMessage("Skipping {$file['name']}: " . $e->getMessage(), 'WARN');
                $errors[] = htmlspecialchars(basename($file['name']), ENT_QUOTES)
                    . ': ' . htmlspecialchars($e->getMessage(), ENT_QUOTES);
            }
        }

        $zip->close();
        if ($successCount === 0 && file_exists(__DIR__ . '/' . $zipPath)) {
            unlink(__DIR__ . '/' . $zipPath);
        }

        $downloadHref  = 'download.php?file=' . urlencode($zipPath);
        $downloadLabel = 'Download ZIP (' . $successCount . ' file' . ($successCount !== 1 ? 's' : '') . ')';
    }

    // Rotate CSRF
    if ($successCount > 0) {
        $_SESSION['csrf_token_convert'] = bin2hex(random_bytes(32));
    }

    foreach (array_unique($toDelete) as $path) {
        if (file_exists($path)) unlink($path);
    }

    if ($successCount === 0) {
        redirectWithError('No files could be converted. ' . implode(' | ', $errors));
    }

    $resp = '<div id="successData" class="hidden"'
        . ' data-href="' . htmlspecialchars($downloadHref, ENT_QUOTES) . '"'
        . ' data-label="' . htmlspecialchars($downloadLabel, ENT_QUOTES) . '"'
        . ' data-count="' . $successCount . '"'
        . ' data-skipped="' . count($errors) . '"'
        . ' data-ts="' . time() . '"'
        . '></div>';
    if (!empty($errors)) {
        $resp .= '<ul id="errorsList" class="hidden">';
        foreach ($errors as $err) {
            $resp .= '<li>' . $err . '</li>';
        }
        $resp .= '</ul>';
    }
}

// ─── Determine available output formats for UI ────────────────────────────────
$availableFormats = [];
foreach (OUTPUT_FORMATS as $key => $meta) {
    if (!$meta['imagick'] || IMAGICK_AVAILABLE) {
        $availableFormats[$key] = $meta;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
    <meta name="description" content="Convert images between JPG, PNG, WebP, BMP, GIF, TIFF, HEIC and AVIF formats.">
    <title>Format Converter</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] } } }
        }
    </script>
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; }
        body { background: radial-gradient(ellipse 80% 50% at 50% -10%, rgba(16,185,129,0.14) 0%, transparent 65%), #020617; }
        html:not(.dark) body { background: radial-gradient(ellipse 80% 50% at 50% -10%, rgba(16,185,129,0.06) 0%, transparent 60%), #f1f5f9; }
        body.transitioning, body.transitioning * { transition: background-color 0.25s ease, border-color 0.25s ease, color 0.25s ease !important; }
        .drop-zone-active { border-color: #10b981 !important; background-color: rgba(16,185,129,0.07) !important; }
        input[type=range] { -webkit-appearance: none; appearance: none; height: 6px; border-radius: 9999px; background: #334155; outline: none; cursor: pointer; }
        html:not(.dark) input[type=range] { background: #cbd5e1; }
        input[type=range]::-webkit-slider-thumb { -webkit-appearance: none; appearance: none; width: 18px; height: 18px; border-radius: 50%; background: #10b981; cursor: pointer; border: 2px solid #34d399; box-shadow: 0 0 0 3px rgba(16,185,129,0.25); }
        input[type=range]::-moz-range-thumb { width: 18px; height: 18px; border-radius: 50%; background: #10b981; cursor: pointer; border: 2px solid #34d399; }
        optgroup, option { background-color: #1e293b; color: #e2e8f0; }
        html:not(.dark) optgroup, html:not(.dark) option { background-color: #ffffff; color: #1e293b; }
        #notifWrap { transition: transform 0.42s cubic-bezier(0.34,1.56,0.64,1), opacity 0.3s ease; }
        #notifWrap.notif-out { transform: translateY(140%); opacity: 0; pointer-events: none; }
        #notifWrap.notif-in  { transform: translateY(0);    opacity: 1; }
        @keyframes countdown { from { width:100%; } to { width:0%; } }
        @keyframes pop-in { 0%{transform:scale(0);opacity:0} 65%{transform:scale(1.15)} 100%{transform:scale(1);opacity:1} }
        .pop-in { animation: pop-in 0.45s cubic-bezier(0.34,1.56,0.64,1) 0.15s both; }
        html.dark  .icon-sun  { display: none; }
        html:not(.dark) .icon-moon { display: none; }
    </style>
    <script>
    (function(){
        var t=localStorage.getItem('imgr_theme'),sys=window.matchMedia('(prefers-color-scheme: dark)').matches;
        if(t==='light'||(!t&&!sys)){document.documentElement.classList.remove('dark');}
        else{document.documentElement.classList.add('dark');}
    })();
    </script>
    <script>
        const SERVER = {
            imagickAvailable: <?php echo IMAGICK_AVAILABLE ? 'true' : 'false'; ?>,
            maxFileSizeMB: <?php echo $effectiveLimitMB; ?>,
            maxFiles: <?php echo MAX_FILES; ?>,
            expiryMs: <?php echo PURGE_MAX_AGE * 1000; ?>
        };
    </script>
</head>
<body class="min-h-screen text-slate-800 dark:text-slate-100">

<!-- ── Theme toggle ── -->
<button id="themeToggle" aria-label="Toggle theme" onclick="toggleTheme()"
        class="fixed top-4 right-4 z-50 flex items-center gap-2 px-3 py-2 rounded-xl
               bg-white/90 dark:bg-slate-800/90 backdrop-blur-md
               border border-slate-200 dark:border-slate-700
               text-slate-500 dark:text-slate-300
               shadow-md hover:shadow-lg hover:scale-105 active:scale-95 transition-all duration-150">
    <svg class="icon-sun w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z"/>
    </svg>
    <svg class="icon-moon w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z"/>
    </svg>
    <span class="text-xs font-medium icon-sun">Light</span>
    <span class="text-xs font-medium icon-moon">Dark</span>
</button>

<!-- ── Success notification ── -->
<div class="fixed bottom-6 left-0 right-0 px-4 z-50 flex justify-center pointer-events-none">
    <div id="notifWrap" class="w-full max-w-sm pointer-events-auto notif-out">
        <div class="rounded-2xl shadow-2xl overflow-hidden bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
            <div class="flex items-start gap-3 p-4">
                <div class="pop-in flex-shrink-0 w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-500/15 border border-emerald-200 dark:border-emerald-500/25 flex items-center justify-center">
                    <svg class="w-5 h-5 text-emerald-500 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                    </svg>
                </div>
                <div class="flex-1 min-w-0 pt-0.5">
                    <p class="font-semibold text-slate-800 dark:text-slate-100 text-sm">Conversion complete!</p>
                    <p id="notifLabel" class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 truncate"></p>
                </div>
                <button onclick="dismissNotification()" class="flex-shrink-0 p-1 rounded-lg text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div id="notifErrors" class="hidden px-4 pb-3">
                <div class="bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 rounded-xl p-3">
                    <p id="notifErrHead" class="text-xs font-semibold text-amber-700 dark:text-amber-400 mb-1"></p>
                    <ul id="notifErrList" class="text-xs text-amber-600 dark:text-amber-400/80 list-disc list-inside space-y-0.5"></ul>
                </div>
            </div>
            <div class="px-4 pb-4">
                <button id="notifDownload"
                        class="w-full py-2.5 px-4 rounded-xl font-semibold text-sm text-white
                               bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500
                               shadow-md shadow-emerald-500/20 flex items-center justify-center gap-2
                               transition-all duration-200 hover:scale-[1.02] active:scale-100">
                    <svg class="w-4 h-4 animate-bounce" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                    </svg>
                    <span id="notifDownloadLabel">Download</span>
                </button>
            </div>
            <div class="h-1 bg-slate-100 dark:bg-slate-700/50 overflow-hidden">
                <div id="notifBar" class="h-full bg-gradient-to-r from-emerald-500 to-teal-500"></div>
            </div>
        </div>
    </div>
</div>

<div class="min-h-screen flex flex-col items-center py-10 px-4 sm:px-6 pb-16">

    <header class="w-full max-w-2xl mb-8 text-center">
        <div class="inline-flex items-center justify-center gap-3 mb-3">
            <div class="p-2.5 rounded-2xl bg-emerald-100 dark:bg-emerald-600/15 border border-emerald-300/50 dark:border-emerald-500/25 shadow-lg shadow-emerald-200/50 dark:shadow-emerald-900/20">
                <svg class="w-6 h-6 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 12c0-1.232-.046-2.453-.138-3.662a4.006 4.006 0 00-3.7-3.7 48.678 48.678 0 00-7.324 0 4.006 4.006 0 00-3.7 3.7c-.017.22-.032.441-.046.662M19.5 12l3-3m-3 3l-3-3m-12 3c0 1.232.046 2.453.138 3.662a4.006 4.006 0 003.7 3.7 48.656 48.656 0 007.324 0 4.006 4.006 0 003.7-3.7c.017-.22.032-.441.046-.662M4.5 12l3 3m-3-3l-3 3"/>
                </svg>
            </div>
            <h1 class="text-3xl sm:text-4xl font-bold tracking-tight text-transparent bg-clip-text bg-gradient-to-r from-emerald-600 via-teal-600 to-cyan-600 dark:from-emerald-400 dark:via-teal-400 dark:to-cyan-400">
                Format Converter
            </h1>
        </div>
        <p class="text-slate-500 dark:text-slate-400 text-sm sm:text-base">
            Convert images between
            <?php echo implode(', ', array_map(fn($m) => strtoupper($m['ext']), $availableFormats)); ?>
            formats — no resizing required.
        </p>
    </header>

    <main class="w-full max-w-2xl">
        <div class="bg-white/90 dark:bg-slate-900/60 backdrop-blur-2xl
                    border border-slate-200 dark:border-slate-700/40
                    rounded-3xl shadow-xl shadow-slate-300/40 dark:shadow-black/60 overflow-hidden">

            <div class="px-6 pt-6 space-y-3">
                <?php if (!IMAGICK_AVAILABLE): ?>
                <div class="flex gap-3 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 rounded-2xl p-4">
                    <svg class="w-5 h-5 text-amber-500 dark:text-amber-400 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <p class="text-amber-700 dark:text-amber-300/90 text-sm"><strong class="font-semibold">Imagick unavailable:</strong> TIFF, HEIC, and AVIF output are not supported.</p>
                </div>
                <?php endif; ?>
                <div class="flex justify-end">
                    <a href="index.php" class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-400 dark:text-slate-500 hover:text-emerald-600 dark:hover:text-emerald-400 transition-colors py-1">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/></svg>
                        Image Resizer
                    </a>
                </div>
            </div>

            <form id="convertForm" method="POST" enctype="multipart/form-data" class="p-6 space-y-7" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES); ?>">

                <section class="space-y-3">
                    <p class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">Upload</p>
                    <div id="dropZone"
                         class="flex flex-col items-center justify-center gap-4 border-2 border-dashed
                                border-slate-300 dark:border-slate-700/70 rounded-2xl px-6 py-10 text-center cursor-pointer
                                transition-all duration-200
                                hover:border-emerald-400 dark:hover:border-emerald-500/50
                                hover:bg-emerald-50/60 dark:hover:bg-emerald-950/20
                                bg-slate-50/50 dark:bg-slate-800/20">
                        <div class="p-4 rounded-2xl bg-slate-100 dark:bg-slate-700/50">
                            <svg class="w-8 h-8 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>
                            </svg>
                        </div>
                        <div>
                            <p class="text-slate-600 dark:text-slate-300 text-sm font-medium">
                                Drop images here, or <span class="text-emerald-600 dark:text-emerald-400 underline underline-offset-2">browse</span>
                            </p>
                            <p class="text-slate-400 dark:text-slate-600 text-xs mt-1.5">
                                Up to <?php echo MAX_FILES; ?> files &middot; Max <?php echo $effectiveLimitMB; ?> MB each
                            </p>
                        </div>
                        <input id="fileInput" type="file" name="images[]" accept="image/*" multiple required class="hidden">
                    </div>
                    <div id="previewGrid" class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 gap-2 hidden"></div>
                    <p id="fileCount" class="text-slate-400 dark:text-slate-500 text-xs hidden"></p>
                </section>

                <div class="border-t border-slate-200 dark:border-slate-800"></div>

                <section class="space-y-4">
                    <p class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">Convert To</p>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5" id="formatGrid">
                        <?php foreach ($availableFormats as $key => $meta): ?>
                        <label class="relative flex items-center gap-2.5
                                      bg-slate-50 dark:bg-slate-800/60
                                      hover:bg-slate-100 dark:hover:bg-slate-800
                                      border border-slate-300 dark:border-slate-700/60
                                      rounded-xl p-3 cursor-pointer transition-all duration-150
                                      has-[:checked]:border-emerald-500 dark:has-[:checked]:border-emerald-500/60
                                      has-[:checked]:bg-emerald-50 dark:has-[:checked]:bg-emerald-950/30">
                            <input type="radio" name="target_format" value="<?php echo htmlspecialchars($key, ENT_QUOTES); ?>"
                                   class="sr-only" onchange="toggleQuality()"
                                   <?php echo ($key === 'jpeg') ? 'checked' : ''; ?>>
                            <div class="w-4 h-4 rounded-full border-2 border-slate-400 dark:border-slate-600 flex items-center justify-center flex-shrink-0 format-ring transition-colors">
                                <div class="w-2 h-2 rounded-full bg-emerald-500 opacity-0 format-dot transition-opacity"></div>
                            </div>
                            <span class="text-sm text-slate-700 dark:text-slate-300 font-medium"><?php echo htmlspecialchars($meta['label'], ENT_QUOTES); ?></span>
                            <?php if ($meta['imagick']): ?>
                            <span class="ml-auto text-xs text-amber-500 dark:text-amber-400/80" title="Requires Imagick">*</span>
                            <?php endif; ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php if (IMAGICK_AVAILABLE): ?>
                    <p class="text-amber-500/80 dark:text-amber-400/70 text-xs">* Requires Imagick (available)</p>
                    <?php endif; ?>
                </section>

                <div class="border-t border-slate-200 dark:border-slate-800"></div>

                <section id="qualityRow" class="space-y-3">
                    <p class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">Quality</p>
                    <div>
                        <label for="quality" class="flex items-center justify-between text-sm text-slate-600 dark:text-slate-400 mb-2">
                            <span>Output quality</span>
                            <span class="text-emerald-600 dark:text-emerald-400 font-semibold tabular-nums"><span id="qualityVal"><?php echo JPEG_QUALITY; ?></span>%</span>
                        </label>
                        <input type="range" id="quality" name="quality"
                               min="1" max="100" value="<?php echo JPEG_QUALITY; ?>"
                               oninput="document.getElementById('qualityVal').textContent = this.value"
                               class="w-full mt-1">
                        <div class="flex justify-between text-xs text-slate-400 dark:text-slate-700 mt-1.5">
                            <span>Smaller</span><span>Better</span>
                        </div>
                        <p class="text-slate-400 dark:text-slate-600 text-xs mt-2">Applies to JPEG, WebP, HEIC, and AVIF output.</p>
                    </div>
                </section>

                <p id="validationError" class="text-red-500 dark:text-red-400 text-sm hidden"></p>

                <button type="submit" id="submitBtn"
                        class="w-full py-3.5 px-6 rounded-2xl font-semibold text-sm text-white
                               bg-gradient-to-r from-emerald-600 to-teal-600
                               hover:from-emerald-500 hover:to-teal-500
                               shadow-lg shadow-emerald-500/20 dark:shadow-emerald-950/60
                               transition-all duration-200 hover:scale-[1.015] active:scale-[0.99]
                               disabled:opacity-40 disabled:cursor-not-allowed disabled:scale-100 disabled:shadow-none">
                    Convert Image(s)
                </button>
            </form>

            <div id="result" class="hidden">
                <?php echo $resp; ?>
            </div>

            <div id="waiting" class="hidden px-6 pb-6 flex justify-center">
                <div class="inline-flex items-center gap-3 px-5 py-3 rounded-2xl
                            bg-slate-100 dark:bg-slate-800/70 border border-slate-200 dark:border-slate-700/50
                            text-slate-600 dark:text-slate-300 text-sm">
                    <svg class="animate-spin w-5 h-5 text-emerald-500 dark:text-emerald-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Converting your image(s)&hellip;
                </div>
            </div>
        </div>

        <footer class="mt-6 text-center">
            <p class="text-slate-400 dark:text-slate-800 text-xs">Powered by <a href="https://spindlecrank.com" class="hover:text-slate-600 transition-colors" target="_blank" rel="noopener">spindlecrank.com</a></p>
        </footer>
    </main>
</div>

<script>
function toggleTheme() {
    const isDark = document.documentElement.classList.contains('dark');
    document.body.classList.add('transitioning');
    if (isDark) { document.documentElement.classList.remove('dark'); localStorage.setItem('imgr_theme', 'light'); }
    else        { document.documentElement.classList.add('dark');    localStorage.setItem('imgr_theme', 'dark');  }
    setTimeout(() => document.body.classList.remove('transitioning'), 300);
}

let _dismissTimer = null;

function showNotification(href, label, skipped, errors) {
    document.getElementById('notifLabel').textContent = label;
    document.getElementById('notifDownloadLabel').textContent = label;
    const errBox = document.getElementById('notifErrors');
    if (skipped > 0 && errors.length) {
        document.getElementById('notifErrHead').textContent = skipped + ' file(s) skipped:';
        const ul = document.getElementById('notifErrList');
        ul.innerHTML = '';
        errors.forEach(e => { const li = document.createElement('li'); li.textContent = e; ul.appendChild(li); });
        errBox.classList.remove('hidden');
    } else { errBox.classList.add('hidden'); }
    document.getElementById('notifDownload').onclick = () => triggerDownload(href);
    const bar = document.getElementById('notifBar');
    bar.style.animation = 'none'; void bar.offsetWidth;
    bar.style.width = '100%'; bar.style.animation = 'countdown 30s linear forwards';
    const wrap = document.getElementById('notifWrap');
    wrap.classList.remove('notif-in'); wrap.classList.add('notif-out');
    void wrap.offsetWidth;
    wrap.classList.remove('notif-out'); wrap.classList.add('notif-in');
    clearTimeout(_dismissTimer);
    _dismissTimer = setTimeout(dismissNotification, 30000);
}

function dismissNotification() {
    clearTimeout(_dismissTimer);
    const wrap = document.getElementById('notifWrap');
    wrap.classList.remove('notif-in'); wrap.classList.add('notif-out');
    document.getElementById('result').innerHTML = '';
}

function triggerDownload(href) {
    window.location.href = href;
    dismissNotification();
    document.getElementById('convertForm').reset();
    document.getElementById('previewGrid').innerHTML = '';
    document.getElementById('previewGrid').classList.add('hidden');
    document.getElementById('fileCount').classList.add('hidden');
    toggleQuality(); updateFormatDots();
}

const qualityFormats = <?php
    $qf = array_keys(array_filter($availableFormats, fn($m) => $m['quality']));
    echo json_encode($qf);
?>;

function toggleQuality() {
    const sel = document.querySelector('input[name="target_format"]:checked');
    document.getElementById('qualityRow').style.display = sel && qualityFormats.includes(sel.value) ? '' : 'none';
    updateFormatDots();
}

function updateFormatDots() {
    document.querySelectorAll('#formatGrid label').forEach(label => {
        const radio = label.querySelector('input[type=radio]');
        const dot   = label.querySelector('.format-dot');
        const ring  = label.querySelector('.format-ring');
        if (!dot || !ring) return;
        dot.style.opacity    = radio.checked ? '1' : '0';
        ring.style.borderColor = radio.checked ? '#10b981' : '';
    });
}
document.querySelectorAll('input[name="target_format"]').forEach(r => r.addEventListener('change', updateFormatDots));
toggleQuality(); updateFormatDots();

const dropZone  = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
dropZone.addEventListener('click', () => fileInput.click());
['dragenter','dragover'].forEach(ev => dropZone.addEventListener(ev, e => { e.preventDefault(); dropZone.classList.add('drop-zone-active'); }));
['dragleave','drop'].forEach(ev  => dropZone.addEventListener(ev, () => dropZone.classList.remove('drop-zone-active')));
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    if (e.dataTransfer?.files.length) { fileInput.files = e.dataTransfer.files; handleFiles(fileInput.files); }
});
fileInput.addEventListener('change', () => handleFiles(fileInput.files));

function handleFiles(files) {
    const grid    = document.getElementById('previewGrid');
    const countEl = document.getElementById('fileCount');
    grid.innerHTML = '';
    if (!files.length) { grid.classList.add('hidden'); countEl.classList.add('hidden'); return; }
    const bytes = Array.from(files).reduce((s,f) => s+f.size, 0);
    countEl.textContent = files.length + ' file(s) · ' + (bytes < 1048576 ? (bytes/1024).toFixed(1)+' KB' : (bytes/1048576).toFixed(2)+' MB') + ' total';
    countEl.classList.remove('hidden'); grid.classList.remove('hidden');
    Array.from(files).forEach(file => {
        const reader = new FileReader();
        reader.onload = ev => {
            const img = new Image();
            img.onload = () => {
                const card = document.createElement('div');
                card.className = 'relative bg-slate-100 dark:bg-slate-800 rounded-xl overflow-hidden';
                const thumb = document.createElement('img');
                thumb.src = ev.target.result; thumb.className = 'w-full h-20 object-cover';
                const info = document.createElement('div');
                info.className = 'absolute bottom-0 left-0 right-0 bg-black/60 text-white text-xs px-1.5 py-1 truncate';
                info.textContent = file.name + ' · ' + img.naturalWidth + 'x' + img.naturalHeight;
                card.appendChild(thumb); card.appendChild(info); grid.appendChild(card);
            };
            img.src = ev.target.result;
        };
        reader.readAsDataURL(file);
    });
}

document.getElementById('convertForm').addEventListener('submit', function(e) {
    const errEl = document.getElementById('validationError');
    errEl.classList.add('hidden'); errEl.textContent = '';
    const files  = fileInput.files;
    const format = document.querySelector('input[name="target_format"]:checked');
    if (!files || !files.length) { e.preventDefault(); errEl.textContent = 'Please select at least one image.'; errEl.classList.remove('hidden'); return; }
    if (files.length > SERVER.maxFiles) { e.preventDefault(); errEl.textContent = 'Too many files. Maximum is ' + SERVER.maxFiles + '.'; errEl.classList.remove('hidden'); return; }
    if (!format) { e.preventDefault(); errEl.textContent = 'Please select a target format.'; errEl.classList.remove('hidden'); return; }
    document.getElementById('waiting').classList.remove('hidden');
    document.getElementById('submitBtn').disabled = true;
    document.title = 'Converting... - Format Converter';
});

document.addEventListener('DOMContentLoaded', function() {
    const sd = document.getElementById('successData');
    if (sd) {
        const href    = sd.dataset.href;
        const label   = sd.dataset.label;
        const skipped = parseInt(sd.dataset.skipped, 10);
        const errors  = Array.from(document.querySelectorAll('#errorsList li')).map(li => li.textContent);
        showNotification(href, label, skipped, errors);
        document.title = 'Done! - Format Converter';
    }
});
</script>
</body>
</html>
