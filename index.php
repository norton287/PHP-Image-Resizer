<?php
session_start();

// ─── S: Load .env if present ─────────────────────────────────────────────────
if (file_exists(__DIR__ . '/.env')) {
    $env = @parse_ini_file(__DIR__ . '/.env');
    if (is_array($env)) {
        foreach ($env as $k => $v) {
            $_ENV[$k] = $v;
        }
    }
}

// ─── D: Security headers ─────────────────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; " .
    "script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://umami.spindlecrank.com; " .
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
    "font-src 'self' https://fonts.gstatic.com; " .
    "img-src 'self' data: blob:; connect-src 'none'; frame-ancestors 'none';");

date_default_timezone_set($_ENV['TIMEZONE'] ?? 'America/Chicago');

// ─── Constants (S: configurable via .env) ────────────────────────────────────
define('LOG_DIR',       __DIR__ . '/logs');
define('LOG_FILE',      LOG_DIR . '/app.log');
define('MAX_LOG_SIZE',  (int) ($_ENV['MAX_LOG_SIZE_MB']       ?? 10)  * 1024 * 1024);
define('UPLOAD_DIR',    __DIR__ . '/uploads/');
define('RESIZED_DIR',   __DIR__ . '/resized/');
define('ZIP_DIR',       __DIR__ . '/zip/');
define('MAX_FILE_SIZE', (int) ($_ENV['MAX_FILE_SIZE_MB']      ?? 20)  * 1024 * 1024);
define('MAX_FILES',     (int) ($_ENV['MAX_FILES']             ?? 20));
define('PURGE_MAX_AGE', (int) ($_ENV['PURGE_MAX_AGE_MINUTES'] ?? 15)  * 60);
define('JPEG_QUALITY',  (int) ($_ENV['DEFAULT_JPEG_QUALITY']  ?? 90));
define('RATE_LIMIT_MAX',    (int) ($_ENV['RATE_LIMIT_MAX']    ?? 10));
define('RATE_LIMIT_WINDOW', (int) ($_ENV['RATE_LIMIT_WINDOW'] ?? 60));
define('IMAGICK_AVAILABLE', extension_loaded('imagick'));
define('EXIF_AVAILABLE',    extension_loaded('exif'));

const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'bmp', 'webp', 'tiff', 'heic'];
const ALLOWED_MIME_TYPES  = [
    'image/jpeg', 'image/png', 'image/bmp', 'image/x-bmp', 'image/x-ms-bmp',
    'image/webp', 'image/tiff', 'image/heic', 'image/heif',
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
    $entry = sprintf('[%s] [%-5s] %s%s', date('Y-m-d H:i:s'), $level, $message, PHP_EOL);
    file_put_contents(LOG_FILE, $entry, FILE_APPEND | LOCK_EX);
}

// ─── Fatal redirect (non-recoverable errors) ─────────────────────────────────
function redirectWithError(string $message, string $level = 'ERROR'): never
{
    logMessage($message, $level);
    header('Location: error.php?error=' . urlencode($message));
    exit;
}

// ─── Directory bootstrap ─────────────────────────────────────────────────────
foreach ([UPLOAD_DIR, RESIZED_DIR, ZIP_DIR, LOG_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        logMessage("Created directory: $dir");
    }
}

// ─── Purge old temp files ─────────────────────────────────────────────────────
function purgeOldFiles(): void
{
    $now = time();
    // Also purge legacy rotated/ dir if it exists from previous versions
    foreach ([ZIP_DIR, UPLOAD_DIR, RESIZED_DIR, __DIR__ . '/rotated/'] as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        foreach (scandir($dir) as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $dir . $file;
            if (is_file($path) && ($now - filemtime($path)) > PURGE_MAX_AGE) {
                unlink($path);
                logMessage("Purged: $path", 'DEBUG');
            }
        }
    }
}

// ─── CSRF ─────────────────────────────────────────────────────────────────────
function generateCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function rotateCsrfToken(): void
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function validateCsrfToken(): bool
{
    $submitted = $_POST['csrf_token'] ?? '';
    return $submitted !== ''
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $submitted);
}

// ─── F: Rate limiting (session-based) ────────────────────────────────────────
function checkRateLimit(): void
{
    $now = time();
    if (!isset($_SESSION['rate_limit'])) {
        $_SESSION['rate_limit'] = ['count' => 0, 'window_start' => $now];
    }
    if ($now - $_SESSION['rate_limit']['window_start'] > RATE_LIMIT_WINDOW) {
        $_SESSION['rate_limit'] = ['count' => 0, 'window_start' => $now];
    }
    $_SESSION['rate_limit']['count']++;
    if ($_SESSION['rate_limit']['count'] > RATE_LIMIT_MAX) {
        http_response_code(429);
        redirectWithError('Too many requests. Please wait a moment before trying again.');
    }
}

// ─── C: Upload error code → human message ────────────────────────────────────
function uploadErrorMessage(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit (upload_max_filesize).',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary upload folder.',
        UPLOAD_ERR_CANT_WRITE => 'Server failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
        default               => "Unknown upload error (code {$code}).",
    };
}

function isAdvancedFormat(string $ext): bool
{
    return in_array($ext, ['webp', 'tiff', 'heic'], true);
}

/**
 * Correct Imagick image orientation based on embedded EXIF data.
 * Uses autoOrientImage() when available; falls back to manual rotate/flip
 * for older Imagick builds that don't have that method.
 */
function imagickAutoOrient(Imagick $image): void
{
    if (method_exists($image, 'autoOrientImage')) {
        $image->autoOrientImage();
        return;
    }

    // Manual fallback: read orientation tag and apply the equivalent transform
    try {
        $orientation = $image->getImageOrientation();
    } catch (ImagickException $e) {
        return; // can't read orientation — leave image as-is
    }

    $bg = new ImagickPixel('none');

    switch ($orientation) {
        case Imagick::ORIENTATION_TOPRIGHT:    // 2 — flip horizontal
            $image->flopImage();
            break;
        case Imagick::ORIENTATION_BOTTOMRIGHT: // 3 — rotate 180
            $image->rotateImage($bg, 180);
            break;
        case Imagick::ORIENTATION_BOTTOMLEFT:  // 4 — flip vertical
            $image->flipImage();
            break;
        case Imagick::ORIENTATION_LEFTTOP:     // 5 — transpose (rotate 90 CCW + flip H)
            $image->rotateImage($bg, -90);
            $image->flopImage();
            break;
        case Imagick::ORIENTATION_RIGHTTOP:    // 6 — rotate 90 CW
            $image->rotateImage($bg, 90);
            break;
        case Imagick::ORIENTATION_RIGHTBOTTOM: // 7 — transverse (rotate 90 CW + flip H)
            $image->rotateImage($bg, 90);
            $image->flopImage();
            break;
        case Imagick::ORIENTATION_LEFTBOTTOM:  // 8 — rotate 270 CW (90 CCW)
            $image->rotateImage($bg, -90);
            break;
        // ORIENTATION_TOPLEFT (1) and ORIENTATION_UNDEFINED (0) need no correction
    }

    // Mark orientation as corrected so downstream tools don't re-apply it
    $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
}

