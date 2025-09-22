<?php
require_once 'config.php';

$file = $_GET['file'] ?? '';
$path = $_GET['path'] ?? '';

if (empty($file) || empty($path)) {
    http_response_code(400);
    exit('Invalid request');
}

$fullPath = $path . '/' . $file;

if (!file_exists($fullPath) || !is_readable($fullPath) || !isPathAllowed($fullPath)) {
    http_response_code(404);
    exit('Subtitle file not found');
}

$extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$allowedExtensions = ['srt', 'vtt', 'ass', 'ssa', 'sub'];

if (!in_array($extension, $allowedExtensions)) {
    http_response_code(400);
    exit('Invalid subtitle format');
}

$mimeTypes = [
    'srt' => 'text/srt',
    'vtt' => 'text/vtt',
    'ass' => 'text/x-ass',
    'ssa' => 'text/x-ssa',
    'sub' => 'text/x-microdvd'
];

header('Content-Type: ' . ($mimeTypes[$extension] ?? 'text/plain') . '; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=3600');

if ($extension === 'srt') {
    $content = file_get_contents($fullPath);
    $content = convertSrtToVtt($content);
    header('Content-Type: text/vtt; charset=UTF-8');
    echo $content;
} else {
    readfile($fullPath);
}

function convertSrtToVtt($srtContent) {
    $vttContent = "WEBVTT\n\n";
    $srtContent = str_replace(["\r\n", "\r"], "\n", $srtContent);
    $srtContent = preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/', '$1.$2', $srtContent);
    return $vttContent . $srtContent;
}
?>