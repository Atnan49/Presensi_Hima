<?php
// ============================================
// api/check_uid.php
// Dipanggil ESP8266 saat kartu di-tap
// Method: GET
// Parameter: ?uid=XXXXXXXX
//
// Response JSON:
// - status: "registered" | "unknown" | "already_attended"
// - name: nama mahasiswa (jika registered)
// - message: pesan untuk ditampilkan di LCD
// ============================================

require_once '../config.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
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

// 2. UID terdaftar → cek apakah sudah absen hari ini
$today = date('Y-m-d');
$stmtAttend = $db->prepare("SELECT id FROM attendance WHERE student_id = ? AND tap_date = ? LIMIT 1");
$stmtAttend->execute([$student['id'], $today]);
$alreadyAttended = $stmtAttend->fetch();

if ($alreadyAttended) {
    sendJSON([
        'success'  => true,
        'status'   => 'already_attended',
        'uid'      => $uid,
        'name'     => $student['name'],
        'nim'      => $student['nim'],
        'message'  => 'Sudah absen hari ini',
    ]);
    exit;
}

// 3. Belum absen hari ini → simpan absensi
$db->prepare("INSERT INTO attendance (student_id, uid, tap_time, tap_date) VALUES (?, ?, NOW(), ?)")
   ->execute([$student['id'], $uid, $today]);

sendJSON([
    'success' => true,
    'status'  => 'registered',
    'uid'     => $uid,
    'name'    => $student['name'],
    'nim'     => $student['nim'],
    'message' => 'Selamat datang, ' . $student['name'],
]);
