<?php
// ============================================
// api/esp_status.php
// Heartbeat dari ESP8266 + cek status online
// POST → ESP kirim heartbeat (tiap 20 detik)
// GET  → Website cek apakah ESP online
// ============================================

require_once '../config.php';
setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];

// ESP8266 POST heartbeat setiap 20 detik
if ($method === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'];
    file_put_contents(__DIR__ . '/../esp_heartbeat.txt', time() . '|' . $ip);
    sendJSON(['success' => true, 'message' => 'Heartbeat received', 'ip' => $ip]);
}

// Website GET: cek apakah ESP online (heartbeat < 30 detik terakhir)
if ($method === 'GET') {
    $file = __DIR__ . '/../esp_heartbeat.txt';
    if (!file_exists($file)) {
        sendJSON(['online' => false, 'last_seen' => null]);
    }
    $content   = file_get_contents($file);
    [$ts, $ip] = explode('|', $content . '|');
    $lastSeen  = (int)$ts;
    $online    = (time() - $lastSeen) < 30;
    sendJSON([
        'online'    => $online,
        'last_seen' => $lastSeen ? date('H:i:s', $lastSeen) : null,
        'ip'        => $ip ?? '',
    ]);
}

sendJSON(['success' => false, 'message' => 'Method tidak diizinkan'], 405);
