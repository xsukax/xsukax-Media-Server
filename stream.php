<?php
require_once 'config.php';
require_once 'database.php';

$id = $_GET['id'] ?? '';
$token = $_GET['token'] ?? '';
$transcode = $_GET['transcode'] ?? 'auto';

if (empty($id) || empty($token)) {
    http_response_code(400);
    exit('Invalid request');
}

$expectedToken = generateStreamToken($id);
if (!hash_equals($expectedToken, $token)) {
    http_response_code(403);
    exit('Invalid or expired token');
}

$item = getMediaById($id);
if (!$item) {
    http_response_code(404);
    exit('Media not found');
}

$filePath = $item['file_path'];

if (!file_exists($filePath) || !is_readable($filePath) || !isPathAllowed($filePath)) {
    http_response_code(404);
    exit('File not accessible');
}

$fileSize = filesize($filePath);
$fileName = basename($filePath);
$extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$lastModified = filemtime($filePath);

$browserSupportedFormats = ['mp4', 'webm', 'ogg'];
$needsTranscoding = !in_array($extension, $browserSupportedFormats) || $transcode === 'force';

if ($needsTranscoding && isFFmpegAvailable()) {
    streamWithTranscoding($filePath, $id);
} else {
    streamDirectly($filePath, $fileSize, $fileName, $lastModified);
}

function streamWithTranscoding($filePath, $id) {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $isMobile = preg_match('/Mobile|Android|iPhone|iPad/', $userAgent);
    
    $quality = $isMobile ? 'mobile' : 'web';
    $videoCodec = 'libx264';
    $audioCodec = 'aac';
    $videoBitrate = $isMobile ? '1000k' : '2500k';
    $audioBitrate = '128k';
    
    header('Content-Type: video/mp4');
    header('Accept-Ranges: bytes');
    header('Cache-Control: no-cache');
    header('Access-Control-Allow-Origin: *');
    
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    $cmd = 'ffmpeg -i ' . escapeshellarg($filePath) . ' ' .
           '-c:v ' . $videoCodec . ' ' .
           '-preset ultrafast ' .
           '-crf 23 ' .
           '-b:v ' . $videoBitrate . ' ' .
           '-c:a ' . $audioCodec . ' ' .
           '-b:a ' . $audioBitrate . ' ' .
           '-movflags +frag_keyframe+separate_moof+omit_tfhd_offset+empty_moov ' .
           '-f mp4 pipe:1 2>/dev/null';
    
    $process = popen($cmd, 'r');
    if ($process) {
        while (!feof($process) && connection_status() === CONNECTION_NORMAL) {
            $chunk = fread($process, STREAM_CHUNK_SIZE);
            if ($chunk === false) break;
            echo $chunk;
            flush();
        }
        pclose($process);
    } else {
        http_response_code(500);
        exit('Transcoding failed');
    }
}

function streamDirectly($filePath, $fileSize, $fileName, $lastModified) {
    $mimeType = getMimeType($filePath);
    $etag = '"' . md5($filePath . $fileSize . $lastModified) . '"';
    $clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';

    if ($clientEtag === $etag) {
        http_response_code(304);
        exit;
    }

    $range = null;
    if (ENABLE_RANGE_REQUESTS && isset($_SERVER['HTTP_RANGE'])) {
        if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
            $start = (int)$matches[1];
            $end = $matches[2] !== '' ? (int)$matches[2] : $fileSize - 1;
            $end = min($end, $fileSize - 1);
            
            if ($start <= $end && $start < $fileSize) {
                $range = [$start, $end];
            }
        }
    }

    header('Content-Type: ' . $mimeType);
    header('Accept-Ranges: bytes');
    header('Content-Disposition: inline; filename="' . addslashes($fileName) . '"');

    if (STREAM_CACHE_HEADERS) {
        header('Cache-Control: public, max-age=31536000');
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
    }

    if ($range) {
        [$start, $end] = $range;
        $contentLength = $end - $start + 1;
        
        http_response_code(206);
        header('Content-Length: ' . $contentLength);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
        
        streamRange($filePath, $start, $end);
    } else {
        header('Content-Length: ' . $fileSize);
        streamFile($filePath);
    }
}

function streamFile($filePath) {
    $handle = fopen($filePath, 'rb');
    if (!$handle) {
        http_response_code(500);
        exit('Cannot open file');
    }
    
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    while (!feof($handle) && connection_status() === CONNECTION_NORMAL) {
        $chunk = fread($handle, STREAM_CHUNK_SIZE);
        if ($chunk === false) break;
        
        echo $chunk;
        flush();
    }
    
    fclose($handle);
}

function streamRange($filePath, $start, $end) {
    $handle = fopen($filePath, 'rb');
    if (!$handle) {
        http_response_code(500);
        exit('Cannot open file');
    }
    
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    fseek($handle, $start);
    $bytesToRead = $end - $start + 1;
    
    while ($bytesToRead > 0 && !feof($handle) && connection_status() === CONNECTION_NORMAL) {
        $chunkSize = min(STREAM_CHUNK_SIZE, $bytesToRead);
        $chunk = fread($handle, $chunkSize);
        
        if ($chunk === false) break;
        
        echo $chunk;
        $bytesToRead -= strlen($chunk);
        flush();
    }
    
    fclose($handle);
}

function isFFmpegAvailable() {
    if (!function_exists('shell_exec') || !is_callable('shell_exec')) {
        return false;
    }
    
    $output = shell_exec('ffmpeg -version 2>/dev/null');
    return !empty($output) && strpos($output, 'ffmpeg version') !== false;
}
?>