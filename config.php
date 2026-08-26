<?php
// ============================================
// config.php - Konfigurasi Database & App
// ============================================

// --- Pengaturan Database (Laragon Default) ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'presensi');
define('DB_USER', 'root');
define('DB_PASS', '');          // Laragon default: password kosong
define('DB_PORT', 3306);

// --- Pengaturan Aplikasi ---
define('APP_NAME', 'Sistem Presensi Mahasiswa');
define('APP_VERSION', '1.0.0');

// --- Timezone ---
date_default_timezone_set('Asia/Jakarta');

// --- Koneksi Database (PDO) ---
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

// --- Helper: Set Header CORS untuk API ---
function setCorsHeaders() {
    header('Content-Type: application/json; charset=UTF-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

// --- Helper: Kirim JSON Response ---
function sendJSON($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
