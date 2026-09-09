<?php
// Database and application settings for local and Hostinger production environments.

if (file_exists(__DIR__ . '/.env')) {
    $envLines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($envKey, $envVal) = explode('=', $line, 2);
            $envKey = trim($envKey);
            $envVal = trim($envVal, " \t\n\r\0\x0B\"'");
            putenv("$envKey=$envVal");
            $_ENV[$envKey] = $envVal;
            $_SERVER[$envKey] = $envVal;
        }
    }
}

$readEnv = function($key, $default = '') {
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
    $val = getenv($key);
    return ($val !== false && $val !== '') ? $val : $default;
};

// Database settings (prioritizes .env, falls back to defaults)
define('DB_HOST', $readEnv('DB_HOST', 'localhost'));
define('DB_NAME', $readEnv('DB_NAME', 'presensi'));
define('DB_USER', $readEnv('DB_USER', 'root'));
define('DB_PASS', $readEnv('DB_PASS', ''));
define('DB_PORT', (int)$readEnv('DB_PORT', 3306));

// Application settings
define('APP_NAME', 'Sistem Presensi Mahasiswa');
define('APP_VERSION', '1.1.0');

// Optional API key for ESP8266 direct requests
define('DEVICE_API_KEY', $readEnv('DEVICE_API_KEY', ''));

// --- Timezone ---
date_default_timezone_set('Asia/Jakarta');

// --- Security Response Headers ---
function setSecurityHeaders() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}
setSecurityHeaders();

// PDO database connection with automatic table initialization
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
            // Synchronize MySQL timezone with Asia/Jakarta (UTC+7)
            $pdo->exec("SET time_zone = '+07:00'");
            // Verify that required tables exist
            ensureDatabaseTables($pdo);
        } catch (PDOException $e) {
            // Jika database belum dibuat (error 1049: Unknown database), buat otomatis!
            if ($e->getCode() == 1049 || strpos($e->getMessage(), 'Unknown database') !== false) {
                try {
                    $rootDsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
                    $rootPdo = new PDO($rootDsn, DB_USER, DB_PASS, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                    ]);
                    $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    
                    // Konek kembali ke database yang baru saja dibuat
                    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
                    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                    ]);
                    $pdo->exec("SET time_zone = '+07:00'");
                    ensureDatabaseTables($pdo);
                    return $pdo;
                } catch (Exception $ex) {
                    // Fallback jika tidak punya akses create database
                }
            }

            // Catat error teknis ke log internal server
            error_log("Database connection failure: " . $e->getMessage());

            // Jika dipanggil dari API json, jangan bocorkan kredensial atau detail server
            if (strpos($_SERVER['REQUEST_URI'] ?? '', 'api/') !== false) {
                http_response_code(500);
                die(json_encode(['success' => false, 'message' => 'Gagal terhubung ke database. Silakan periksa konfigurasi server.']));
            }

            // Jika dipanggil dari halaman web, lempar pesan ramah pengguna
            throw new Exception("Gagal terhubung ke database MySQL. Silakan hubungi administrator.");
        }
    }
    return $pdo;
}

