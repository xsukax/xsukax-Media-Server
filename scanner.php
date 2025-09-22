<?php
function scanMediaFolders() {
    $results = ['added' => [], 'updated' => [], 'deleted' => [], 'errors' => []];
    
    $deletedCount = cleanupOrphanedRecords();
    if ($deletedCount > 0) {
        $results['deleted'][] = "Removed $deletedCount orphaned database records";
    }
    
    $folders = [
        'movie' => MOVIES_PATH,
        'show' => SHOWS_PATH
    ];
    
    foreach ($folders as $type => $path) {
        if (is_dir($path)) {
            $folderResults = scanFolder($path, $type);
            $results['added'] = array_merge($results['added'], $folderResults['added']);
            $results['updated'] = array_merge($results['updated'], $folderResults['updated']);
            $results['errors'] = array_merge($results['errors'], $folderResults['errors']);
        } else {
            $results['errors'][] = "Folder not found: $path";
        }
    }
    
    return $results;
}

function scanFolder($folderPath, $type) {
    $results = ['added' => [], 'updated' => [], 'errors' => []];
    
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folderPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && isSupportedExtension($file->getFilename())) {
                $filePath = $file->getRealPath();
                $existingItem = getMediaByPath($filePath);
                
                $metadata = parseFilename($file->getFilename(), $filePath, $type);
                $metadata['type'] = $type;
                $metadata['file_path'] = $filePath;
                $metadata['file_size'] = $file->getSize();
                $metadata['duration'] = getVideoDuration($filePath);
                
                if ($existingItem) {
                    if ($existingItem['file_size'] !== $metadata['file_size']) {
                        if (updateMediaItem($existingItem['id'], $metadata)) {
                            $results['updated'][] = $file->getFilename();
                        }
                    }
                } else {
                    if (addMediaItem($metadata)) {
                        $results['added'][] = $file->getFilename();
                    } else {
                        $results['errors'][] = "Failed to add: " . $file->getFilename();
                    }
                }
            }
        }
    } catch (Exception $e) {
        $results['errors'][] = "Error scanning $folderPath: " . $e->getMessage();
    }
    
    return $results;
}

function parseFilename($filename, $filePath, $type) {
    if ($type === 'show') {
        return parseShowFilename($filename, $filePath);
    } else {
        return parseMovieFilename($filename, $filePath);
    }
}

function parseMovieFilename($filename, $filePath) {
    $metadata = [
        'title' => '', 'year' => null, 'season' => null, 'episode' => null,
        'show_title' => null, 'genre' => null, 'description' => null,
        'imdb_id' => null, 'poster_url' => null, 'rating' => null
    ];
    
    $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
    
    $patterns = [
        '/^(.+?)\.(\d{4})\..*$/i',
        '/^(.+?)\s*\((\d{4})\).*$/i',
        '/^(.+?)\.(\d{4})$/i',
        '/^(.+?)\s+(\d{4}).*$/i',
        '/^(.+?)\s*-\s*(\d{4}).*$/i'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $nameWithoutExt, $matches)) {
            $metadata['title'] = cleanTitle($matches[1]);
            $metadata['year'] = (int)$matches[2];
            break;
        }
    }
    
    if (empty($metadata['title'])) {
        $metadata['title'] = cleanTitle($nameWithoutExt);
        if (preg_match('/\b(\d{4})\b/', $nameWithoutExt, $matches)) {
            $year = (int)$matches[1];
            if ($year >= 1900 && $year <= date('Y') + 2) {
                $metadata['year'] = $year;
            }
        }
    }
    
    if (preg_match('/(720p|1080p|2160p|4K|BluRay|WEBRip|DVDRip)/i', $nameWithoutExt, $matches)) {
        $metadata['description'] = 'Quality: ' . strtoupper($matches[1]);
    }
    
    return $metadata;
}

