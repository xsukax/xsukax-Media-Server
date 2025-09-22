<?php
session_start();
require_once 'config.php';
require_once 'database.php';
require_once 'scanner.php';

initDatabase();

$action = $_GET['action'] ?? 'home';
$type = $_GET['type'] ?? '';
$id = $_GET['id'] ?? '';
$query = $_GET['q'] ?? '';
$show = $_GET['show'] ?? '';
$season = $_GET['season'] ?? '';

if ($_POST) {
    if (isset($_POST['scan'])) {
        $results = scanMediaFolders();
        $_SESSION['scan_results'] = $results;
        header('Location: ?action=scan_results');
        exit;
    }
    
    if (isset($_POST['update_metadata'])) {
        updateItemMetadata($_POST['item_id'], $_POST);
        header('Location: ?action=view&type=' . $_POST['type'] . '&id=' . $_POST['item_id']);
        exit;
    }
}

if ($action === 'play' && $id) {
    updatePlayHistory($id);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>xsukax Media Server</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/video.js/8.10.0/video-js.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/video.js/8.10.0/video.min.js"></script>
</head>
<body>
    <header>
        <nav>
            <div class="nav-brand">
                <h1>🎬 xsukax</h1>
                <span class="nav-subtitle">Media Server</span>
            </div>
            <div class="nav-links">
                <a href="?action=home" class="<?= $action === 'home' ? 'active' : '' ?>">Home</a>
                <a href="?action=movies" class="<?= $action === 'movies' ? 'active' : '' ?>">Movies</a>
                <a href="?action=shows" class="<?= in_array($action, ['shows', 'show_detail', 'season_view']) ? 'active' : '' ?>">TV Shows</a>
            </div>
            <div class="nav-actions">
                <form method="get" class="search-form">
                    <input type="hidden" name="action" value="search">
                    <input type="text" name="q" placeholder="Search media..." value="<?= htmlspecialchars($query) ?>">
                    <button type="submit">🔍</button>
                </form>
                <a href="?action=scan" class="btn-scan">⟳ Scan</a>
            </div>
        </nav>
    </header>

    <main>
        <?php
        switch ($action) {
            case 'home': showHomePage(); break;
            case 'movies': showMediaList('movie'); break;
            case 'shows': showMediaList('show'); break;
            case 'show_detail': showShowDetail($show); break;
            case 'season_view': showSeasonView($show, $season); break;
            case 'view': showMediaDetails($type, $id); break;
            case 'play': showMediaPlayer($id); break;
            case 'search': showSearchResults($query); break;
            case 'scan': showScanPage(); break;
            case 'scan_results': showScanResults(); break;
            case 'edit': showEditForm($type, $id); break;
            default: showHomePage();
        }
        ?>
    </main>

    <footer>
        <p>&copy; 2025 xsukax Media Server - Stream Your World</p>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const players = document.querySelectorAll('.video-player');
            players.forEach(playerEl => {
                const player = videojs(playerEl, {
                    fluid: true,
                    responsive: true,
                    controls: true,
                    preload: 'metadata',
                    playbackRates: [0.25, 0.5, 0.75, 1, 1.25, 1.5, 1.75, 2],
                    plugins: {},
                    html5: {
                        vhs: {
                            overrideNative: true
                        }
                    }
                });

                player.ready(() => {
                    console.log('Player ready');
                });
            });

            document.addEventListener('keydown', (e) => {
                if (e.target.tagName.toLowerCase() === 'input') return;
                
                const player = videojs.getPlayers()[Object.keys(videojs.getPlayers())[0]];
                if (!player) return;

                switch(e.key) {
                    case ' ': 
                        e.preventDefault(); 
                        if (player.paused()) player.play(); 
                        else player.pause(); 
                        break;
                    case 'f': case 'F': 
                        if (player.isFullscreen()) player.exitFullscreen(); 
                        else player.requestFullscreen(); 
                        break;
                    case 'm': case 'M': 
                        player.muted(!player.muted()); 
                        break;
                    case 'ArrowLeft': 
                        player.currentTime(player.currentTime() - 10); 
                        break;
                    case 'ArrowRight': 
                        player.currentTime(player.currentTime() + 10); 
                        break;
                }
            });
        });
    </script>
</body>
</html>