// ─── Upload validation — throws RuntimeException ──────────────────────────────
function validateUploadedFile(array $file): void
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(uploadErrorMessage($file['error']));
    }
    if ($file['size'] > MAX_FILE_SIZE) {
        $mb = MAX_FILE_SIZE / 1024 / 1024;
        throw new RuntimeException("File too large. Maximum is {$mb} MB.");
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
        throw new RuntimeException("Unsupported extension '{$ext}'. Allowed: JPG, PNG, BMP, WebP, TIFF, HEIC.");
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, ALLOWED_MIME_TYPES, true)) {
        throw new RuntimeException("File content ({$mime}) does not match an allowed image type.");
    }
}

// ─── Upload — throws RuntimeException ────────────────────────────────────────
function uploadImage(array $file): string
{
    $ext        = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $safeName   = bin2hex(random_bytes(8)) . '.' . $ext;
    $targetPath = UPLOAD_DIR . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException("Failed to save uploaded file '{$file['name']}'.");
    }
    logMessage("Uploaded: {$file['name']} → {$safeName}");
    return $targetPath;
}

// ─── Main image processor — A B G H J K L M N R (throws RuntimeException) ────
function processImage(
    string $imagePath,
    int    $targetWidth,
    int    $targetHeight,
    bool   $maintainAspectRatio,
    string $outputFormat,       // 'original' | 'jpeg' | 'png' | 'webp'
    int    $quality,            // 1-100 for jpeg/webp
    bool   $sharpen,            // K
    bool   $grayscale,          // R
    string $flip,               // 'none' | 'h' | 'v' | 'both'   N
    int    $rotateDegrees       // 0 | 45 | 90 | 180 | 270
): string {
    $srcExt = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
    $outExt = ($outputFormat === 'original') ? $srcExt : $outputFormat;
    // Normalise jpeg alias
    if ($outExt === 'jpeg') {
        $outExt = 'jpg';
    }

    $stem       = pathinfo(basename($imagePath), PATHINFO_FILENAME);
    $outputPath = RESIZED_DIR . $stem . '.' . $outExt;

    // ── Imagick path ─────────────────────────────────────────────────────────────
    // BMP is included here because GD only supports 24-bit uncompressed BMPs;
    // Imagick handles all BMP variants (indexed, RLE-compressed, 32-bit, etc.)
    if ((isAdvancedFormat($srcExt) || $srcExt === 'bmp') && IMAGICK_AVAILABLE) {
        try {
            $image = new Imagick($imagePath);
            imagickAutoOrient($image);   // A: EXIF orientation (compatible with all Imagick builds)
            $image->stripImage();        // B: strip EXIF/metadata

            if ($maintainAspectRatio) {
                $image->thumbnailImage($targetWidth, $targetHeight, true);
            } else {
                $image->resizeImage($targetWidth, $targetHeight, Imagick::FILTER_LANCZOS, 1);
            }

            // R: Grayscale
            if ($grayscale) {
                $image->transformImageColorspace(Imagick::COLORSPACE_GRAY);
            }

            // N: Flip
            if ($flip === 'h' || $flip === 'both') {
                $image->flopImage();
            }
            if ($flip === 'v' || $flip === 'both') {
                $image->flipImage();
            }

            // K: Sharpen
            if ($sharpen) {
                $image->unsharpMaskImage(0, 0.5, 1, 0.05);
            }

            // User rotation
            if ($rotateDegrees > 0) {
                $image->rotateImage(new ImagickPixel('none'), $rotateDegrees);
            }

            // L: Output format conversion
            if ($outputFormat !== 'original') {
                $image->setImageFormat($outExt === 'jpg' ? 'jpeg' : $outExt);
            }
            if (in_array($outExt, ['jpg', 'webp'], true)) {
                $image->setImageCompressionQuality($quality);
            }

            $image->writeImage($outputPath);
            $image->destroy();

        } catch (\ImagickException $e) {
            throw new RuntimeException('Imagick error: ' . $e->getMessage());
        }

        logMessage("Processed (Imagick): {$stem} → {$outExt}");
        return $outputPath;
    }

    // ── GD path ───────────────────────────────────────────────────────────────

    // A: Read EXIF orientation (JPEG only)
    $exifOrientation = 1;
    if (EXIF_AVAILABLE && in_array($srcExt, ['jpg', 'jpeg'], true)) {
        $exifData        = @exif_read_data($imagePath);
        $exifOrientation = (int) ($exifData['Orientation'] ?? 1);
    }

    // Load source
    $srcImage = match ($srcExt) {
        'jpg', 'jpeg' => imagecreatefromjpeg($imagePath),
        'png'         => imagecreatefrompng($imagePath),
        'bmp'         => imagecreatefrombmp($imagePath),
        'webp'        => imagecreatefromwebp($imagePath),
        default       => throw new RuntimeException("Unsupported source format: {$srcExt}. Install Imagick for advanced formats."),
    };
    if ($srcImage === false) {
        $hint = ($srcExt === 'bmp')
            ? ' GD only supports 24-bit uncompressed BMPs. Install Imagick for full BMP support, or convert to PNG/JPEG first.'
            : '';
        throw new RuntimeException('GD failed to load image: ' . basename($imagePath) . '.' . $hint);
    }

    // A: Auto-orient before resize
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

    $srcW = imagesx($srcImage);
    $srcH = imagesy($srcImage);

    // Compute output canvas size
    if ($maintainAspectRatio) {
        $scale  = min($targetWidth / $srcW, $targetHeight / $srcH);
        $newW   = (int) round($srcW * $scale);
        $newH   = (int) round($srcH * $scale);
    } else {
        $newW = $targetWidth;
        $newH = $targetHeight;
    }

    $canvas = imagecreatetruecolor($newW, $newH);
    if ($canvas === false) {
        imagedestroy($srcImage);
        throw new RuntimeException('GD: failed to allocate canvas.');
    }

    // Transparency for PNG/WebP output; white fill for JPEG/BMP output
    if (in_array($outExt, ['png', 'webp'], true)) {
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);
    } else {
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
    }

    imagecopyresampled($canvas, $srcImage, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
    imagedestroy($srcImage);

    // R: Grayscale
    if ($grayscale) {
        imagefilter($canvas, IMG_FILTER_GRAYSCALE);
    }

    // N: Flip
    match ($flip) {
        'h'    => imageflip($canvas, IMG_FLIP_HORIZONTAL),
        'v'    => imageflip($canvas, IMG_FLIP_VERTICAL),
        'both' => imageflip($canvas, IMG_FLIP_BOTH),
        default => null,
    };

    // K: Sharpen (unsharp mask via convolution)
    if ($sharpen) {
        imageconvolution($canvas, [[0, -1, 0], [-1, 5, -1], [0, -1, 0]], 1, 0);
    }

    // User rotation
    if ($rotateDegrees > 0) {
        $rotated = imagerotate($canvas, -$rotateDegrees, 0);
        if ($rotated === false) {
            imagedestroy($canvas);
            throw new RuntimeException('GD: imagerotate failed.');
        }
        imagedestroy($canvas);
        $canvas = $rotated;
    }

    // B: GD re-encodes without EXIF — strip is automatic. No extra step needed.

    // L + M: Save in chosen output format / quality
    $saved = match ($outExt) {
        'jpg', 'jpeg' => imagejpeg($canvas, $outputPath, $quality),
        'png'         => imagepng($canvas, $outputPath),
        'webp'        => imagewebp($canvas, $outputPath, $quality),
        'bmp'         => imagebmp($canvas, $outputPath),
        default       => false,
    };

    imagedestroy($canvas);

    if ($saved === false) {
        throw new RuntimeException("GD: failed to save as {$outExt}.");
    }

    logMessage("Processed (GD): {$stem} → {$outExt}");
    return $outputPath;
}

