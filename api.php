<?php
// api.php - поместите в корень сайта
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

// Подключаемся к SQLite базе данных
$db = new SQLite3('osint_data.db');

// Создаем таблицы при первом запуске
$db->exec("CREATE TABLE IF NOT EXISTS visitors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip TEXT UNIQUE,
    country TEXT,
    city TEXT,
    provider TEXT,
    browser TEXT,
    os TEXT,
    screen TEXT,
    first_visit DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_visit DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_active DATETIME DEFAULT CURRENT_TIMESTAMP,
    visit_count INTEGER DEFAULT 1,
    current_page TEXT,
    completed_lessons TEXT DEFAULT '[]',
    completed_challenges TEXT DEFAULT '[]'
)");

$db->exec("CREATE TABLE IF NOT EXISTS logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    action TEXT,
    ip TEXT,
    details TEXT
)");

$action = $_GET['action'] ?? '';

if ($action === 'register') {
    $ip = $_GET['ip'] ?? '';
    $country = $_GET['country'] ?? 'Unknown';
    $city = $_GET['city'] ?? 'Unknown';
    $provider = $_GET['provider'] ?? 'Unknown';
    $browser = $_GET['browser'] ?? 'Unknown';
    $os = $_GET['os'] ?? 'Unknown';
    $screen = $_GET['screen'] ?? 'Unknown';
    
    $stmt = $db->prepare("SELECT * FROM visitors WHERE ip = :ip");
    $stmt->bindValue(':ip', $ip);
    $existing = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    
    if ($existing) {
        $update = $db->prepare("UPDATE visitors SET 
            last_visit = CURRENT_TIMESTAMP,
            last_active = CURRENT_TIMESTAMP,
            visit_count = visit_count + 1
            WHERE ip = :ip");
        $update->bindValue(':ip', $ip);
        $update->execute();
    } else {
        $insert = $db->prepare("INSERT INTO visitors 
            (ip, country, city, provider, browser, os, screen) 
            VALUES (:ip, :country, :city, :provider, :browser, :os, :screen)");
        $insert->bindValue(':ip', $ip);
        $insert->bindValue(':country', $country);
        $insert->bindValue(':city', $city);
        $insert->bindValue(':provider', $provider);
        $insert->bindValue(':browser', $browser);
        $insert->bindValue(':os', $os);
        $insert->bindValue(':screen', $screen);
        $insert->execute();
    }
    
    // Добавляем лог
    $log = $db->prepare("INSERT INTO logs (action, ip, details) VALUES ('visitor_enter', :ip, :details)");
    $log->bindValue(':ip', $ip);
    $log->bindValue(':details', "$country, $city");
    $log->execute();
    
    echo json_encode(['success' => true, 'message' => 'Visitor registered']);
}

if ($action === 'get_visitors') {
    $result = $db->query("SELECT * FROM visitors ORDER BY last_active DESC");
    $visitors = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $visitors[] = $row;
    }
    echo json_encode($visitors);
}

if ($action === 'get_logs') {
    $limit = $_GET['limit'] ?? 100;
    $result = $db->query("SELECT * FROM logs ORDER BY timestamp DESC LIMIT $limit");
    $logs = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $logs[] = $row;
    }
    echo json_encode($logs);
}

if ($action === 'update_activity') {
    $ip = $_GET['ip'] ?? '';
    $page = $_GET['page'] ?? '';
    
    $update = $db->prepare("UPDATE visitors SET 
        last_active = CURRENT_TIMESTAMP,
        current_page = :page 
        WHERE ip = :ip");
    $update->bindValue(':ip', $ip);
    $update->bindValue(':page', $page);
    $update->execute();
    
    echo json_encode(['success' => true]);
}

if ($action === 'add_log') {
    $ip = $_GET['ip'] ?? '';
    $action_text = $_GET['action_text'] ?? '';
    $details = $_GET['details'] ?? '';
    
    $log = $db->prepare("INSERT INTO logs (action, ip, details) VALUES (:action, :ip, :details)");
    $log->bindValue(':action', $action_text);
    $log->bindValue(':ip', $ip);
    $log->bindValue(':details', $details);
    $log->execute();
    
    echo json_encode(['success' => true]);
}

if ($action === 'update_lesson') {
    $ip = $_GET['ip'] ?? '';
    $lesson_id = $_GET['lesson_id'] ?? '';
    
    $result = $db->querySingle("SELECT completed_lessons FROM visitors WHERE ip = '$ip'", true);
    $completed = json_decode($result['completed_lessons'] ?? '[]', true);
    
    if (!in_array($lesson_id, $completed)) {
        $completed[] = $lesson_id;
        $update = $db->prepare("UPDATE visitors SET completed_lessons = :lessons WHERE ip = :ip");
        $update->bindValue(':lessons', json_encode($completed));
        $update->bindValue(':ip', $ip);
        $update->execute();
    }
    
    echo json_encode(['success' => true]);
}

if ($action === 'update_challenge') {
    $ip = $_GET['ip'] ?? '';
    $challenge_id = $_GET['challenge_id'] ?? '';
    
    $result = $db->querySingle("SELECT completed_challenges FROM visitors WHERE ip = '$ip'", true);
    $completed = json_decode($result['completed_challenges'] ?? '[]', true);
    
    if (!in_array($challenge_id, $completed)) {
        $completed[] = $challenge_id;
        $update = $db->prepare("UPDATE visitors SET completed_challenges = :challenges WHERE ip = :ip");
        $update->bindValue(':challenges', json_encode($completed));
        $update->bindValue(':ip', $ip);
        $update->execute();
    }
    
    echo json_encode(['success' => true]);
}

if ($action === 'clear_data' && isset($_GET['admin_key']) && $_GET['admin_key'] === 'KilledBeta908090') {
    $db->exec("DELETE FROM visitors");
    $db->exec("DELETE FROM logs");
    $db->exec("DELETE FROM sqlite_sequence WHERE name='visitors' OR name='logs'");
    echo json_encode(['success' => true, 'message' => 'All data cleared']);
}

if ($action === 'get_stats') {
    $total_visitors = $db->querySingle("SELECT COUNT(*) FROM visitors");
    $active_visitors = $db->querySingle("SELECT COUNT(*) FROM visitors WHERE julianday('now') - julianday(last_active) < 0.0035"); // последние 5 минут
    
    $total_lessons = $db->querySingle("SELECT SUM(json_array_length(completed_lessons)) FROM visitors");
    $total_challenges = $db->querySingle("SELECT SUM(json_array_length(completed_challenges)) FROM visitors");
    
    $avg_time = $db->querySingle("SELECT AVG(julianday(last_visit) - julianday(first_visit)) * 24 * 60 FROM visitors");
    
    echo json_encode([
        'total_visitors' => $total_visitors,
        'active_visitors' => $active_visitors,
        'total_lessons' => $total_lessons ?? 0,
        'total_challenges' => $total_challenges ?? 0,
        'avg_time' => round($avg_time ?? 0)
    ]);
}
?>