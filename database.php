<?php
function getDB() {
    static $db = null;
    if ($db === null) {
        try {
            $db = new PDO('sqlite:' . DB_PATH);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $db->exec('PRAGMA foreign_keys = ON');
            $db->exec('PRAGMA journal_mode = WAL');
        } catch (PDOException $e) {
            die('Database connection failed: ' . $e->getMessage());
        }
    }
    return $db;
}

function initDatabase() {
    $db = getDB();
    
    $sql = "CREATE TABLE IF NOT EXISTS media (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        type TEXT NOT NULL CHECK(type IN ('movie', 'show')),
        file_path TEXT NOT NULL UNIQUE,
        file_size INTEGER,
        duration INTEGER,
        year INTEGER,
        genre TEXT,
        description TEXT,
        imdb_id TEXT,
        poster_url TEXT,
        season INTEGER,
        episode INTEGER,
        show_title TEXT,
        rating REAL,
        last_played DATETIME,
        play_count INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )";
    
    $db->exec($sql);
    
    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_media_type ON media(type)",
        "CREATE INDEX IF NOT EXISTS idx_media_title ON media(title)",
        "CREATE INDEX IF NOT EXISTS idx_media_show ON media(show_title, season, episode)",
        "CREATE INDEX IF NOT EXISTS idx_media_imdb ON media(imdb_id)",
        "CREATE INDEX IF NOT EXISTS idx_media_created ON media(created_at DESC)",
        "CREATE INDEX IF NOT EXISTS idx_media_played ON media(last_played DESC)"
    ];
    
    foreach ($indexes as $index) {
        $db->exec($index);
    }
}

function addMediaItem($data) {
    $db = getDB();
    $sql = "INSERT OR REPLACE INTO media (
        title, type, file_path, file_size, duration, year, genre, 
        description, imdb_id, poster_url, season, episode, show_title, rating
    ) VALUES (
        :title, :type, :file_path, :file_size, :duration, :year, :genre,
        :description, :imdb_id, :poster_url, :season, :episode, :show_title, :rating
    )";
    
    $stmt = $db->prepare($sql);
    return $stmt->execute([
        ':title' => $data['title'],
        ':type' => $data['type'],
        ':file_path' => $data['file_path'],
        ':file_size' => $data['file_size'] ?? null,
        ':duration' => $data['duration'] ?? null,
        ':year' => $data['year'] ?? null,
        ':genre' => $data['genre'] ?? null,
        ':description' => $data['description'] ?? null,
        ':imdb_id' => $data['imdb_id'] ?? null,
        ':poster_url' => $data['poster_url'] ?? null,
        ':season' => $data['season'] ?? null,
        ':episode' => $data['episode'] ?? null,
        ':show_title' => $data['show_title'] ?? null,
        ':rating' => $data['rating'] ?? null
    ]);
}

function updateMediaItem($id, $data) {
    $db = getDB();
    $sql = "UPDATE media SET 
        title = :title, year = :year, genre = :genre, description = :description,
        imdb_id = :imdb_id, poster_url = :poster_url, rating = :rating,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = :id";
    
    $stmt = $db->prepare($sql);
    return $stmt->execute([
        ':id' => $id,
        ':title' => $data['title'],
        ':year' => $data['year'] ?? null,
        ':genre' => $data['genre'] ?? null,
        ':description' => $data['description'] ?? null,
        ':imdb_id' => $data['imdb_id'] ?? null,
        ':poster_url' => $data['poster_url'] ?? null,
        ':rating' => $data['rating'] ?? null
    ]);
}

function getMediaById($id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM media WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function getMediaByPath($path) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM media WHERE file_path = ?");
    $stmt->execute([$path]);
    return $stmt->fetch();
}

function getMediaByType($type) {
    $db = getDB();
    
    if ($type === 'movie') {
        $sql = "SELECT * FROM media WHERE type = 'movie' ORDER BY title";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    } else {
        return getShows();
    }
}

function getShows() {
    $db = getDB();
    $sql = "SELECT 
        MIN(id) as id, 
        show_title as title, 
        'show' as type, 
        MIN(file_path) as file_path,
        SUM(file_size) as file_size, 
        SUM(duration) as duration, 
        MIN(year) as year,
        GROUP_CONCAT(DISTINCT genre) as genre, 
        MIN(description) as description,
        MIN(imdb_id) as imdb_id, 
        MIN(poster_url) as poster_url,
        COUNT(*) as episode_count, 
        COUNT(DISTINCT season) as seasons, 
        AVG(rating) as rating,
        MIN(created_at) as created_at
    FROM media 
    WHERE type = 'show' AND show_title IS NOT NULL AND show_title != ''
    GROUP BY show_title
    ORDER BY show_title";
    
    $stmt = $db->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll();
}

