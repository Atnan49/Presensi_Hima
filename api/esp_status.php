<?php
// Monitoring heartbeat status perangkat ESP8266

require_once '../config.php';
setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];

// ESP8266 POST heartbeat setiap 20 detik
if ($method === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    file_put_contents(__DIR__ . '/../esp_heartbeat.txt', time() . '|' . $ip, LOCK_EX);
    
    $activeEvent = null;
    try {
        $db = getDB();
        $activeEvent = $db->query("SELECT id, name FROM events WHERE is_active = 1 LIMIT 1")->fetch();
    } catch (Exception $e) {}

    sendJSON([
        'success'          => true,
        'message'          => 'Heartbeat received',
        'ip'               => $ip,
        'has_active_event' => !empty($activeEvent),
        'event_name'       => $activeEvent ? $activeEvent['name'] : ''
    ]);
}

// Website GET: cek apakah ESP online (heartbeat < 45 detik terakhir)
if ($method === 'GET') {
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $file = __DIR__ . '/../esp_heartbeat.txt';
    if (!file_exists($file)) {
        sendJSON(['online' => false, 'last_seen' => null, 'ip' => '']);
    }
    $content = @file_get_contents($file);
    if ($content === false || trim($content) === '') {
        sendJSON(['online' => false, 'last_seen' => null, 'ip' => '']);
    }
    [$ts, $ip] = explode('|', $content . '|');
    $lastSeen  = (int)$ts;
    $online    = $lastSeen > 0 && (time() - $lastSeen) < 45;
    sendJSON([
        'online'    => $online,
        'last_seen' => $lastSeen ? date('H:i:s', $lastSeen) : null,
        'ip'        => $ip ?? '',
    ]);
}

sendJSON(['success' => false, 'message' => 'Method tidak diizinkan'], 405);