function parseShowFilename($filename, $filePath) {
    $metadata = [
        'title' => '', 'year' => null, 'season' => null, 'episode' => null,
        'show_title' => null, 'genre' => null, 'description' => null,
        'imdb_id' => null, 'poster_url' => null, 'rating' => null
    ];
    
    $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
    
    $showsPath = realpath(SHOWS_PATH);
    $fileDir = dirname(realpath($filePath));
    
    $relativePath = str_replace($showsPath, '', $fileDir);
    $relativePath = trim($relativePath, DIRECTORY_SEPARATOR . '/\\');
    
    $pathParts = preg_split('/[\/\\\\]/', $relativePath);
    $pathParts = array_filter($pathParts);
    $pathParts = array_values($pathParts);
    
    if (count($pathParts) >= 1) {
        $metadata['show_title'] = cleanTitle($pathParts[0]);
        
        if (count($pathParts) >= 2) {
            $seasonFolder = $pathParts[1];
            
            if (preg_match('/season\s*(\d+)/i', $seasonFolder, $matches)) {
                $metadata['season'] = (int)$matches[1];
            } elseif (preg_match('/^s(\d+)$/i', $seasonFolder, $matches)) {
                $metadata['season'] = (int)$matches[1];
            } elseif (preg_match('/^(\d+)$/', $seasonFolder, $matches)) {
                $metadata['season'] = (int)$matches[1];
            }
        }
    }
    
    if (!$metadata['season']) {
        $metadata['season'] = 1;
    }
    
    $patterns = [
        '/^(.+?)[\.\s]+S(\d+)E(\d+)[\.\s]+(.+?)(?:\.\d+p)?(?:\..*)?$/i',
        '/^(.+?)[\.\s]+S(\d+)E(\d+)(?:[\.\s].*)?$/i',
        '/^(.+?)[\.\s]+(\d+)x(\d+)[\.\s]+(.+?)(?:\..*)?$/i',
        '/^(.+?)[\.\s]+(\d+)x(\d+)(?:[\.\s].*)?$/i',
        '/^(.+?)[\.\s]+E(\d+)[\.\s]+(.+?)(?:\..*)?$/i',
        '/^(.+?)[\.\s]+E(\d+)(?:[\.\s].*)?$/i',
        '/^(\d{1,2})[\.\s]+(.+?)(?:\..*)?$/i',
        '/^(\d{1,2})(?:[\.\s].*)?$/i'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $nameWithoutExt, $matches)) {
            if (count($matches) >= 5) {
                $metadata['season'] = (int)$matches[2];
                $metadata['episode'] = (int)$matches[3];
                $metadata['title'] = cleanTitle($matches[4]);
            } elseif (count($matches) >= 4) {
                if (isset($matches[3]) && is_numeric($matches[2]) && is_numeric($matches[3])) {
                    $metadata['season'] = (int)$matches[2];
                    $metadata['episode'] = (int)$matches[3];
                } else {
                    $metadata['episode'] = (int)$matches[2];
                    $metadata['title'] = cleanTitle($matches[3]);
                }
            } elseif (count($matches) >= 3) {
                $metadata['episode'] = (int)$matches[1];
                if (isset($matches[2])) {
                    $metadata['title'] = cleanTitle($matches[2]);
                }
            }
            break;
        }
    }
    
    if (!$metadata['episode']) {
        if (preg_match('/(?:episode|ep|e)[\s\._-]*(\d+)/i', $nameWithoutExt, $matches)) {
            $metadata['episode'] = (int)$matches[1];
        } elseif (preg_match('/(\d{1,2})/', $nameWithoutExt, $matches)) {
            $metadata['episode'] = (int)$matches[1];
        } else {
            $metadata['episode'] = 1;
        }
    }
    
    if (empty($metadata['title'])) {
        $metadata['title'] = cleanTitle($nameWithoutExt);
    }
    
    if (empty($metadata['show_title'])) {
        $metadata['show_title'] = $metadata['title'];
    }
    
    return $metadata;
}

function cleanTitle($title) {
    $title = str_replace(['.', '_', '-'], ' ', $title);
    
    $patterns = [
        '/\b(720p|1080p|2160p|4K|UHD|HDR|BluRay|BRRip|DVDRip|WEBRip|HDTV|WEB-DL)\b/i',
        '/\b(x264|x265|HEVC|h264|h265|AVC|DivX|XviD)\b/i',
        '/\b(AAC|AC3|DTS|MP3|FLAC|Atmos|5\.1|7\.1)\b/i',
        '/\b(YIFY|RARBG|PublicHD|FGT|ETRG|ShAaNiG)\b/i',
        '/\[.*?\]/',
        '/\(.*?\)(?!\s*$)/',
    ];
    
    foreach ($patterns as $pattern) {
        $title = preg_replace($pattern, ' ', $title);
    }
    
    $title = preg_replace('/\s+/', ' ', trim($title));
    $title = ucwords(strtolower($title));
    
    $title = preg_replace('/\bUs\b/', 'US', $title);
    $title = preg_replace('/\bUk\b/', 'UK', $title);
    $title = preg_replace('/\bTv\b/', 'TV', $title);
    
    return $title;
}

function getVideoDuration($filePath) {
    if (function_exists('shell_exec') && is_callable('shell_exec')) {
        $command = 'ffprobe -v quiet -show_entries format=duration -of csv="p=0" ' . escapeshellarg($filePath) . ' 2>/dev/null';
        $duration = trim(shell_exec($command));
        if ($duration && is_numeric($duration)) {
            return (int)round(floatval($duration));
        }
    }
    
    if (class_exists('getID3')) {
        try {
            $getID3 = new getID3();
            $fileInfo = $getID3->analyze($filePath);
            if (isset($fileInfo['playtime_seconds'])) {
                return (int)round($fileInfo['playtime_seconds']);
            }
        } catch (Exception $e) {
        }
    }
    
    if (function_exists('shell_exec') && is_callable('shell_exec')) {
        $command = 'mediainfo --Inform="General;%Duration%" ' . escapeshellarg($filePath) . ' 2>/dev/null';
        $duration = trim(shell_exec($command));
        if ($duration && is_numeric($duration)) {
            return (int)round($duration / 1000);
        }
    }
    
    return null;
}

function validateMediaFile($filePath) {
    return file_exists($filePath) && 
           is_readable($filePath) && 
           isPathAllowed($filePath) && 
           isSupportedExtension($filePath) && 
           filesize($filePath) <= MAX_FILE_SIZE;
}
?>