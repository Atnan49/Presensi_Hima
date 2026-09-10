<?php
// API rekap dan riwayat absensi mahasiswa

require_once '../config.php';
setCorsHeaders();

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// --- GET: Ambil data absensi ---
if ($method === 'GET') {
    checkApiAuth();
    $date = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }
    $studentId = isset($_GET['student_id']) ? (int)$_GET['student_id']  : 0;
    $eventId   = isset($_GET['event_id'])   ? (int)$_GET['event_id']    : 0;
    $sessionId = trim($_GET['session_id'] ?? '');
    $all       = isset($_GET['all'])        && $_GET['all'] === '1';

    $records = [];
    try {
        // Jika diminta berdasarkan event_id tertentu
        if ($eventId > 0) {
            $sql = "
                SELECT a.id, a.uid, s.name, s.nim,
                       DATE_FORMAT(a.tap_time, '%d/%m/%Y %H:%i:%s') AS waktu,
                       a.tap_date, a.event_id,
                       COALESCE(a.session_id, 'sesi_1') AS session_id,
                       COALESCE(a.session_name, 'Sesi 1 (Datang)') AS session_name,
                       COALESCE(a.telat, 0) AS telat,
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
                       COALESCE(a.telat, 0) AS telat,
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
                       COALESCE(a.telat, 0) AS telat,
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
                SELECT a.id, a.uid, s.name, s.nim, s.category, s.division, s.position,
                       DATE_FORMAT(a.tap_time, '%d/%m/%Y %H:%i:%s') AS waktu,
                       a.tap_date, a.event_id,
                       COALESCE(a.session_id, 'sesi_1') AS session_id,
                       COALESCE(a.session_name, 'Sesi 1 (Datang)') AS session_name,
                       COALESCE(a.telat, 0) AS telat,
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
            $sql .= " ORDER BY a.tap_time DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
        }
        $records = $stmt->fetchAll();
    } catch (\Throwable $e) {
        // Fallback jika kolom telat belum termigrasi
        try {
            ensureDatabaseTables($db);
            // Fallback query tanpa a.telat
            $sqlFallback = "
                SELECT a.id, a.uid, s.name, s.nim, s.category, s.division, s.position,
                       DATE_FORMAT(a.tap_time, '%d/%m/%Y %H:%i:%s') AS waktu,
                       a.tap_date, a.event_id,
                       COALESCE(a.session_id, 'sesi_1') AS session_id,
                       COALESCE(a.session_name, 'Sesi 1 (Datang)') AS session_name,
                       0 AS telat,
                       COALESCE(e.name, 'Kegiatan HIMA Umum') AS event_name
                FROM attendance a
                JOIN students s ON s.id = a.student_id
                LEFT JOIN events e ON e.id = a.event_id
                WHERE a.tap_date = ?
                ORDER BY a.tap_time DESC
            ";
            $stmt = $db->prepare($sqlFallback);
            $stmt->execute([$date]);
            $records = $stmt->fetchAll();
        } catch (\Throwable $e2) {
            $records = [];
        }
    }

    // Hitung statistik dengan memperhatikan target audience acara jika ada
    $targetAudience = 'all';
    if ($eventId <= 0) {
        $rowActive = $db->query("SELECT id, target_audience FROM events WHERE is_active = 1 LIMIT 1")->fetch();
        if ($rowActive) {
            $eventId = (int)$rowActive['id'];
            $targetAudience = $rowActive['target_audience'] ?? 'all';
        }
    } else {
        $stmtAud = $db->prepare("SELECT target_audience FROM events WHERE id = ? LIMIT 1");
        $stmtAud->execute([$eventId]);
        $rowAud = $stmtAud->fetch();
        if ($rowAud && !empty($rowAud['target_audience'])) {
            $targetAudience = $rowAud['target_audience'];
        }
    }

    if ($targetAudience === 'committee_only') {
        $stmtTot = $db->prepare("SELECT COUNT(DISTINCT student_id) FROM event_committees WHERE event_id = ?");
        $stmtTot->execute([$eventId]);
        $totalMhs = (int)$stmtTot->fetchColumn();
    } elseif ($targetAudience === 'bpi_bph') {
        $totalMhs = (int)$db->query("SELECT COUNT(*) FROM students WHERE is_active = 1 AND category IN ('BPI', 'BPH')")->fetchColumn();
    } else {
        $totalMhs = (int)$db->query("SELECT COUNT(*) FROM students WHERE is_active = 1")->fetchColumn();
    }

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

// --- PUT: Update status absensi (mis. toggle telat) ---
if ($method === 'PUT') {
    checkApiAuth();
    $rawInput = file_get_contents('php://input');
    $body     = json_decode($rawInput, true) ?: [];

    $id     = (int)($body['id'] ?? 0);
    $action = $body['action'] ?? '';

    if ($action === 'toggle_late') {
        if ($id > 0) {
            $stmt = $db->prepare("SELECT a.id, a.telat, s.name FROM attendance a JOIN students s ON s.id = a.student_id WHERE a.id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                sendJSON(['success' => false, 'message' => 'Data absensi tidak ditemukan'], 404);
            }

            $explicitTelat = isset($body['telat']) && $body['telat'] !== null ? (int)$body['telat'] : null;
            $newTelat = $explicitTelat !== null ? ($explicitTelat ? 1 : 0) : ((int)$row['telat'] === 1 ? 0 : 1);

            $stmtUpd = $db->prepare("UPDATE attendance SET telat = ? WHERE id = ?");
            $stmtUpd->execute([$newTelat, $id]);

            sendJSON([
                'success' => true,
                'message' => 'Status kehadiran berhasil diperbarui',
                'id'      => $id,
                'name'    => $row['name'],
                'telat'   => $newTelat
            ]);
        } else {
            sendJSON(['success' => false, 'message' => 'ID absensi tidak valid'], 400);
        }
    }

    sendJSON(['success' => false, 'message' => 'Action tidak didukung'], 400);
}

sendJSON(['success' => false, 'message' => 'Method tidak diizinkan'], 405);