function getShowSeasons($showTitle) {
    $db = getDB();
    $sql = "SELECT 
        season,
        MIN(id) as id,
        MIN(poster_url) as poster_url,
        COUNT(*) as episode_count,
        SUM(duration) as total_duration,
        MIN(year) as year
    FROM media 
    WHERE show_title = ? AND type = 'show' AND season IS NOT NULL
    GROUP BY show_title, season
    ORDER BY season ASC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$showTitle]);
    return $stmt->fetchAll();
}

function getSeasonEpisodes($showTitle, $season) {
    $db = getDB();
    $sql = "SELECT * FROM media 
    WHERE show_title = ? AND season = ? AND type = 'show'
    ORDER BY episode";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$showTitle, $season]);
    return $stmt->fetchAll();
}

function getRecentItems($limit = 12) {
    $db = getDB();
    
    $movies = "SELECT * FROM media WHERE type = 'movie' ORDER BY created_at DESC LIMIT " . intval($limit/2);
    $shows = "SELECT 
        MIN(id) as id, 
        show_title as title, 
        'show' as type, 
        MIN(file_path) as file_path,
        MIN(poster_url) as poster_url,
        MIN(year) as year,
        MIN(created_at) as created_at
    FROM media 
    WHERE type = 'show' AND show_title IS NOT NULL
    GROUP BY show_title
    ORDER BY created_at DESC 
    LIMIT " . intval($limit/2);
    
    $stmt = $db->prepare($movies);
    $stmt->execute();
    $movieResults = $stmt->fetchAll();
    
    $stmt = $db->prepare($shows);
    $stmt->execute();
    $showResults = $stmt->fetchAll();
    
    $combined = array_merge($movieResults, $showResults);
    usort($combined, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    return array_slice($combined, 0, $limit);
}

function getContinueWatching($limit = 6) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM media WHERE last_played IS NOT NULL ORDER BY last_played DESC LIMIT ?");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

function getLibraryStats() {
    $db = getDB();
    $stats = ['movies' => 0, 'shows' => 0, 'episodes' => 0, 'total_duration' => 0];
    
    $stmt = $db->prepare("SELECT COUNT(*) as count, SUM(duration) as duration FROM media WHERE type = 'movie'");
    $stmt->execute();
    $result = $stmt->fetch();
    $stats['movies'] = $result['count'] ?? 0;
    $stats['total_duration'] += $result['duration'] ?? 0;
    
    $stmt = $db->prepare("SELECT COUNT(DISTINCT show_title) as shows, COUNT(*) as episodes, SUM(duration) as duration FROM media WHERE type = 'show' AND show_title IS NOT NULL");
    $stmt->execute();
    $result = $stmt->fetch();
    $stats['shows'] = $result['shows'] ?? 0;
    $stats['episodes'] = $result['episodes'] ?? 0;
    $stats['total_duration'] += $result['duration'] ?? 0;
    
    return $stats;
}

function searchMedia($query, $type = null) {
    $db = getDB();
    
    if ($type === 'show') {
        $sql = "SELECT 
            MIN(id) as id, 
            show_title as title, 
            'show' as type, 
            MIN(file_path) as file_path,
            MIN(poster_url) as poster_url,
            MIN(year) as year
        FROM media 
        WHERE (show_title LIKE :query OR description LIKE :query OR genre LIKE :query) AND type = 'show'
        GROUP BY show_title
        ORDER BY show_title";
    } else {
        $sql = "SELECT * FROM media WHERE (title LIKE :query OR description LIKE :query OR genre LIKE :query)";
        if ($type) {
            $sql .= " AND type = :type";
        }
        $sql .= " ORDER BY title";
    }
    
    $params = [':query' => '%' . $query . '%'];
    if ($type && $type !== 'show') {
        $params[':type'] = $type;
    }
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function updatePlayHistory($id) {
    $db = getDB();
    $sql = "UPDATE media SET 
        last_played = CURRENT_TIMESTAMP, 
        play_count = play_count + 1 
    WHERE id = ?";
    $stmt = $db->prepare($sql);
    return $stmt->execute([$id]);
}

function cleanupOrphanedRecords() {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, file_path FROM media");
    $stmt->execute();
    $records = $stmt->fetchAll();
    
    $deletedCount = 0;
    foreach ($records as $record) {
        if (!file_exists($record['file_path'])) {
            $deleteStmt = $db->prepare("DELETE FROM media WHERE id = ?");
            $deleteStmt->execute([$record['id']]);
            $deletedCount++;
        }
    }
    
    return $deletedCount;
}

function deleteMediaItem($id) {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM media WHERE id = ?");
    return $stmt->execute([$id]);
}

function updateItemMetadata($id, $data) {
    $allowedFields = ['title', 'year', 'genre', 'description', 'imdb_id', 'poster_url', 'rating'];
    $updateData = array_intersect_key($data, array_flip($allowedFields));
    return empty($updateData) ? false : updateMediaItem($id, $updateData);
}
?>