// ─── PHP limit helpers (I) ────────────────────────────────────────────────────
function phpIniBytes(string $key): int
{
    $raw = ini_get($key);
    $num = (int) $raw;
    $unit = strtoupper(substr(trim($raw), -1));
    return match ($unit) {
        'G' => $num * 1024 * 1024 * 1024,
        'M' => $num * 1024 * 1024,
        'K' => $num * 1024,
        default => $num,
    };
}

$phpUploadLimit = min(phpIniBytes('upload_max_filesize'), phpIniBytes('post_max_size'));
$phpLimitMB     = round($phpUploadLimit / 1024 / 1024, 1);
$appLimitMB     = MAX_FILE_SIZE / 1024 / 1024;
$effectiveLimitMB = min($phpLimitMB, $appLimitMB);

// ─── Request handling ─────────────────────────────────────────────────────────
$resp     = '';
$warnings = [];

purgeOldFiles();
logMessage('Request from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Fatal guards — these abort the whole request
    if (!validateCsrfToken()) {
        redirectWithError('Invalid security token. Please refresh and try again.');
    }
    checkRateLimit();                      // F
    if (!extension_loaded('gd')) {
        redirectWithError('GD library is not available on this server.');
    }

    // Validate scalar inputs
    $resizeWidth = filter_var(
        $_POST['width'] ?? '',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 10000]]
    );
    $resizeHeight = filter_var(
        $_POST['height'] ?? '',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 10000]]
    );
    if ($resizeWidth === false || $resizeHeight === false) {
        redirectWithError('Invalid dimensions. Width and height must be integers between 1 and 10 000.');
    }

    $allowedRotations = [0, 45, 90, 180, 270];
    $rotateDegrees    = (int) ($_POST['rotation'] ?? 0);
    if (!in_array($rotateDegrees, $allowedRotations, true)) {
        redirectWithError('Invalid rotation value.');
    }

    $allowedFlips = ['none', 'h', 'v', 'both'];
    $flip         = $_POST['flip'] ?? 'none';
    if (!in_array($flip, $allowedFlips, true)) {
        $flip = 'none';
    }

    $allowedOutputFormats = ['original', 'jpeg', 'png', 'webp'];
    $outputFormat = $_POST['output_format'] ?? 'original';
    if (!in_array($outputFormat, $allowedOutputFormats, true)) {
        $outputFormat = 'original';
    }

    $quality = filter_var(
        $_POST['quality'] ?? JPEG_QUALITY,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 100]]
    );
    if ($quality === false) {
        $quality = JPEG_QUALITY;
    }

    $maintainAspectRatio = isset($_POST['maintainAspectRatio']);
    $sharpen             = isset($_POST['sharpen']);
    $grayscale           = isset($_POST['grayscale']);

    if (empty($_FILES['images']['tmp_name'][0])) {
        redirectWithError('No files were uploaded.');
    }
    $fileCount = count($_FILES['images']['tmp_name']);
    if ($fileCount > MAX_FILES) {
        redirectWithError('Too many files. Maximum ' . MAX_FILES . ' per batch.');
    }

    // ── H: Single-file direct download (no ZIP) ───────────────────────────────
    $isSingleFile = ($fileCount === 1);

    $errors       = [];
    $toDelete     = [];
    $successCount = 0;

    if ($isSingleFile) {
        // Process and serve directly — no ZIP needed
        $file = [
            'name'     => $_FILES['images']['name'][0],
            'tmp_name' => $_FILES['images']['tmp_name'][0],
            'error'    => $_FILES['images']['error'][0],
            'size'     => $_FILES['images']['size'][0],
            'type'     => $_FILES['images']['type'][0],
        ];
        try {
            validateUploadedFile($file);
            $uploadedPath = uploadImage($file);
            $toDelete[]   = $uploadedPath;

            $processedPath = processImage(
                $uploadedPath, $resizeWidth, $resizeHeight,
                $maintainAspectRatio, $outputFormat, $quality,
                $sharpen, $grayscale, $flip, $rotateDegrees
            );

            // Move processed file into ZIP_DIR so download.php can serve it
            $serveName = bin2hex(random_bytes(8)) . '_' . basename($processedPath);
            $servePath = ZIP_DIR . $serveName;
            rename($processedPath, $servePath);
            $toDelete[] = $uploadedPath;

            $successCount = 1;
            $downloadHref = 'download.php?file=' . urlencode('zip/' . $serveName);
            $downloadLabel = htmlspecialchars(
                pathinfo($file['name'], PATHINFO_FILENAME) . '.' .
                pathinfo($servePath, PATHINFO_EXTENSION), ENT_QUOTES
            );

        } catch (RuntimeException $e) {
            $errors[] = htmlspecialchars(basename($file['name']), ENT_QUOTES) . ': ' . htmlspecialchars($e->getMessage(), ENT_QUOTES);
        }

    } else {
        // ── Multiple files — ZIP ───────────────────────────────────────────────
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
                'type'     => $_FILES['images']['type'][$key],
            ];
            try {
                validateUploadedFile($file);
                $uploadedPath  = uploadImage($file);
                $toDelete[]    = $uploadedPath;

                $processedPath = processImage(
                    $uploadedPath, $resizeWidth, $resizeHeight,
                    $maintainAspectRatio, $outputFormat, $quality,
                    $sharpen, $grayscale, $flip, $rotateDegrees
                );
                $toDelete[] = $processedPath;

                $zip->addFile($processedPath, $key . '_' . basename($processedPath));
                logMessage('Added to ZIP: ' . basename($processedPath));
                $successCount++;

            } catch (RuntimeException $e) {
                logMessage("Skipping {$file['name']}: " . $e->getMessage(), 'WARN');
                $errors[] = htmlspecialchars(basename($file['name']), ENT_QUOTES)
                    . ': ' . htmlspecialchars($e->getMessage(), ENT_QUOTES);
            }
        }

        $zip->close();

        if ($successCount === 0) {
            // All files failed — clean up empty ZIP
            if (file_exists(__DIR__ . '/' . $zipPath)) {
                unlink(__DIR__ . '/' . $zipPath);
            }
        }

        $downloadHref  = 'download.php?file=' . urlencode($zipPath);
        $downloadLabel = 'Download ZIP (' . $successCount . ' file' . ($successCount !== 1 ? 's' : '') . ')';
    }

    // E: Rotate CSRF token after any successful processing
    if ($successCount > 0) {
        rotateCsrfToken();
    }

    // Clean up temp upload/processed files
    foreach (array_unique($toDelete) as $path) {
        if (file_exists($path)) {
            unlink($path);
        }
    }

    if ($successCount === 0) {
        redirectWithError('No files could be processed. ' . implode(' | ', $errors));
    }

    logMessage("Done. {$successCount} file(s) processed.");

    // ── G: Build response — partial failure warnings + download button ─────────
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
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <script>
    (function(){
        var t=localStorage.getItem('imgr_theme'),sys=window.matchMedia('(prefers-color-scheme: dark)').matches;
        if(t==='light'||(!t&&!sys)){document.documentElement.classList.remove('dark');}
        else{document.documentElement.classList.add('dark');}
    })();
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
    <meta http-equiv="Pragma" content="no-cache">
    <meta name="description" content="Resize your images to custom dimensions or social media presets. Supports JPG, PNG, BMP, WebP, TIFF, HEIC.">
    <meta property="og:title" content="Image Resizer">
    <meta property="og:description" content="Quickly resize images for social media or custom sizes.">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="manifest" href="/site.webmanifest">
    <link rel="mask-icon" href="/safari-pinned-tab.svg" color="#5bbad5">
    <meta name="msapplication-TileColor" content="#0f172a">
    <meta name="theme-color" content="#0f172a">
    <meta name="google-site-verification" content="gu3duYB5OEsqTehyFOA1M1OOzJ--AfbTsk4dt_CVJTU">
    <title>Image Resizer</title>
    <script defer src="https://umami.spindlecrank.com/script.js" data-website-id="2f510d1b-c38e-4c4c-ad90-645240db4037"></script>
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
        body { background: radial-gradient(ellipse 80% 50% at 50% -10%, rgba(99,102,241,0.18) 0%, transparent 65%), #020617; }
        html:not(.dark) body { background: radial-gradient(ellipse 80% 50% at 50% -10%, rgba(99,102,241,0.07) 0%, transparent 60%), #f1f5f9; }
        body.transitioning, body.transitioning * { transition: background-color 0.25s ease, border-color 0.25s ease, color 0.25s ease, box-shadow 0.25s ease !important; }
        .drop-zone-active { border-color: #6366f1 !important; background-color: rgba(99,102,241,0.08) !important; }
        input[type=range] { -webkit-appearance: none; appearance: none; height: 6px; border-radius: 9999px; background: #334155; outline: none; cursor: pointer; }
        html:not(.dark) input[type=range] { background: #cbd5e1; }
        input[type=range]::-webkit-slider-thumb { -webkit-appearance: none; appearance: none; width: 18px; height: 18px; border-radius: 50%; background: #6366f1; cursor: pointer; border: 2px solid #818cf8; box-shadow: 0 0 0 3px rgba(99,102,241,0.25); }
        input[type=range]::-moz-range-thumb { width: 18px; height: 18px; border-radius: 50%; background: #6366f1; cursor: pointer; border: 2px solid #818cf8; }
        select { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%2394a3b8' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E"); background-position: right 10px center; background-repeat: no-repeat; background-size: 18px; -webkit-appearance: none; appearance: none; padding-right: 2.5rem !important; }
        html:not(.dark) select { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%2364748b' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E"); }
        optgroup, option { background-color: #1e293b; color: #e2e8f0; }
        html:not(.dark) optgroup, html:not(.dark) option { background-color: #ffffff; color: #1e293b; }
        /* Notification */
        #notifWrap { transition: transform 0.42s cubic-bezier(0.34,1.56,0.64,1), opacity 0.3s ease; }
        #notifWrap.notif-out { transform: translateY(140%); opacity: 0; pointer-events: none; }
        #notifWrap.notif-in  { transform: translateY(0);    opacity: 1; }
        @keyframes countdown { from { width:100%; } to { width:0%; } }
        @keyframes pop-in { 0%{transform:scale(0) rotate(-10deg);opacity:0} 65%{transform:scale(1.15)} 100%{transform:scale(1);opacity:1} }
        .pop-in { animation: pop-in 0.45s cubic-bezier(0.34,1.56,0.64,1) 0.15s both; }
        @keyframes slide-up { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
        .recent-item { animation: slide-up 0.22s ease both; }
        /* Theme toggle icon swap */
        html.dark  .icon-sun  { display: none; }
        html:not(.dark) .icon-moon { display: none; }
    </style>
    <script>
        const SERVER = {
            imagickAvailable: <?php echo IMAGICK_AVAILABLE ? 'true' : 'false'; ?>,
            exifAvailable: <?php echo EXIF_AVAILABLE ? 'true' : 'false'; ?>,
            maxFileSizeMB: <?php echo $effectiveLimitMB; ?>,
            maxFiles: <?php echo MAX_FILES; ?>,
            expiryMs: <?php echo PURGE_MAX_AGE * 1000; ?>,
            purgeMinutes: <?php echo intval(PURGE_MAX_AGE / 60); ?>
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
               shadow-md hover:shadow-lg hover:scale-105 active:scale-95
               transition-all duration-150">
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
                    <p class="font-semibold text-slate-800 dark:text-slate-100 text-sm">Processing complete!</p>
                    <p id="notifLabel" class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 truncate"></p>
                </div>
                <button onclick="dismissNotification()"
                        class="flex-shrink-0 p-1 rounded-lg text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
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
                               bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500
                               shadow-md shadow-indigo-500/20 flex items-center justify-center gap-2
                               transition-all duration-200 hover:scale-[1.02] active:scale-100">
                    <svg class="w-4 h-4 animate-bounce" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                    </svg>
                    <span id="notifDownloadLabel">Download</span>
                </button>
            </div>
            <div class="h-1 bg-slate-100 dark:bg-slate-700/50 overflow-hidden">
                <div id="notifBar" class="h-full bg-gradient-to-r from-indigo-500 to-violet-500"></div>
            </div>
        </div>
    </div>
</div>

<div class="min-h-screen flex flex-col items-center py-10 px-4 sm:px-6 pb-28">

    <header class="w-full max-w-2xl mb-8 text-center">
        <div class="inline-flex items-center justify-center gap-3 mb-3">
            <div class="p-2.5 rounded-2xl bg-indigo-100 dark:bg-indigo-600/15 border border-indigo-300/50 dark:border-indigo-500/25 shadow-lg shadow-indigo-200/50 dark:shadow-indigo-900/20">
                <svg class="w-6 h-6 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3 21h18M6.75 10.5a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/>
                </svg>
            </div>
            <h1 class="text-3xl sm:text-4xl font-bold tracking-tight text-transparent bg-clip-text bg-gradient-to-r from-indigo-600 via-violet-600 to-purple-600 dark:from-indigo-400 dark:via-violet-400 dark:to-purple-400">
                Image Resizer
            </h1>
        </div>
        <p class="text-slate-500 dark:text-slate-400 text-sm sm:text-base">
            Resize, convert, flip and rotate images for social media or custom dimensions.
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
                    <p class="text-amber-700 dark:text-amber-300/90 text-sm"><strong class="font-semibold">Imagick unavailable:</strong> WebP, TIFF, and HEIC are not supported. JPG, PNG, and BMP only.</p>
                </div>
                <?php endif; ?>
                <?php if ($phpLimitMB < $appLimitMB): ?>
                <div class="flex gap-3 bg-sky-50 dark:bg-sky-500/10 border border-sky-200 dark:border-sky-500/20 rounded-2xl p-4">
                    <svg class="w-5 h-5 text-sky-500 dark:text-sky-400 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p class="text-sky-700 dark:text-sky-300/90 text-sm"><strong class="font-semibold">Server limit:</strong> PHP restricts uploads to <?php echo $phpLimitMB; ?> MB per file.</p>
                </div>
                <?php endif; ?>
                <div class="flex justify-end">
                    <a href="convert.php" class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-400 dark:text-slate-500 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors py-1">
                        Format Converter
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                    </a>
                </div>
            </div>

            <form id="resizerForm" method="POST" enctype="multipart/form-data" class="p-6 space-y-7" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES); ?>">

                <section class="space-y-3">
                    <p class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">Upload</p>
                    <div id="dropZone"
                         class="flex flex-col items-center justify-center gap-4 border-2 border-dashed
                                border-slate-300 dark:border-slate-700/70 rounded-2xl px-6 py-10 text-center cursor-pointer
                                transition-all duration-200
                                hover:border-indigo-400 dark:hover:border-indigo-500/50
                                hover:bg-indigo-50/60 dark:hover:bg-indigo-950/20
                                bg-slate-50/50 dark:bg-slate-800/20">
                        <div class="p-4 rounded-2xl bg-slate-100 dark:bg-slate-700/50">
                            <svg class="w-8 h-8 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>
                            </svg>
                        </div>
                        <div>
                            <p class="text-slate-600 dark:text-slate-300 text-sm font-medium">
                                Drop images here, or <span class="text-indigo-600 dark:text-indigo-400 underline underline-offset-2">browse</span>
                            </p>
                            <p class="text-slate-400 dark:text-slate-600 text-xs mt-1.5">
                                Up to <?php echo MAX_FILES; ?> files &middot; Max <?php echo $effectiveLimitMB; ?> MB each &middot;
                                JPG, PNG, BMP<?php echo IMAGICK_AVAILABLE ? ', WebP, TIFF, HEIC' : ''; ?>
                            </p>
                        </div>
                        <input id="fileInput" type="file" name="images[]" accept="image/*" multiple required class="hidden">
                    </div>
                    <div id="previewGrid" class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 gap-2 hidden"></div>
                    <p id="fileCount" class="text-slate-400 dark:text-slate-500 text-xs hidden"></p>
                </section>

                <div class="border-t border-slate-200 dark:border-slate-800"></div>

                <section class="space-y-4">
                    <p class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">Dimensions</p>
                    <div>
                        <label for="preset" class="block text-sm text-slate-600 dark:text-slate-400 mb-2">Social Media Preset</label>
                        <select id="preset" onchange="applyPreset()"
                                class="w-full bg-white dark:bg-slate-800/80 border border-slate-300 dark:border-slate-700/60
                                       text-slate-700 dark:text-slate-200 rounded-xl px-3 py-2.5 text-sm
                                       focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500 transition-colors">
                            <option value="">— Choose a preset —</option>
                            <optgroup label="Facebook">
                                <option value="1200x630">Facebook Post (1200x630)</option>
                                <option value="820x312">Facebook Cover (820x312)</option>
                            </optgroup>
                            <optgroup label="Instagram">
                                <option value="1080x1080">Instagram Square (1080x1080)</option>
                                <option value="1080x1350">Instagram Portrait (1080x1350)</option>
                                <option value="1080x608">Instagram Landscape (1080x608)</option>
                            </optgroup>
                            <optgroup label="Twitter / X">
                                <option value="1024x512">Twitter Post (1024x512)</option>
                                <option value="1500x500">Twitter Header (1500x500)</option>
                            </optgroup>
                            <optgroup label="LinkedIn">
                                <option value="1200x627">LinkedIn Post (1200x627)</option>
                                <option value="1584x396">LinkedIn Cover (1584x396)</option>
                            </optgroup>
                            <optgroup label="YouTube">
                                <option value="1280x720">YouTube Thumbnail (1280x720)</option>
                                <option value="2560x1440">YouTube Channel Art (2560x1440)</option>
                            </optgroup>
                            <optgroup label="TikTok">
                                <option value="1080x1920">TikTok (1080x1920)</option>
                            </optgroup>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="width" class="block text-sm text-slate-600 dark:text-slate-400 mb-2">Width <span class="text-slate-400 dark:text-slate-600 text-xs">(px)</span></label>
                            <input type="number" id="width" name="width" placeholder="1920" min="1" max="10000"
                                   class="w-full bg-white dark:bg-slate-800/80 border border-slate-300 dark:border-slate-700/60
                                          text-slate-800 dark:text-slate-200 placeholder-slate-400 dark:placeholder-slate-600
                                          rounded-xl px-3 py-2.5 text-sm
                                          focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500 transition-colors">
                        </div>
                        <div>
                            <label for="height" class="block text-sm text-slate-600 dark:text-slate-400 mb-2">Height <span class="text-slate-400 dark:text-slate-600 text-xs">(px)</span></label>
                            <input type="number" id="height" name="height" placeholder="1080" min="1" max="10000"
                                   class="w-full bg-white dark:bg-slate-800/80 border border-slate-300 dark:border-slate-700/60
                                          text-slate-800 dark:text-slate-200 placeholder-slate-400 dark:placeholder-slate-600
                                          rounded-xl px-3 py-2.5 text-sm
                                          focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500 transition-colors">
                        </div>
                    </div>
                    <p id="origDimensions" class="text-slate-400 dark:text-slate-600 text-xs hidden"></p>
                    <label class="flex items-center gap-3 cursor-pointer select-none">
                        <div class="relative flex-shrink-0">
                            <input id="aspectBox" type="checkbox" name="maintainAspectRatio" class="sr-only peer">
                            <div class="w-10 h-6 bg-slate-300 dark:bg-slate-700 rounded-full transition-colors duration-200 peer-checked:bg-indigo-600"></div>
                            <div class="absolute top-1 left-1 w-4 h-4 bg-white rounded-full shadow-sm transition-transform duration-200 peer-checked:translate-x-4 pointer-events-none"></div>
                        </div>
                        <span class="text-sm text-slate-600 dark:text-slate-300">Maintain Aspect Ratio</span>
                    </label>
                </section>

                <div class="border-t border-slate-200 dark:border-slate-800"></div>

                <section class="space-y-4">
                    <p class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">Output</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div>
                            <label for="output_format" class="block text-sm text-slate-600 dark:text-slate-400 mb-2">Format</label>
                            <select id="output_format" name="output_format" onchange="toggleQuality()"
                                    class="w-full bg-white dark:bg-slate-800/80 border border-slate-300 dark:border-slate-700/60
                                           text-slate-700 dark:text-slate-200 rounded-xl px-3 py-2.5 text-sm
                                           focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500 transition-colors">
                                <option value="original">Original (keep as-is)</option>
                                <option value="jpeg">JPEG</option>
                                <option value="png">PNG (lossless)</option>
                                <option value="webp">WebP</option>
                            </select>
                        </div>
                        <div id="qualityRow">
                            <label for="quality" class="flex items-center justify-between text-sm text-slate-600 dark:text-slate-400 mb-2">
                                <span>Quality</span>
                                <span class="text-indigo-600 dark:text-indigo-400 font-semibold tabular-nums"><span id="qualityVal"><?php echo JPEG_QUALITY; ?></span>%</span>
                            </label>
                            <input type="range" id="quality" name="quality"
                                   min="1" max="100" value="<?php echo JPEG_QUALITY; ?>"
                                   oninput="document.getElementById('qualityVal').textContent = this.value"
                                   class="w-full mt-3">
                            <div class="flex justify-between text-xs text-slate-400 dark:text-slate-700 mt-1.5">
                                <span>Smaller</span><span>Better</span>
                            </div>
                        </div>
                    </div>
                </section>

                <div class="border-t border-slate-200 dark:border-slate-800"></div>

                <section class="space-y-4">
                    <p class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">Transform</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="rotation" class="block text-sm text-slate-600 dark:text-slate-400 mb-2">Rotation</label>
                            <select id="rotation" name="rotation"
                                    class="w-full bg-white dark:bg-slate-800/80 border border-slate-300 dark:border-slate-700/60
                                           text-slate-700 dark:text-slate-200 rounded-xl px-3 py-2.5 text-sm
                                           focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500 transition-colors">
                                <option value="0">None</option>
                                <option value="45">45 degrees</option>
                                <option value="90">90 degrees clockwise</option>
                                <option value="180">180 degrees</option>
                                <option value="270">270 degrees clockwise</option>
                            </select>
                        </div>
                        <div>
                            <label for="flip" class="block text-sm text-slate-600 dark:text-slate-400 mb-2">Flip</label>
                            <select id="flip" name="flip"
                                    class="w-full bg-white dark:bg-slate-800/80 border border-slate-300 dark:border-slate-700/60
                                           text-slate-700 dark:text-slate-200 rounded-xl px-3 py-2.5 text-sm
                                           focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500 transition-colors">
                                <option value="none">None</option>
                                <option value="h">Horizontal (mirror)</option>
                                <option value="v">Vertical</option>
                                <option value="both">Both</option>
                            </select>
                        </div>
                    </div>
                </section>

                <div class="border-t border-slate-200 dark:border-slate-800"></div>

                <section class="space-y-4">
                    <p class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">Enhancements</p>
                    <div class="flex flex-col sm:flex-row gap-5">
                        <label class="flex items-center gap-3 cursor-pointer select-none">
                            <div class="relative flex-shrink-0">
                                <input type="checkbox" name="grayscale" class="sr-only peer">
                                <div class="w-10 h-6 bg-slate-300 dark:bg-slate-700 rounded-full transition-colors duration-200 peer-checked:bg-indigo-600"></div>
                                <div class="absolute top-1 left-1 w-4 h-4 bg-white rounded-full shadow-sm transition-transform duration-200 peer-checked:translate-x-4 pointer-events-none"></div>
                            </div>
                            <span class="text-sm text-slate-600 dark:text-slate-300">Grayscale / B&amp;W</span>
                        </label>
                        <label class="flex items-center gap-3 cursor-pointer select-none">
                            <div class="relative flex-shrink-0">
                                <input type="checkbox" name="sharpen" class="sr-only peer">
                                <div class="w-10 h-6 bg-slate-300 dark:bg-slate-700 rounded-full transition-colors duration-200 peer-checked:bg-indigo-600"></div>
                                <div class="absolute top-1 left-1 w-4 h-4 bg-white rounded-full shadow-sm transition-transform duration-200 peer-checked:translate-x-4 pointer-events-none"></div>
                            </div>
                            <span class="text-sm text-slate-600 dark:text-slate-300">Sharpen after resize</span>
                        </label>
                    </div>
                </section>

                <p id="validationError" class="text-red-500 dark:text-red-400 text-sm hidden"></p>

                <button type="submit" id="submitBtn"
                        class="w-full py-3.5 px-6 rounded-2xl font-semibold text-sm text-white
                               bg-gradient-to-r from-indigo-600 to-violet-600
                               hover:from-indigo-500 hover:to-violet-500
                               shadow-lg shadow-indigo-500/20 dark:shadow-indigo-950/60
                               transition-all duration-200 hover:scale-[1.015] active:scale-[0.99]
                               disabled:opacity-40 disabled:cursor-not-allowed disabled:scale-100 disabled:shadow-none">
                    Resize Image(s)
                    <span class="ml-2 text-xs opacity-60 hidden sm:inline">(Ctrl+Enter)</span>
                </button>
            </form>

            <div id="result" class="hidden">
                <?php echo $resp; ?>
            </div>

            <div id="waiting" class="hidden px-6 pb-6 flex justify-center">
                <div class="inline-flex items-center gap-3 px-5 py-3 rounded-2xl
                            bg-slate-100 dark:bg-slate-800/70 border border-slate-200 dark:border-slate-700/50
                            text-slate-600 dark:text-slate-300 text-sm">
                    <svg class="animate-spin w-5 h-5 text-indigo-500 dark:text-indigo-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Processing your image(s)&hellip;
                </div>
            </div>
        </div>

        <!-- Recent images panel -->
        <div id="recentSection" class="mt-5 w-full hidden">
            <div class="bg-white/90 dark:bg-slate-900/60 backdrop-blur-2xl
                        border border-slate-200 dark:border-slate-700/40
                        rounded-3xl shadow-xl shadow-slate-300/40 dark:shadow-black/60 overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-slate-800">
                    <div class="flex items-center gap-2.5">
                        <svg class="w-4 h-4 text-indigo-500 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Recent Images</h2>
                        <span id="recentBadge" class="text-xs bg-indigo-100 dark:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400 px-2 py-0.5 rounded-full font-medium"></span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-xs text-slate-400 dark:text-slate-600 hidden sm:inline">Links expire after <?php echo intval(PURGE_MAX_AGE / 60); ?>min</span>
                        <button onclick="clearAllRecent()" class="text-xs text-slate-400 dark:text-slate-500 hover:text-red-500 dark:hover:text-red-400 transition-colors font-medium">Clear all</button>
                        <button onclick="toggleRecentPanel()" class="p-1 rounded-lg text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                            <svg id="recentChevron" class="w-4 h-4 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5"/>
                            </svg>
                        </button>
                    </div>
                </div>
                <div id="recentContent">
                    <ul id="recentList" class="px-4 py-3 space-y-2"></ul>
                    <p id="recentEmpty" class="hidden px-6 py-5 text-center text-sm text-slate-400 dark:text-slate-600">No recent images yet.</p>
                </div>
            </div>
        </div>

        <footer class="mt-6 text-center">
            <p class="text-slate-400 dark:text-slate-800 text-xs">Powered by <a href="https://spindlecrank.com" class="hover:text-slate-600 transition-colors" target="_blank" rel="noopener">spindlecrank.com</a></p>
        </footer>
    </main>
</div>

<script>
// ═══════════════════════════════════════════════════════════
// Theme
// ═══════════════════════════════════════════════════════════
function toggleTheme() {
    const isDark = document.documentElement.classList.contains('dark');
    document.body.classList.add('transitioning');
    if (isDark) {
        document.documentElement.classList.remove('dark');
        localStorage.setItem('imgr_theme', 'light');
    } else {
        document.documentElement.classList.add('dark');
        localStorage.setItem('imgr_theme', 'dark');
    }
    setTimeout(() => document.body.classList.remove('transitioning'), 300);
}

// ═══════════════════════════════════════════════════════════
// Notification
// ═══════════════════════════════════════════════════════════
let _dismissTimer = null;

function showNotification(href, label, count, skipped, errors) {
    document.getElementById('notifLabel').textContent = label;
    document.getElementById('notifDownloadLabel').textContent = label;

    const errBox = document.getElementById('notifErrors');
    if (skipped > 0 && errors.length) {
        document.getElementById('notifErrHead').textContent = skipped + ' file(s) skipped:';
        const ul = document.getElementById('notifErrList');
        ul.innerHTML = '';
        errors.forEach(e => { const li = document.createElement('li'); li.textContent = e; ul.appendChild(li); });
        errBox.classList.remove('hidden');
    } else {
        errBox.classList.add('hidden');
    }

    document.getElementById('notifDownload').onclick = () => triggerDownload(href, label, count);

    const bar = document.getElementById('notifBar');
    bar.style.animation = 'none';
    void bar.offsetWidth;
    bar.style.width = '100%';
    bar.style.animation = 'countdown 30s linear forwards';

    const wrap = document.getElementById('notifWrap');
    wrap.classList.remove('notif-in');
    wrap.classList.add('notif-out');
    void wrap.offsetWidth;
    wrap.classList.remove('notif-out');
    wrap.classList.add('notif-in');

    clearTimeout(_dismissTimer);
    _dismissTimer = setTimeout(dismissNotification, 30000);
}

function dismissNotification() {
    clearTimeout(_dismissTimer);
    const wrap = document.getElementById('notifWrap');
    wrap.classList.remove('notif-in');
    wrap.classList.add('notif-out');
    document.getElementById('result').innerHTML = '';
}

function triggerDownload(href, label, count) {
    window.location.href = href;
    addToRecent(href, label, count);
    dismissNotification();
    resetForm();
}

function resetForm() {
    document.getElementById('resizerForm').reset();
    const grid = document.getElementById('previewGrid');
    grid.innerHTML = '';
    grid.classList.add('hidden');
    document.getElementById('fileCount').classList.add('hidden');
    document.getElementById('origDimensions').classList.add('hidden');
    origW = 0; origH = 0;
    toggleQuality();
}

// ═══════════════════════════════════════════════════════════
// Recent files
// ═══════════════════════════════════════════════════════════
const RECENT_KEY = 'imgr_recent';
const RECENT_MAX = 12;
let _recentOpen  = true;

function loadRecent() {
    try { return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); }
    catch(e) { return []; }
}
function saveRecent(items) { localStorage.setItem(RECENT_KEY, JSON.stringify(items.slice(0, RECENT_MAX))); }

function addToRecent(href, label, count) {
    const items = loadRecent();
    const id = Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
    items.unshift({ id, href, label, count, ts: Date.now() });
    saveRecent(items);
    renderRecent();
}

function removeFromRecent(id) { saveRecent(loadRecent().filter(i => i.id !== id)); renderRecent(); }

function clearAllRecent() { localStorage.removeItem(RECENT_KEY); renderRecent(); }

function toggleRecentPanel() {
    _recentOpen = !_recentOpen;
    document.getElementById('recentContent').style.display = _recentOpen ? '' : 'none';
    document.getElementById('recentChevron').style.transform = _recentOpen ? '' : 'rotate(180deg)';
}

function relTime(ts) {
    const s = Math.floor((Date.now() - ts) / 1000);
    if (s < 5)    return 'just now';
    if (s < 60)   return s + 's ago';
    if (s < 3600) return Math.floor(s / 60) + 'm ago';
    if (s < 86400) return Math.floor(s / 3600) + 'h ago';
    return Math.floor(s / 86400) + 'd ago';
}

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderRecent() {
    const items   = loadRecent();
    const section = document.getElementById('recentSection');
    const list    = document.getElementById('recentList');
    const empty   = document.getElementById('recentEmpty');
    const badge   = document.getElementById('recentBadge');
    if (!items.length) { section.classList.add('hidden'); return; }
    section.classList.remove('hidden');
    badge.textContent = items.length;
    list.innerHTML = '';
    empty.classList.add('hidden');
    items.forEach((item, idx) => {
        const expired = (Date.now() - item.ts) > SERVER.expiryMs;
        const li = document.createElement('li');
        li.className = 'recent-item group flex items-center gap-3 p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700/50';
        li.style.animationDelay = (idx * 0.04) + 's';
        const iconColor  = expired ? 'text-slate-400 dark:text-slate-600'   : 'text-indigo-500 dark:text-indigo-400';
        const iconBg     = expired ? 'bg-slate-100 dark:bg-slate-700/40'    : 'bg-indigo-50 dark:bg-indigo-500/10';
        const nameColor  = expired ? 'text-slate-400 dark:text-slate-500'   : 'text-slate-700 dark:text-slate-200';
        const action = expired
            ? `<span class="flex-shrink-0 text-xs font-medium bg-slate-100 dark:bg-slate-700/50 text-slate-400 dark:text-slate-500 px-2.5 py-1 rounded-xl">Expired</span>`
            : `<a href="${escHtml(item.href)}" class="flex-shrink-0 inline-flex items-center gap-1.5 text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-500 px-3 py-1.5 rounded-xl transition-all duration-150 hover:scale-105 active:scale-100"><svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>Download</a>`;
        li.innerHTML = `
            <div class="flex-shrink-0 w-9 h-9 rounded-xl ${iconBg} flex items-center justify-center">
                <svg class="w-4 h-4 ${iconColor}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3 21h18M6.75 10.5a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium ${nameColor} truncate" title="${escHtml(item.label)}">${escHtml(item.label)}</p>
                <p class="text-xs text-slate-400 dark:text-slate-600 mt-0.5">${relTime(item.ts)}${item.count > 1 ? ' &middot; ' + item.count + ' files' : ''}</p>
            </div>
            ${action}
            <button onclick="removeFromRecent('${item.id}')"
                    class="opacity-0 group-hover:opacity-100 flex-shrink-0 w-7 h-7 flex items-center justify-center rounded-xl text-slate-400 hover:text-red-500 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 transition-all duration-150">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>`;
        list.appendChild(li);
    });
}

// ═══════════════════════════════════════════════════════════
// Form
// ═══════════════════════════════════════════════════════════
let origW = 0, origH = 0;

function applyPreset() {
    const v = document.getElementById('preset').value;
    if (!v) return;
    const [w, h] = v.split('x');
    document.getElementById('width').value  = w;
    document.getElementById('height').value = h;
    origW = 0; origH = 0;
}

function toggleQuality() {
    const fmt = document.getElementById('output_format').value;
    document.getElementById('qualityRow').style.display = (fmt === 'png') ? 'none' : '';
}
toggleQuality();

const dropZone  = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
dropZone.addEventListener('click', () => fileInput.click());
['dragenter','dragover'].forEach(ev =>
    dropZone.addEventListener(ev, e => { e.preventDefault(); dropZone.classList.add('drop-zone-active'); })
);
['dragleave','drop'].forEach(ev =>
    dropZone.addEventListener(ev, () => dropZone.classList.remove('drop-zone-active'))
);
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    if (e.dataTransfer?.files.length) { fileInput.files = e.dataTransfer.files; handleFiles(fileInput.files); }
});
fileInput.addEventListener('change', () => handleFiles(fileInput.files));

function handleFiles(files) {
    const grid    = document.getElementById('previewGrid');
    const countEl = document.getElementById('fileCount');
    const origEl  = document.getElementById('origDimensions');
    grid.innerHTML = ''; origW = 0; origH = 0; origEl.classList.add('hidden');
    if (!files.length) { grid.classList.add('hidden'); countEl.classList.add('hidden'); return; }
    const bytes = Array.from(files).reduce((s,f) => s+f.size, 0);
    countEl.textContent = files.length + ' file(s) · ' + (bytes < 1048576 ? (bytes/1024).toFixed(1)+' KB' : (bytes/1048576).toFixed(2)+' MB') + ' total';
    countEl.classList.remove('hidden');
    grid.classList.remove('hidden');
    Array.from(files).forEach((file, idx) => {
        const reader = new FileReader();
        reader.onload = ev => {
            const img = new Image();
            img.onload = () => {
                if (idx === 0) { origW = img.naturalWidth; origH = img.naturalHeight; origEl.textContent = 'Original: ' + origW + 'x' + origH + ' px'; origEl.classList.remove('hidden'); }
                const card  = document.createElement('div');
                card.className = 'relative bg-slate-100 dark:bg-slate-800 rounded-xl overflow-hidden';
                const thumb = document.createElement('img');
                thumb.src = ev.target.result; thumb.className = 'w-full h-20 object-cover'; thumb.alt = file.name;
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

document.getElementById('width').addEventListener('input', function () {
    if (!document.getElementById('aspectBox').checked || !origW || !origH) return;
    const w = parseInt(this.value, 10);
    if (w > 0) document.getElementById('height').value = Math.round(w * origH / origW);
});
document.getElementById('height').addEventListener('input', function () {
    if (!document.getElementById('aspectBox').checked || !origW || !origH) return;
    const h = parseInt(this.value, 10);
    if (h > 0) document.getElementById('width').value = Math.round(h * origW / origH);
});

document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        const btn = document.getElementById('submitBtn');
        if (!btn.disabled) btn.click();
    }
});

document.getElementById('resizerForm').addEventListener('submit', function (e) {
    const errEl = document.getElementById('validationError');
    errEl.classList.add('hidden'); errEl.textContent = '';
    const files  = fileInput.files;
    const width  = parseInt(document.getElementById('width').value, 10);
    const height = parseInt(document.getElementById('height').value, 10);
    if (!files || !files.length) { e.preventDefault(); errEl.textContent = 'Please select at least one image.'; errEl.classList.remove('hidden'); return; }
    if (files.length > SERVER.maxFiles) { e.preventDefault(); errEl.textContent = 'Too many files. Maximum is ' + SERVER.maxFiles + '.'; errEl.classList.remove('hidden'); return; }
    if (!width || !height || width < 1 || height < 1 || width > 10000 || height > 10000) { e.preventDefault(); errEl.textContent = 'Please enter valid dimensions (1-10000 px).'; errEl.classList.remove('hidden'); return; }
    document.getElementById('waiting').classList.remove('hidden');
    document.getElementById('submitBtn').disabled = true;
    document.title = 'Processing... - Image Resizer';
});

document.addEventListener('DOMContentLoaded', function () {
    const sd = document.getElementById('successData');
    if (sd) {
        const href    = sd.dataset.href;
        const label   = sd.dataset.label;
        const count   = parseInt(sd.dataset.count, 10);
        const skipped = parseInt(sd.dataset.skipped, 10);
        const errors  = Array.from(document.querySelectorAll('#errorsList li')).map(li => li.textContent);
        showNotification(href, label, count, skipped, errors);
        document.title = 'Done! - Image Resizer';
    }
    renderRecent();
});

setInterval(renderRecent, 30000);
</script>
</body>
</html>