<?php
function showHomePage() {
    $stats = getLibraryStats();
    $recentItems = getRecentItems(12);
    $continueWatching = getContinueWatching(6);
    ?>
    <div class="hero-section">
        <div class="hero-content">
            <h1>Welcome to Your Personal Media Server</h1>
            <p>Stream your movies and TV shows anywhere</p>
            <div class="hero-stats">
                <span><?= $stats['movies'] ?> Movies</span>
                <span><?= $stats['shows'] ?> TV Shows</span>
                <span><?= $stats['episodes'] ?> Episodes</span>
                <span><?= formatDuration($stats['total_duration']) ?> Total Runtime</span>
            </div>
        </div>
    </div>

    <div class="content-sections">
        <?php if (!empty($continueWatching)): ?>
        <section class="media-section">
            <h2>Continue Watching</h2>
            <div class="media-row">
                <?php foreach ($continueWatching as $item): ?>
                    <div class="media-card">
                        <?= renderMediaCard($item) ?>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: 30%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <section class="media-section">
            <h2>Recently Added</h2>
            <div class="media-row">
                <?php foreach ($recentItems as $item): ?>
                    <?= renderMediaCard($item) ?>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="quick-access">
            <h2>Browse Your Library</h2>
            <div class="library-grid">
                <a href="?action=movies" class="library-card">
                    <div class="library-icon">🎬</div>
                    <h3>Movies</h3>
                    <p><?= $stats['movies'] ?> titles</p>
                </a>
                <a href="?action=shows" class="library-card">
                    <div class="library-icon">📺</div>
                    <h3>TV Shows</h3>
                    <p><?= $stats['shows'] ?> series</p>
                </a>
            </div>
        </section>
    </div>
    <?php
}

function showMediaList($type) {
    $items = getMediaByType($type);
    $typeLabel = ucfirst($type) . ($type === 'movie' ? 's' : ' Shows');
    ?>
    <div class="page-header">
        <h1><?= $typeLabel ?></h1>
        <div class="view-options">
            <span class="item-count"><?= count($items) ?> <?= strtolower($typeLabel) ?></span>
        </div>
    </div>
    
    <div class="media-grid">
        <?php foreach ($items as $item): ?>
            <?= renderMediaCard($item) ?>
        <?php endforeach; ?>
    </div>
    
    <?php if (empty($items)): ?>
        <div class="empty-state">
            <div class="empty-icon">📁</div>
            <h3>No <?= strtolower($typeLabel) ?> found</h3>
            <p>Add media files to your library and scan to see them here</p>
            <a href="?action=scan" class="btn primary">Scan Library</a>
        </div>
    <?php endif; ?>
    <?php
}

