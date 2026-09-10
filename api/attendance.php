<?php
// API rekap dan riwayat absensi mahasiswa

require_once '../config.php';
setCorsHeaders();

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// --- GET: Ambil data absensi ---
if ($method === 'GET') {
    $date      = isset($_GET['date'])       ? $_GET['date']              : date('Y-m-d');
    $studentId = isset($_GET['student_id']) ? (int)$_GET['student_id']  : 0;
    $eventId   = isset($_GET['event_id'])   ? (int)$_GET['event_id']    : 0;
    $sessionId = trim($_GET['session_id'] ?? '');
    $all       = isset($_GET['all'])        && $_GET['all'] === '1';

    // Jika diminta berdasarkan event_id tertentu
    if ($eventId > 0) {
        $sql = "
            SELECT a.id, a.uid, s.name, s.nim,
                   DATE_FORMAT(a.tap_time, '%d/%m/%Y %H:%i:%s') AS waktu,
                   a.tap_date, a.event_id,
                   COALESCE(a.session_id, 'sesi_1') AS session_id,
                   COALESCE(a.session_name, 'Sesi 1 (Datang)') AS session_name,
                   COALESCE(e.name, 'Acara') AS event_name
            FROM attendance a
            JOIN students s ON s.id = a.student_id
            LEFT JOIN events e ON e.id = a.event_id
            WHERE a.event_id = ?
        ";
        $params = [$eventId];
        if (!empty($sessionId)) {
            $sql .= " AND a.session_id = ?";
            $params[] = $sessionId;
        }
        $sql .= " ORDER BY a.tap_time DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
    } elseif ($all) {
        // Semua rekap
        $sql = "
            SELECT a.id, a.uid, s.name, s.nim,
                   DATE_FORMAT(a.tap_time, '%d/%m/%Y %H:%i:%s') AS waktu,
                   a.tap_date, a.event_id,
                   COALESCE(a.session_id, 'sesi_1') AS session_id,
                   COALESCE(a.session_name, 'Sesi 1 (Datang)') AS session_name,
                   COALESCE(e.name, 'Kegiatan HIMA Umum') AS event_name
            FROM attendance a
            JOIN students s ON s.id = a.student_id
            LEFT JOIN events e ON e.id = a.event_id
        ";
        $params = [];
        if (!empty($sessionId)) {
            $sql .= " WHERE a.session_id = ?";
            $params[] = $sessionId;
        }
        $sql .= " ORDER BY a.tap_time DESC LIMIT 500";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
    } elseif ($studentId > 0) {
        // Rekap per mahasiswa
        $stmt = $db->prepare("
            SELECT a.id, a.uid, s.name, s.nim,
                   DATE_FORMAT(a.tap_time, '%d/%m/%Y %H:%i:%s') AS waktu,
                   a.tap_date, a.event_id,
                   COALESCE(a.session_id, 'sesi_1') AS session_id,
                   COALESCE(a.session_name, 'Sesi 1 (Datang)') AS session_name,
                   COALESCE(e.name, 'Kegiatan HIMA Umum') AS event_name
            FROM attendance a
            JOIN students s ON s.id = a.student_id
            LEFT JOIN events e ON e.id = a.event_id
            WHERE a.student_id = ?
            ORDER BY a.tap_time DESC
        ");
        $stmt->execute([$studentId]);
    } else {
        // Rekap per tanggal (default: hari ini)
        $sql = "
            SELECT a.id, a.uid, s.name, s.nim,
                   DATE_FORMAT(a.tap_time, '%d/%m/%Y %H:%i:%s') AS waktu,
                   a.tap_date, a.event_id,
                   COALESCE(a.session_id, 'sesi_1') AS session_id,
                   COALESCE(a.session_name, 'Sesi 1 (Datang)') AS session_name,
                   COALESCE(e.name, 'Kegiatan HIMA Umum') AS event_name
            FROM attendance a
            JOIN students s ON s.id = a.student_id
            LEFT JOIN events e ON e.id = a.event_id
            WHERE a.tap_date = ?
        ";
        $params = [$date];
        if (!empty($sessionId)) {
            $sql .= " AND a.session_id = ?";
            $params[] = $sessionId;
        }
        $sql .= " ORDER BY a.tap_time ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
    }

    $records = $stmt->fetchAll();

    // Hitung statistik dengan hadir unik (distinct student UID)
    $totalMhs = (int)$db->query("SELECT COUNT(*) FROM students WHERE is_active = 1")->fetchColumn();
    $uniqueUids = [];
    foreach ($records as $r) {
        if (!empty($r['uid'])) {
            $uniqueUids[$r['uid']] = true;
        }
    }
    $totalHadir = count($uniqueUids);
    $totalAlpha = max(0, $totalMhs - $totalHadir);

    sendJSON([
        'success'      => true,
        'date'         => $date,
        'event_id'     => $eventId,
        'session_id'   => $sessionId,
        'total_hadir'  => $totalHadir,
        'total_alpha'  => $totalAlpha,
        'total_mhs'    => $totalMhs,
        'data'         => $records,
    ]);
}

// --- DELETE: Hapus data absensi (Hanya metode DELETE, tolak GET) ---
if ($method === 'DELETE') {
    checkApiAuth();
    $rawInput = file_get_contents('php://input');
    $body     = json_decode($rawInput, true) ?: [];

    $id   = (int)($body['id']   ?? 0);
    $date = trim($body['date']  ?? '');

    if ($id > 0) {
        // Hapus 1 record absensi
        $stmt = $db->prepare("DELETE FROM attendance WHERE id = ?");
        $stmt->execute([$id]);
        sendJSON(['success' => true, 'message' => 'Data absensi berhasil dihapus']);
    } elseif (!empty($date)) {
        // Hapus semua absensi pada tanggal tertentu
        $stmt = $db->prepare("DELETE FROM attendance WHERE tap_date = ?");
        $stmt->execute([$date]);
        sendJSON(['success' => true, 'message' => "Semua absensi tanggal $date berhasil dihapus"]);
    } else {
        sendJSON(['success' => false, 'message' => 'ID atau tanggal tidak valid'], 400);
    }
}

sendJSON(['success' => false, 'message' => 'Method tidak diizinkan'], 405);
