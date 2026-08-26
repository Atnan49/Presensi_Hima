<?php
// ============================================
// api/attendance.php
// Ambil & Hapus Rekap Absensi
// GET    → rekap absensi (filter: date, student_id)
// DELETE / POST → hapus record absensi (by id atau date)
// ============================================

require_once '../config.php';
setCorsHeaders();

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// --- GET: Ambil data absensi ---
if ($method === 'GET') {
    $date      = isset($_GET['date'])       ? $_GET['date']              : date('Y-m-d');
    $studentId = isset($_GET['student_id']) ? (int)$_GET['student_id']  : 0;
    $all       = isset($_GET['all'])        && $_GET['all'] === '1';

    // Jika diminta semua rekap
    if ($all) {
        $stmt = $db->prepare("
            SELECT a.id, a.uid, s.name, s.nim,
                   DATE_FORMAT(a.tap_time, '%d/%m/%Y %H:%i:%s') AS waktu,
                   a.tap_date
            FROM attendance a
            JOIN students s ON s.id = a.student_id
            ORDER BY a.tap_time DESC
            LIMIT 500
        ");
        $stmt->execute();
    } elseif ($studentId) {
        // Rekap per mahasiswa
        $stmt = $db->prepare("
            SELECT a.id, a.uid, s.name, s.nim,
                   DATE_FORMAT(a.tap_time, '%d/%m/%Y %H:%i:%s') AS waktu,
                   a.tap_date
            FROM attendance a
            JOIN students s ON s.id = a.student_id
            WHERE a.student_id = ?
            ORDER BY a.tap_time DESC
        ");
        $stmt->execute([$studentId]);
    } else {
        // Rekap per tanggal (default: hari ini)
        $stmt = $db->prepare("
            SELECT a.id, a.uid, s.name, s.nim,
                   DATE_FORMAT(a.tap_time, '%d/%m/%Y %H:%i:%s') AS waktu,
                   a.tap_date
            FROM attendance a
            JOIN students s ON s.id = a.student_id
            WHERE a.tap_date = ?
            ORDER BY a.tap_time ASC
        ");
        $stmt->execute([$date]);
    }

    $records = $stmt->fetchAll();

    // Hitung statistik
    $totalMhs   = $db->query("SELECT COUNT(*) FROM students WHERE is_active = 1")->fetchColumn();
    $totalHadir = count($records);

    sendJSON([
        'success'      => true,
        'date'         => $date,
        'total_hadir'  => $totalHadir,
        'total_mhs'    => (int)$totalMhs,
        'data'         => $records,
    ]);
}

// --- DELETE: Hapus data absensi ---
if ($method === 'DELETE' || isset($_GET['action']) && $_GET['action'] === 'delete') {
    // Baca dari JSON body atau GET query param
    $rawInput = file_get_contents('php://input');
    $body     = json_decode($rawInput, true) ?: [];

    $id   = (int)($body['id']   ?? $_GET['id']   ?? 0);
    $date = trim($body['date']  ?? $_GET['date'] ?? '');

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
        sendJSON(['success' => false, 'message' => 'ID atau tanggal tidak ditemukan'], 400);
    }
}

sendJSON(['success' => false, 'message' => 'Method tidak diizinkan'], 405);
