<?php
// API tap kartu RFID dari perangkat IoT (ESP8266)
// Parameter: ?uid=XXXXXXXX&session_id=sesi_1

require_once '../config.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
    exit;
}

// Verifikasi kunci perangkat IoT jika DEVICE_API_KEY dikonfigurasi
if (!verifyDeviceAccess()) {
    sendJSON(['success' => false, 'message' => 'Unauthorized: Akses perangkat ditolak'], 401);
    exit;
}

$uid = isset($_GET['uid']) ? strtoupper(trim($_GET['uid'])) : '';

if (empty($uid)) {
    sendJSON(['success' => false, 'message' => 'UID tidak boleh kosong'], 400);
    exit;
}

$db = getDB();

// 1. Cek apakah UID terdaftar di tabel students
$stmt = $db->prepare("SELECT id, name, nim FROM students WHERE uid = ? AND is_active = 1 LIMIT 1");
$stmt->execute([$uid]);
$student = $stmt->fetch();

if (!$student) {
    // UID belum terdaftar → simpan ke unknown_cards
    $stmtCheck = $db->prepare("SELECT id, tap_count FROM unknown_cards WHERE uid = ?");
    $stmtCheck->execute([$uid]);
    $existing = $stmtCheck->fetch();

    if ($existing) {
        // Update tap count
        $db->prepare("UPDATE unknown_cards SET tap_count = tap_count + 1, last_seen = NOW() WHERE uid = ?")
           ->execute([$uid]);
    } else {
        // Insert baru
        $db->prepare("INSERT INTO unknown_cards (uid, first_seen, last_seen, tap_count) VALUES (?, NOW(), NOW(), 1)")
           ->execute([$uid]);
    }

    sendJSON([
        'success' => true,
        'status'  => 'unknown',
        'uid'     => $uid,
        'message' => 'Kartu belum terdaftar',
    ]);
    exit;
}

// 2. Tentukan sesi aktif & acara aktif
$today = date('Y-m-d');
$sessionId = trim($_GET['session_id'] ?? 'sesi_1');
if (empty($sessionId)) $sessionId = 'sesi_1';

$sessionNames = [
    'sesi_1' => 'Sesi 1 (Datang)',
    'sesi_2' => 'Sesi 2 (Ishoma)',
    'sesi_3' => 'Sesi 3 (Pulang)'
];
$sessionName = $sessionNames[$sessionId] ?? ucfirst($sessionId);

// Dapatkan acara aktif (hanya jika ada yang berstatus is_active = 1)
$activeEvent = $db->query("SELECT id, name FROM events WHERE is_active = 1 LIMIT 1")->fetch();
$eventId   = $activeEvent ? (int)$activeEvent['id'] : null;
$eventName = $activeEvent ? $activeEvent['name'] : 'Kegiatan HIMA Umum';

// 3. Cek apakah sudah absen pada sesi ini di acara dan tanggal yang sama
$stmtAttend = $db->prepare("SELECT id FROM attendance WHERE student_id = ? AND (event_id = ? OR (event_id IS NULL AND ? IS NULL)) AND tap_date = ? AND session_id = ? LIMIT 1");
$stmtAttend->execute([$student['id'], $eventId, $eventId, $today, $sessionId]);
$alreadyAttended = $stmtAttend->fetch();

if ($alreadyAttended) {
    sendJSON([
        'success'          => true,
        'status'           => 'already_attended',
        'uid'              => $uid,
        'name'             => $student['name'],
        'nim'              => $student['nim'],
        'session_id'       => $sessionId,
        'session'          => $sessionName,
        'has_active_event' => !empty($activeEvent),
        'event_name'       => $eventName,
        'message'          => 'Sudah absen pada ' . $sessionName,
    ]);
    exit;
}

// 4. Belum absen pada sesi ini → simpan absensi dengan proteksi duplicate race condition
try {
    $stmtInsert = $db->prepare("
        INSERT INTO attendance (student_id, event_id, session_id, session_name, uid, tap_time, tap_date)
        VALUES (?, ?, ?, ?, ?, NOW(), ?)
    ");
    $stmtInsert->execute([$student['id'], $eventId, $sessionId, $sessionName, $uid, $today]);
} catch (PDOException $e) {
    // Tangkap bila terjadi duplicate entry dari double-tap bersamaan (Error Code 23000)
    if ($e->getCode() == 23000 || strpos($e->getMessage(), 'Duplicate entry') !== false) {
        sendJSON([
            'success'          => true,
            'status'           => 'already_attended',
            'uid'              => $uid,
            'name'             => $student['name'],
            'nim'              => $student['nim'],
            'session_id'       => $sessionId,
            'session'          => $sessionName,
            'has_active_event' => !empty($activeEvent),
            'event_name'       => $eventName,
            'message'          => 'Sudah absen pada ' . $sessionName,
        ]);
        exit;
    }
    throw $e;
}

sendJSON([
    'success'          => true,
    'status'           => 'registered',
    'uid'              => $uid,
    'name'             => $student['name'],
    'nim'              => $student['nim'],
    'session_id'       => $sessionId,
    'session'          => $sessionName,
    'has_active_event' => !empty($activeEvent),
    'event_name'       => $eventName,
    'message'          => 'Selamat datang, ' . $student['name'],
]);
