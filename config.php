<?php
define('DB_PATH', __DIR__ . '/media.db');
define('MOVIES_PATH', __DIR__ . '/media/movies');
define('SHOWS_PATH', __DIR__ . '/media/shows');

define('SUPPORTED_EXTENSIONS', [
    'mp4', 'mkv', 'avi', 'mov', 'wmv', 'flv', 'webm', 'm4v', 'mpg', 'mpeg', 
    'ts', 'mts', 'm2ts', '3gp', 'ogv', 'asf', 'divx', 'vob', 'rmvb', 'rm',
    'f4v', 'm2v', 'mxf', 'dv', 'xvid', 'qt', 'amv', 'nsv'
]);

define('APP_NAME', 'xsukax Media Server');
define('APP_VERSION', '1.0.0');
define('MAX_FILE_SIZE', 100 * 1024 * 1024 * 1024);

define('STREAM_SECRET', 'xsukax_' . hash('md5', __DIR__));
define('STREAM_CHUNK_SIZE', 8 * 1024 * 1024);
define('ENABLE_RANGE_REQUESTS', true);
define('STREAM_CACHE_HEADERS', true);
define('SESSION_TIMEOUT', 24 * 3600);
define('ENABLE_TRANSCODING', true);

define('ALLOWED_PATHS', [MOVIES_PATH, SHOWS_PATH]);
define('DEBUG_MODE', false);

define('DEFAULT_POSTER', 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjMwMCIgdmlld0JveD0iMCAwIDIwMCAzMDAiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PHJlY3Qgd2lkdGg9IjIwMCIgaGVpZ2h0PSIzMDAiIGZpbGw9IiMzMzMiLz48dGV4dCB4PSIxMDAiIHk9IjE1MCIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjI0IiBmaWxsPSIjNjY2IiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5ObyBJbWFnZTwvdGV4dD48L3N2Zz4K');

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

date_default_timezone_set('UTC');

function createMediaDirectories() {
    foreach ([MOVIES_PATH, SHOWS_PATH] as $path) {
        if (!is_dir($path) && !mkdir($path, 0755, true)) {
            error_log("Failed to create directory: $path");
        }
    }
}

function isPathAllowed($path) {
    $realPath = realpath($path);
    if (!$realPath) return false;
    
    foreach (ALLOWED_PATHS as $allowedPath) {
        $allowedRealPath = realpath($allowedPath);
        if ($allowedRealPath && strpos($realPath, $allowedRealPath) === 0) {
            return true;
        }
    }
    return false;
}

function isSupportedExtension($filename) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, SUPPORTED_EXTENSIONS);
}

function generateStreamToken($id) {
    return hash('sha256', $id . STREAM_SECRET . date('Y-m-d-H'));
}

function getMimeType($filePath) {
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'mp4' => 'video/mp4', 'mkv' => 'video/x-matroska', 'avi' => 'video/x-msvideo',
        'mov' => 'video/quicktime', 'wmv' => 'video/x-ms-wmv', 'flv' => 'video/x-flv',
        'webm' => 'video/webm', 'm4v' => 'video/x-m4v', 'mpg' => 'video/mpeg',
        'mpeg' => 'video/mpeg', 'ts' => 'video/mp2t', 'mts' => 'video/mp2t',
        'm2ts' => 'video/mp2t', '3gp' => 'video/3gpp', 'ogv' => 'video/ogg',
        'asf' => 'video/x-ms-asf', 'divx' => 'video/divx', 'vob' => 'video/dvd',
        'rmvb' => 'application/vnd.rn-realmedia-vbr', 'rm' => 'application/vnd.rn-realmedia',
        'f4v' => 'video/x-f4v', 'm2v' => 'video/mpeg', 'mxf' => 'application/mxf',
        'dv' => 'video/dv', 'xvid' => 'video/x-msvideo', 'qt' => 'video/quicktime'
    ];
    return $mimeTypes[$extension] ?? 'video/mp4';
}

createMediaDirectories();
?>