function showShowDetail($showTitle) {
    if (empty($showTitle)) {
        header('Location: ?action=shows');
        exit;
    }
    
    $seasons = getShowSeasons($showTitle);
    
    if (empty($seasons)) {
        echo '<div class="container"><h2>Show not found</h2></div>';
        return;
    }
    ?>
    <div class="show-detail">
        <div class="page-header">
            <div>
                <a href="?action=shows" class="btn secondary">← Back to Shows</a>
                <h1><?= htmlspecialchars($showTitle) ?></h1>
            </div>
        </div>
        
        <div class="seasons-grid">
            <?php foreach ($seasons as $seasonData): ?>
                <div class="season-card">
                    <a href="?action=season_view&show=<?= urlencode($showTitle) ?>&season=<?= $seasonData['season'] ?>" class="season-link">
                        <div class="season-poster">
                            <img src="<?= $seasonData['poster_url'] ?: DEFAULT_POSTER ?>" alt="Season <?= $seasonData['season'] ?>">
                            <div class="season-overlay">
                                <span class="season-number">Season <?= $seasonData['season'] ?></span>
                            </div>
                        </div>
                        <div class="season-info">
                            <h3>Season <?= $seasonData['season'] ?></h3>
                            <p><?= $seasonData['episode_count'] ?> episodes</p>
                            <?php if ($seasonData['total_duration']): ?>
                                <p><?= formatDuration($seasonData['total_duration']) ?></p>
                            <?php endif; ?>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

function showSeasonView($showTitle, $season) {
    if (empty($showTitle) || empty($season)) {
        header('Location: ?action=shows');
        exit;
    }
    
    $episodes = getSeasonEpisodes($showTitle, $season);
    
    if (empty($episodes)) {
        echo '<div class="container"><h2>Season not found</h2></div>';
        return;
    }
    ?>
    <div class="season-detail">
        <div class="page-header">
            <div>
                <a href="?action=show_detail&show=<?= urlencode($showTitle) ?>" class="btn secondary">← Back to <?= htmlspecialchars($showTitle) ?></a>
                <h1><?= htmlspecialchars($showTitle) ?> - Season <?= $season ?></h1>
            </div>
        </div>
        
        <div class="episodes-list">
            <?php foreach ($episodes as $episode): ?>
                <div class="episode-card">
                    <div class="episode-poster">
                        <img src="<?= $episode['poster_url'] ?: DEFAULT_POSTER ?>" alt="Episode <?= $episode['episode'] ?>">
                        <div class="episode-overlay">
                            <a href="?action=play&id=<?= $episode['id'] ?>" class="play-btn">▶</a>
                        </div>
                    </div>
                    <div class="episode-info">
                        <div class="episode-header">
                            <span class="episode-number">S<?= str_pad($episode['episode'], 2, '0', STR_PAD_LEFT) ?></span>
                            <h3><?= htmlspecialchars($episode['title']) ?></h3>
                        </div>
                        <?php if ($episode['duration']): ?>
                            <p class="episode-duration"><?= formatDuration($episode['duration']) ?></p>
                        <?php endif; ?>
                        <?php if ($episode['description']): ?>
                            <p class="episode-description"><?= htmlspecialchars($episode['description']) ?></p>
                        <?php endif; ?>
                        <div class="episode-actions">
                            <a href="?action=play&id=<?= $episode['id'] ?>" class="btn primary">Play</a>
                            <a href="?action=view&type=show&id=<?= $episode['id'] ?>" class="btn secondary">Info</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

function showMediaDetails($type, $id) {
    $item = getMediaById($id);
    if (!$item) {
        echo '<div class="container"><h2>Media not found</h2></div>';
        return;
    }
    ?>
    <div class="media-detail">
        <div class="detail-backdrop">
            <div class="detail-content">
                <div class="detail-poster">
                    <img src="<?= $item['poster_url'] ?: DEFAULT_POSTER ?>" alt="<?= htmlspecialchars($item['title']) ?>">
                    <div class="detail-actions">
                        <a href="?action=play&id=<?= $id ?>" class="btn primary large">▶ Play</a>
                        <a href="?action=edit&type=<?= $type ?>&id=<?= $id ?>" class="btn secondary">✏ Edit</a>
                    </div>
                </div>
                <div class="detail-info">
                    <h1><?= htmlspecialchars($item['title']) ?></h1>
                    <?php if ($item['type'] === 'show' && $item['show_title']): ?>
                        <p class="show-info"><?= htmlspecialchars($item['show_title']) ?> - Season <?= $item['season'] ?>, Episode <?= $item['episode'] ?></p>
                    <?php endif; ?>
                    <div class="detail-meta">
                        <?php if ($item['year']): ?><span class="meta-badge"><?= $item['year'] ?></span><?php endif; ?>
                        <?php if ($item['genre']): ?><span class="meta-badge"><?= htmlspecialchars($item['genre']) ?></span><?php endif; ?>
                        <?php if ($item['duration']): ?><span class="meta-badge"><?= formatDuration($item['duration']) ?></span><?php endif; ?>
                        <?php if ($item['rating']): ?><span class="meta-badge">⭐ <?= number_format($item['rating'], 1) ?></span><?php endif; ?>
                    </div>
                    <?php if ($item['description']): ?>
                        <p class="detail-description"><?= nl2br(htmlspecialchars($item['description'])) ?></p>
                    <?php endif; ?>
                    <div class="detail-specs">
                        <p><strong>File:</strong> <?= htmlspecialchars(basename($item['file_path'])) ?></p>
                        <p><strong>Size:</strong> <?= formatFileSize($item['file_size']) ?></p>
                        <?php if ($item['play_count']): ?><p><strong>Played:</strong> <?= $item['play_count'] ?> times</p><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function showMediaPlayer($id) {
    $item = getMediaById($id);
    if (!$item || !validateMediaFile($item['file_path'])) {
        echo '<div class="container"><h2>Media not accessible</h2></div>';
        return;
    }
    
    $extension = strtolower(pathinfo($item['file_path'], PATHINFO_EXTENSION));
    $needsTranscoding = !in_array($extension, ['mp4', 'webm', 'ogg']);
    
    $streamUrl = 'stream.php?id=' . urlencode($id) . '&token=' . generateStreamToken($id);
    if ($needsTranscoding && ENABLE_TRANSCODING) {
        $streamUrl .= '&transcode=auto';
    }
    
    $subtitles = findSubtitles($item['file_path']);
    
    $backUrl = '?action=view&type=' . $item['type'] . '&id=' . $id;
    if ($item['type'] === 'show') {
        $backUrl = '?action=season_view&show=' . urlencode($item['show_title']) . '&season=' . $item['season'];
    }
    ?>
    <div class="player-page">
        <div class="player-header">
            <h2><?= htmlspecialchars($item['title']) ?></h2>
            <div class="player-nav">
                <a href="<?= $backUrl ?>" class="btn secondary">← Back</a>
                <?php if ($needsTranscoding): ?>
                    <span class="transcoding-info">🔄 Transcoding: <?= strtoupper($extension) ?> → MP4</span>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="player-container">
            <video class="video-player video-js vjs-theme-city" controls preload="metadata" 
                   poster="<?= $item['poster_url'] ?: DEFAULT_POSTER ?>" 
                   data-setup='{"fluid": true}'>
                <source src="<?= htmlspecialchars($streamUrl) ?>" type="video/mp4">
                <?php foreach ($subtitles as $sub): ?>
                    <track kind="subtitles" src="<?= htmlspecialchars($sub['url']) ?>" 
                           srclang="<?= htmlspecialchars($sub['lang']) ?>" 
                           label="<?= htmlspecialchars($sub['label']) ?>" 
                           <?= $sub['default'] ? 'default' : '' ?>>
                <?php endforeach; ?>
                <p>Your browser doesn't support video playback. <a href="<?= htmlspecialchars($streamUrl) ?>">Download</a> instead.</p>
            </video>
        </div>
        
        <div class="player-info">
            <div class="player-meta">
                <?php if ($item['year']): ?><span>📅 <?= $item['year'] ?></span><?php endif; ?>
                <?php if ($item['duration']): ?><span>⏱️ <?= formatDuration($item['duration']) ?></span><?php endif; ?>
                <?php if ($item['genre']): ?><span>🎭 <?= htmlspecialchars($item['genre']) ?></span><?php endif; ?>
                <span>📂 <?= strtoupper($extension) ?></span>
            </div>
            <?php if ($item['description']): ?>
                <p><?= nl2br(htmlspecialchars($item['description'])) ?></p>
            <?php endif; ?>
            
            <?php if ($needsTranscoding && !ENABLE_TRANSCODING): ?>
                <div class="format-warning">
                    <h4>⚠️ Format Compatibility Notice</h4>
                    <p>This <?= strtoupper($extension) ?> file may not play in all browsers. For best compatibility, consider converting to MP4 format.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

function showSearchResults($query) {
    $results = searchMedia($query);
    ?>
    <div class="page-header">
        <h1>Search Results</h1>
        <p>Found <?= count($results) ?> results for "<?= htmlspecialchars($query) ?>"</p>
    </div>
    
    <div class="media-grid">
        <?php foreach ($results as $item): ?>
            <?= renderMediaCard($item) ?>
        <?php endforeach; ?>
    </div>
    
    <?php if (empty($results)): ?>
        <div class="empty-state">
            <div class="empty-icon">🔍</div>
            <h3>No results found</h3>
            <p>Try a different search term</p>
        </div>
    <?php endif; ?>
    <?php
}

function showScanPage() {
    ?>
    <div class="scan-page">
        <h2>Library Scanner</h2>
        <p>Scan your media folders to update the library database</p>
        
        <div class="scan-info">
            <h3>Configured Folders:</h3>
            <ul>
                <li><strong>Movies:</strong> <?= MOVIES_PATH ?></li>
                <li><strong>TV Shows:</strong> <?= SHOWS_PATH ?></li>
            </ul>
            <p><strong>Supported formats:</strong> <?= implode(', ', SUPPORTED_EXTENSIONS) ?></p>
        </div>
        
        <form method="post">
            <button type="submit" name="scan" class="btn primary large">🔄 Start Library Scan</button>
        </form>
    </div>
    <?php
}

function showScanResults() {
    $results = $_SESSION['scan_results'] ?? [];
    unset($_SESSION['scan_results']);
    ?>
    <div class="scan-results">
        <h2>Scan Complete</h2>
        
        <?php foreach (['added', 'updated', 'deleted', 'errors'] as $section): ?>
            <?php if (!empty($results[$section])): ?>
                <div class="scan-section <?= $section ?>">
                    <h3><?= ucfirst($section) ?> (<?= count($results[$section]) ?>)</h3>
                    <ul>
                        <?php foreach ($results[$section] as $item): ?>
                            <li><?= htmlspecialchars($item) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
        
        <a href="?action=home" class="btn primary">← Return Home</a>
    </div>
    <?php
}

function showEditForm($type, $id) {
    $item = getMediaById($id);
    if (!$item) return;
    ?>
    <div class="edit-form">
        <h2>Edit Metadata</h2>
        <form method="post">
            <input type="hidden" name="item_id" value="<?= $id ?>">
            <input type="hidden" name="type" value="<?= $type ?>">
            
            <div class="form-grid">
                <div class="form-group">
                    <label>Title</label>
                    <input type="text" name="title" value="<?= htmlspecialchars($item['title']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Year</label>
                    <input type="number" name="year" value="<?= $item['year'] ?>" min="1900" max="2030">
                </div>
                <div class="form-group">
                    <label>Genre</label>
                    <input type="text" name="genre" value="<?= htmlspecialchars($item['genre']) ?>">
                </div>
                <div class="form-group">
                    <label>Rating</label>
                    <input type="number" name="rating" value="<?= $item['rating'] ?>" min="0" max="10" step="0.1">
                </div>
                <div class="form-group full-width">
                    <label>Description</label>
                    <textarea name="description" rows="4"><?= htmlspecialchars($item['description']) ?></textarea>
                </div>
                <div class="form-group">
                    <label>IMDb ID</label>
                    <input type="text" name="imdb_id" value="<?= htmlspecialchars($item['imdb_id']) ?>" placeholder="tt1234567">
                </div>
                <div class="form-group">
                    <label>Poster URL</label>
                    <input type="url" name="poster_url" value="<?= htmlspecialchars($item['poster_url']) ?>">
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" name="update_metadata" class="btn primary">Save Changes</button>
                <a href="?action=view&type=<?= $type ?>&id=<?= $id ?>" class="btn secondary">Cancel</a>
            </div>
        </form>
    </div>
    <?php
}

function renderMediaCard($item) {
    $href = $item['type'] === 'show' ? 
        "?action=show_detail&show=" . urlencode($item['title']) : 
        "?action=view&type={$item['type']}&id={$item['id']}";
    
    return "
    <div class=\"media-card\">
        <div class=\"card-poster\">
            <img src=\"" . ($item['poster_url'] ?: DEFAULT_POSTER) . "\" alt=\"" . htmlspecialchars($item['title']) . "\">
            <div class=\"card-overlay\">
                <a href=\"?action=play&id={$item['id']}\" class=\"play-btn\">▶</a>
            </div>
        </div>
        <div class=\"card-info\">
            <h3>" . htmlspecialchars($item['title']) . "</h3>
            <p class=\"card-year\">" . ($item['year'] ?: 'Unknown') . "</p>
            <div class=\"card-actions\">
                <a href=\"$href\" class=\"btn small\">Info</a>
            </div>
        </div>
    </div>";
}

function findSubtitles($videoPath) {
    $subtitles = [];
    $videoDir = dirname($videoPath);
    $videoName = pathinfo($videoPath, PATHINFO_FILENAME);
    
    $subExtensions = ['srt', 'vtt', 'ass', 'ssa', 'sub'];
    $subPatterns = [
        $videoName . '.%s',
        $videoName . '.en.%s',
        $videoName . '.eng.%s',
        $videoName . '.english.%s'
    ];
    
    foreach ($subPatterns as $pattern) {
        foreach ($subExtensions as $ext) {
            $subFile = $videoDir . '/' . sprintf($pattern, $ext);
            if (file_exists($subFile)) {
                $lang = 'en';
                $label = 'English';
                
                if (strpos($pattern, '.en.') !== false || strpos($pattern, '.eng.') !== false) {
                    $lang = 'en';
                    $label = 'English';
                }
                
                $subtitles[] = [
                    'url' => 'subtitles.php?file=' . urlencode(basename($subFile)) . '&path=' . urlencode($videoDir),
                    'lang' => $lang,
                    'label' => $label,
                    'default' => empty($subtitles)
                ];
            }
        }
    }
    
    return $subtitles;
}

function formatDuration($seconds) {
    if (!$seconds) return 'Unknown';
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    return $hours > 0 ? sprintf('%dh %dm', $hours, $minutes) : sprintf('%dm', $minutes);
}

function formatFileSize($bytes) {
    if (!$bytes) return 'Unknown';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / pow(1024, $pow), 2) . ' ' . $units[$pow];
}
?>