// Auto-create seluruh tabel (students, attendance, unknown_cards, admins) jika belum ada
function ensureDatabaseTables($pdo) {
    try {
        // 1. Tabel students
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `students` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `uid` VARCHAR(50) NOT NULL UNIQUE,
              `name` VARCHAR(100) NOT NULL,
              `nim` VARCHAR(20) DEFAULT NULL,
              `is_active` TINYINT(1) NOT NULL DEFAULT 1,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // 2. Tabel attendance
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `attendance` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `student_id` INT(11) NOT NULL,
              `uid` VARCHAR(50) NOT NULL,
              `tap_time` DATETIME NOT NULL,
              `tap_date` DATE NOT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_student_date` (`student_id`, `tap_date`),
              KEY `idx_tap_date` (`tap_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // 3. Tabel unknown_cards
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `unknown_cards` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `uid` VARCHAR(50) NOT NULL UNIQUE,
              `first_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `last_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `tap_count` INT(11) NOT NULL DEFAULT 1,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // 4. Tabel admins
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `admins` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `username` VARCHAR(50) NOT NULL UNIQUE,
              `password_hash` VARCHAR(255) NOT NULL,
              `name` VARCHAR(100) NOT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // 5. Tabel events (Program Kerja / Acara HIMA)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `events` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `name` VARCHAR(150) NOT NULL,
              `description` TEXT DEFAULT NULL,
              `event_date` DATE NOT NULL,
              `is_active` TINYINT(1) NOT NULL DEFAULT 0,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Migration: tambahkan kolom event_id ke attendance jika belum ada
        try {
            $colCheck = $pdo->query("SHOW COLUMNS FROM `attendance` LIKE 'event_id'")->fetch();
            if (!$colCheck) {
                $pdo->exec("ALTER TABLE `attendance` ADD COLUMN `event_id` INT(11) NULL DEFAULT NULL AFTER `student_id`");
                $pdo->exec("ALTER TABLE `attendance` ADD KEY `idx_event_id` (`event_id`)");
            }
            $colSess = $pdo->query("SHOW COLUMNS FROM `attendance` LIKE 'session_id'")->fetch();
            if (!$colSess) {
                $pdo->exec("ALTER TABLE `attendance` ADD COLUMN `session_id` VARCHAR(30) DEFAULT 'sesi_1' AFTER `event_id`");
                $pdo->exec("ALTER TABLE `attendance` ADD COLUMN `session_name` VARCHAR(60) DEFAULT 'Sesi 1 (Datang)' AFTER `session_id`");
                $pdo->exec("ALTER TABLE `attendance` ADD KEY `idx_session_id` (`session_id`)");
            }
            // Tambahkan index unik untuk student_id + tap_date + session_id guna mencegah race condition double tap
            $idxCheck = $pdo->query("SHOW INDEX FROM `attendance` WHERE Key_name = 'uniq_student_date_session'")->fetch();
            if (!$idxCheck) {
                $pdo->exec("ALTER TABLE `attendance` ADD UNIQUE KEY `uniq_student_date_session` (`student_id`, `tap_date`, `session_id`)");
            }
        } catch (Exception $e) {
            // Kolom atau index mungkin sudah ada
        }

        // Inisialisasi admin & event default HANYA jika database baru di-setup (admins kosong)
        $count = $pdo->query("SELECT COUNT(*) FROM `admins`")->fetchColumn();
        if ($count == 0) {
            $defaultHash = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO `admins` (`username`, `password_hash`, `name`) VALUES (?, ?, ?)");
            $stmt->execute(['admin', $defaultHash, 'Administrator Presensi']);

            $eventCount = $pdo->query("SELECT COUNT(*) FROM `events`")->fetchColumn();
            if ($eventCount == 0) {
                $pdo->exec("INSERT INTO `events` (`name`, `description`, `event_date`, `is_active`) VALUES ('Kegiatan HIMA Umum', 'Presensi kegiatan reguler / program kerja HIMA', CURDATE(), 1)");
            }
        }
    } catch (Exception $e) {
        // Abaikan jika database user tidak memiliki privilege DDL tertentu
    }
}

// Session management with HTTPS and cookie security
function startSessionSafe() {
    if (session_status() === PHP_SESSION_NONE) {
        $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') 
               || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        session_set_cookie_params([
            'lifetime' => 86400 * 7,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        session_start();
    }
}

// CSRF token protection helpers
function generateCsrfToken() {
    startSessionSafe();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    startSessionSafe();
    return !empty($_SESSION['csrf_token']) && !empty($token) && hash_equals($_SESSION['csrf_token'], (string)$token);
}

function isLoggedIn() {
    startSessionSafe();
    return !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function getCurrentUser() {
    startSessionSafe();
    return $_SESSION['admin_user'] ?? [
        'id'       => 1,
        'username' => 'admin',
        'name'     => 'Administrator'
    ];
}

// Guard untuk halaman web (index.php)
function checkAuth() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

// Guard untuk API mutasi (POST/PUT/DELETE)
function checkApiAuth() {
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized: Sesi login diperlukan untuk melakukan tindakan ini.'
        ]);
        exit;
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
        // Validasi ketat jika token CSRF disertakan atau jika sesi aktif
        if (!empty($csrfToken) && !verifyCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Forbidden: Validasi token keamanan (CSRF) gagal.'
            ]);
            exit;
        }
    }
}

// Verifikasi akses perangkat IoT (ESP8266)
function verifyDeviceAccess() {
    $expectedKey = defined('DEVICE_API_KEY') ? DEVICE_API_KEY : '';
    // Jika kunci belum diset di .env/config, berikan akses penuh untuk kompatibilitas
    if (empty($expectedKey)) {
        return true;
    }
    $providedKey = $_SERVER['HTTP_X_DEVICE_KEY'] ?? $_GET['key'] ?? '';
    return hash_equals($expectedKey, (string)$providedKey);
}

// CORS response headers for API requests
function setCorsHeaders() {
    header('Content-Type: application/json; charset=UTF-8');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $serverHost = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $allowedHosts = ['localhost', '127.0.0.1', $serverHost];
    $originHost = parse_url($origin, PHP_URL_HOST) ?: '';
    
    if ($origin && in_array($originHost, $allowedHosts, true)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Credentials: true');
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

// Send formatted JSON response
function sendJSON($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

