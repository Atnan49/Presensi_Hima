<?php
// ============================================
// config.php - Konfigurasi Database & App
// Siap untuk Localhost & Produksi (Hostinger)
// ============================================

// --- Baca file .env jika tersedia di root ---
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
        }
    }
}

// --- Pengaturan Database (Prioritas: Environment Variable / Fallback Default) ---
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'presensi');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_PORT', getenv('DB_PORT') ? (int)getenv('DB_PORT') : 3306);

// --- Pengaturan Aplikasi ---
define('APP_NAME', 'Sistem Presensi Mahasiswa');
define('APP_VERSION', '1.1.0');

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

// --- Koneksi Database (PDO) dengan Auto-Setup Database & Tabel ---
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
            // Pastikan seluruh tabel otomatis tersedia
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
                    ensureDatabaseTables($pdo);
                    return $pdo;
                } catch (Exception $ex) {
                    // Fallback jika tidak punya akses create database
                }
            }

            // Jika dipanggil dari API json
            if (strpos($_SERVER['REQUEST_URI'] ?? '', 'api/') !== false) {
                http_response_code(500);
                die(json_encode(['success' => false, 'message' => 'Database Connection Error: ' . $e->getMessage()]));
            }

            // Jika dipanggil dari halaman web, throw exception agar ditangani oleh try-catch di login.php / index.php
            throw new Exception("Gagal terhubung ke database MySQL (" . DB_HOST . ":" . DB_PORT . "). Detail: " . $e->getMessage());
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
        } catch (Exception $e) {
            // Kolom mungkin sudah ada atau DDL terbatas
        }

        // Inisialisasi event default jika belum ada acara aktif
        $eventCount = $pdo->query("SELECT COUNT(*) FROM `events`")->fetchColumn();
        if ($eventCount == 0) {
            $pdo->exec("INSERT INTO `events` (`name`, `description`, `event_date`, `is_active`) VALUES ('Kegiatan HIMA Umum', 'Presensi kegiatan reguler / program kerja HIMA', CURDATE(), 1)");
        }

        // 6. Inisialisasi admin default jika kosong
        $count = $pdo->query("SELECT COUNT(*) FROM `admins`")->fetchColumn();
        if ($count == 0) {
            $defaultHash = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO `admins` (`username`, `password_hash`, `name`) VALUES (?, ?, ?)");
            $stmt->execute(['admin', $defaultHash, 'Administrator Presensi']);
        }
    } catch (Exception $e) {
        // Abaikan jika database user tidak memiliki privilege DDL tertentu
    }
}

// --- Manajemen Sesi Autentikasi yang Aman ---
function startSessionSafe() {
    if (session_status() === PHP_SESSION_NONE) {
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        session_set_cookie_params([
            'lifetime' => 86400 * 7, // 7 hari
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        session_start();
    }
}

// --- CSRF Token Protection Helpers ---
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
}

// --- Helper: Set Header CORS untuk API ---
function setCorsHeaders() {
    header('Content-Type: application/json; charset=UTF-8');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowedHosts = ['localhost', '127.0.0.1'];
    $originHost = parse_url($origin, PHP_URL_HOST);
    
    if ($origin && ($originHost === $_SERVER['SERVER_NAME'] || in_array($originHost, $allowedHosts))) {
        header("Access-Control-Allow-Origin: $origin");
    } else {
        header('Access-Control-Allow-Origin: *');
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
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

