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
$stmt = $db->prepare("SELECT id, name, nim, category, division, position FROM students WHERE uid = ? AND is_active = 1 LIMIT 1");
$stmt->execute([$uid]);
$student = $stmt->fetch();

if (!$student) {
    // UID belum terdaftar → simpan/update ke unknown_cards secara atomik
    $db->prepare("
        INSERT INTO unknown_cards (uid, first_seen, last_seen, tap_count)
        VALUES (?, NOW(), NOW(), 1)
        ON DUPLICATE KEY UPDATE tap_count = tap_count + 1, last_seen = NOW()
    ")->execute([$uid]);

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

// Dapatkan acara aktif (fallback ke ID 1 / Kegiatan HIMA Umum jika belum ada yang diaktifkan)
$activeEvent = $db->query("SELECT id, name, start_time, end_time, target_audience FROM events WHERE is_active = 1 LIMIT 1")->fetch();
$hasActive = !empty($activeEvent);
if (!$activeEvent) {
    $activeEvent = $db->query("SELECT id, name, start_time, end_time, target_audience FROM events ORDER BY id ASC LIMIT 1")->fetch();
}
$eventId   = $activeEvent ? (int)$activeEvent['id'] : 1;
$eventName = $activeEvent ? $activeEvent['name'] : 'Kegiatan HIMA Umum';
$startTime = ($activeEvent && !empty($activeEvent['start_time'])) ? $activeEvent['start_time'] : '08:00:00';

// 3. Validasi Kepanitiaan: Hanya mahasiswa yang terdaftar sebagai panitia yang diizinkan absen
$isCommittee   = false;
$committeeRole = '';
$committeeDiv  = '';

// 3a. Cek apakah terdaftar di tabel event_committees untuk acara ini
if ($eventId > 0) {
    try {
        $stmtComm = $db->prepare("
            SELECT role, division 
            FROM event_committees 
            WHERE event_id = ? AND student_id = ? 
            LIMIT 1
        ");
        $stmtComm->execute([$eventId, $student['id']]);
        $commRow = $stmtComm->fetch();
        if ($commRow) {
            $isCommittee   = true;
            $committeeRole = $commRow['role'];
            $committeeDiv  = $commRow['division'];
        }
    } catch (\Throwable $e) {
    }
}

// 3b. Cek apakah terdaftar di kepanitiaan manapun di sistem
if (!$isCommittee) {
    try {
        $stmtCommAny = $db->prepare("
            SELECT ec.role, ec.division, e.name AS event_name
            FROM event_committees ec
            LEFT JOIN events e ON e.id = ec.event_id
            WHERE ec.student_id = ?
            LIMIT 1
        ");
        $stmtCommAny->execute([$student['id']]);
        $commAny = $stmtCommAny->fetch();
        if ($commAny) {
            $isCommittee   = true;
            $committeeRole = $commAny['role'];
            $committeeDiv  = $commAny['division'];
        }
    } catch (\Throwable $e) {
    }
}

// 3c. Cek jika data mahasiswa memiliki jabatan kepanitiaan (position)
if (!$isCommittee && !empty($student['position'])) {
    $posLower = strtolower($student['position']);
    if (strpos($posLower, 'panitia') !== false || strpos($posLower, 'ketua') !== false || strpos($posLower, 'sie') !== false || strpos($posLower, 'koor') !== false) {
        $isCommittee   = true;
        $committeeRole = $student['position'];
        $committeeDiv  = $student['division'] ?? '';
    }
}

// Tolak presensi jika bukan panitia
if (!$isCommittee) {
    sendJSON([
        'success'          => false,
        'status'           => 'not_committee',
        'uid'              => $uid,
        'name'             => $student['name'],
        'nim'              => $student['nim'],
        'session_id'       => $sessionId,
        'session'          => $sessionName,
        'has_active_event' => $hasActive,
        'event_name'       => $eventName,
        'message'          => 'Akses Ditolak: Hanya mahasiswa yang terdaftar sebagai panitia yang dapat melakukan presensi.',
    ], 403);
    exit;
}

// 4. Cek apakah sudah absen pada sesi ini di acara dan tanggal yang sama
$stmtAttend = $db->prepare("SELECT id FROM attendance WHERE student_id = ? AND event_id = ? AND tap_date = ? AND session_id = ? LIMIT 1");
$stmtAttend->execute([$student['id'], $eventId, $today, $sessionId]);
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
        'has_active_event' => $hasActive,
        'event_name'       => $eventName,
        'message'          => 'Sudah absen pada ' . $sessionName,
    ]);
    exit;
}

// 4. Belum absen pada sesi ini → hitung status telat di server berdasarkan jam mulai acara
$currentTime  = date('H:i:s');
$isServerLate = ($currentTime > $startTime) ? 1 : 0;
$isClientLate = (!empty($_GET['telat']) && $_GET['telat'] !== '0')
             || (!empty($_GET['is_late']) && $_GET['is_late'] !== '0')
             || (isset($_GET['status']) && strtolower($_GET['status']) === 'telat');

$isTelat = ($sessionId === 'sesi_1' && $isServerLate) || $isClientLate ? 1 : 0;

try {
    $stmtInsert = $db->prepare("
        INSERT INTO attendance (student_id, event_id, session_id, session_name, uid, tap_time, tap_date, telat)
        VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)
    ");
    $stmtInsert->execute([$student['id'], $eventId, $sessionId, $sessionName, $uid, $today, $isTelat]);
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
            'has_active_event' => $hasActive,
            'event_name'       => $eventName,
            'message'          => 'Sudah absen pada ' . $sessionName,
        ]);
        exit;
    }
    // Fallback jika kolom telat belum termigrasi
    try {
        $stmtInsert = $db->prepare("
            INSERT INTO attendance (student_id, event_id, session_id, session_name, uid, tap_time, tap_date)
            VALUES (?, ?, ?, ?, ?, NOW(), ?)
        ");
        $stmtInsert->execute([$student['id'], $eventId, $sessionId, $sessionName, $uid, $today]);
    } catch (\Throwable $e2) {
        throw $e;
    }
}

sendJSON([
    'success'          => true,
    'status'           => 'registered',
    'uid'              => $uid,
    'name'             => $student['name'],
    'nim'              => $student['nim'],
    'session_id'       => $sessionId,
    'session'          => $sessionName,
    'has_active_event' => $hasActive,
    'event_name'       => $eventName,
    'is_committee'     => true,
    'committee_role'   => $committeeRole,
    'message'          => 'Selamat datang Panitia: ' . $student['name'] . ($committeeRole ? " ($committeeRole)" : ''),
]);
