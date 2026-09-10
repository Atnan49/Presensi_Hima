<?php
// API susunan kepanitiaan per program kerja / acara HIMA

require_once '../config.php';
setCorsHeaders();

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// --- GET: Ambil susunan panitia berdasarkan event_id ---
if ($method === 'GET') {
    checkApiAuth();
    $eventId = (int)($_GET['event_id'] ?? 0);
    if (!$eventId) {
        sendJSON(['success' => false, 'message' => 'event_id wajib disertakan'], 400);
    }

    $committees = [];
    try {
        $stmt = $db->prepare("
            SELECT ec.id, ec.event_id, ec.student_id, ec.role, ec.division AS committee_division, ec.created_at,
                   s.name, s.nim, s.uid, s.category, s.division AS student_division, s.position AS student_position
            FROM event_committees ec
            JOIN students s ON s.id = ec.student_id
            WHERE ec.event_id = ?
            ORDER BY 
              CASE 
                WHEN ec.role LIKE '%Ketua Panitia%' THEN 1
                WHEN ec.role LIKE '%Wakil%' THEN 2
                WHEN ec.role LIKE '%Sekretaris%' THEN 3
                WHEN ec.role LIKE '%Bendahara%' THEN 4
                WHEN ec.role LIKE '%Steering%' OR ec.role LIKE '%SC%' THEN 5
                WHEN ec.role LIKE '%Koordinator%' OR ec.role LIKE '%Koor%' THEN 6
                ELSE 7
              END ASC, ec.role ASC, s.name ASC
        ");
        $stmt->execute([$eventId]);
        $committees = $stmt->fetchAll();
    } catch (\Throwable $e) {
        try {
            ensureDatabaseTables($db);
            $stmt = $db->prepare("
                SELECT ec.id, ec.event_id, ec.student_id, ec.role, ec.division AS committee_division, ec.created_at,
                       s.name, s.nim, s.uid, s.category, s.division AS student_division, s.position AS student_position
                FROM event_committees ec
                JOIN students s ON s.id = ec.student_id
                WHERE ec.event_id = ?
            ");
            $stmt->execute([$eventId]);
            $committees = $stmt->fetchAll();
        } catch (\Throwable $e2) {
            $committees = [];
        }
    }

    sendJSON([
        'success'  => true,
        'event_id' => $eventId,
        'count'    => count($committees),
        'data'     => $committees
    ]);
}

// --- POST: Tambah / Assign panitia ke acara ---
if ($method === 'POST') {
    checkApiAuth();
    $body      = json_decode(file_get_contents('php://input'), true) ?: [];
    $eventId   = (int)($body['event_id'] ?? 0);
    $studentId = (int)($body['student_id'] ?? 0);
    $uid       = strtoupper(trim($body['uid'] ?? ''));
    $role      = strip_tags(trim($body['role'] ?? ''));
    $division  = strip_tags(trim($body['division'] ?? ''));

    if (!$eventId) {
        sendJSON(['success' => false, 'message' => 'event_id wajib disertakan'], 400);
    }
    if (empty($role)) {
        sendJSON(['success' => false, 'message' => 'Jabatan/Sie panitia wajib diisi'], 400);
    }

    // Jika student_id tidak ada tetapi uid ada, cari student_id
    if (!$studentId && !empty($uid)) {
        $stmtS = $db->prepare("SELECT id FROM students WHERE uid = ? LIMIT 1");
        $stmtS->execute([$uid]);
        $row = $stmtS->fetch();
        if ($row) $studentId = (int)$row['id'];
    }

    if (!$studentId) {
        sendJSON(['success' => false, 'message' => 'Mahasiswa tidak ditemukan atau student_id tidak valid'], 400);
    }

    try {
        $stmt = $db->prepare("
            INSERT INTO event_committees (event_id, student_id, role, division)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE role = VALUES(role), division = VALUES(division)
        ");
        $stmt->execute([$eventId, $studentId, $role, $division]);
    } catch (\Throwable $e) {
        ensureDatabaseTables($db);
        $stmt = $db->prepare("
            INSERT INTO event_committees (event_id, student_id, role, division)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE role = VALUES(role), division = VALUES(division)
        ");
        $stmt->execute([$eventId, $studentId, $role, $division]);
    }

    $id = (int)$db->lastInsertId();

    sendJSON([
        'success' => true,
        'message' => 'Panitia berhasil ditambahkan ke acara',
        'id'      => $id
    ]);
}

// --- DELETE: Hapus panitia dari acara ---
if ($method === 'DELETE') {
    checkApiAuth();
    $id        = (int)($_GET['id'] ?? 0);
    $eventId   = (int)($_GET['event_id'] ?? 0);
    $studentId = (int)($_GET['student_id'] ?? 0);
    $uid       = trim($_GET['uid'] ?? '');

    try {
        if ($eventId > 0 && !empty($uid)) {
            $stmt = $db->prepare("DELETE ec FROM event_committees ec JOIN students s ON s.id = ec.student_id WHERE ec.event_id = ? AND s.uid = ?");
            $stmt->execute([$eventId, $uid]);
        } elseif ($eventId > 0 && $studentId > 0) {
            $stmt = $db->prepare("DELETE FROM event_committees WHERE event_id = ? AND student_id = ?");
            $stmt->execute([$eventId, $studentId]);
        } elseif ($eventId > 0 && $id > 0) {
            $stmt = $db->prepare("DELETE FROM event_committees WHERE id = ? AND event_id = ?");
            $stmt->execute([$id, $eventId]);
        } elseif ($id > 0) {
            $stmt = $db->prepare("DELETE FROM event_committees WHERE id = ?");
            $stmt->execute([$id]);
        } else {
            sendJSON(['success' => false, 'message' => 'Parameter tidak lengkap'], 400);
        }
    } catch (\Throwable $e) {
        ensureDatabaseTables($db);
    }

    sendJSON(['success' => true, 'message' => 'Panitia berhasil dihapus dari acara']);
}

sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
