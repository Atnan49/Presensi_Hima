<?php
// ============================================
// api/students.php
// CRUD Mahasiswa
// GET    → daftar semua mahasiswa + UID
// POST   → tambah mahasiswa baru (daftarkan UID)
// PUT    → update data mahasiswa
// DELETE → hapus mahasiswa
// ============================================

require_once '../config.php';
setCorsHeaders();

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// --- GET: Ambil semua data mahasiswa ---
if ($method === 'GET') {
    $search = isset($_GET['search']) ? '%' . trim($_GET['search']) . '%' : '%';
    $stmt   = $db->prepare("
        SELECT s.id, s.uid, s.name, s.nim, s.is_active, s.created_at,
               COUNT(a.id) AS total_hadir
        FROM students s
        LEFT JOIN attendance a ON a.student_id = s.id
        WHERE s.name LIKE ? OR s.nim LIKE ? OR s.uid LIKE ?
        GROUP BY s.id
        ORDER BY s.name ASC
    ");
    $stmt->execute([$search, $search, $search]);
    $students = $stmt->fetchAll();
    sendJSON(['success' => true, 'data' => $students]);
}

// --- POST: Tambah mahasiswa baru ---
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $uid  = strtoupper(trim($body['uid'] ?? ''));
    $name = trim($body['name'] ?? '');
    $nim  = trim($body['nim'] ?? '');

    if (empty($uid) || empty($name)) {
        sendJSON(['success' => false, 'message' => 'UID dan nama wajib diisi'], 400);
    }

    // Cek apakah UID sudah ada
    $stmtCheck = $db->prepare("SELECT id FROM students WHERE uid = ?");
    $stmtCheck->execute([$uid]);
    if ($stmtCheck->fetch()) {
        sendJSON(['success' => false, 'message' => 'UID sudah terdaftar'], 409);
    }

    $db->prepare("INSERT INTO students (uid, name, nim) VALUES (?, ?, ?)")
       ->execute([$uid, $name, $nim]);

    // Hapus dari unknown_cards jika ada
    $db->prepare("DELETE FROM unknown_cards WHERE uid = ?")->execute([$uid]);

    sendJSON([
        'success' => true,
        'message' => 'Mahasiswa berhasil didaftarkan',
        'id'      => $db->lastInsertId(),
    ]);
}

// --- PUT: Update data mahasiswa ---
if ($method === 'PUT') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($body['id'] ?? 0);
    $name = trim($body['name'] ?? '');
    $nim  = trim($body['nim'] ?? '');
    $uid  = strtoupper(trim($body['uid'] ?? ''));
    $active = isset($body['is_active']) ? (int)$body['is_active'] : 1;

    if (!$id || empty($name)) {
        sendJSON(['success' => false, 'message' => 'ID dan nama wajib diisi'], 400);
    }

    $db->prepare("UPDATE students SET name=?, nim=?, uid=?, is_active=? WHERE id=?")
       ->execute([$name, $nim, $uid, $active, $id]);

    sendJSON(['success' => true, 'message' => 'Data berhasil diperbarui']);
}

// --- DELETE: Hapus mahasiswa ---
if ($method === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($body['id'] ?? 0);

    if (!$id) {
        sendJSON(['success' => false, 'message' => 'ID tidak valid'], 400);
    }

    $db->prepare("DELETE FROM students WHERE id = ?")->execute([$id]);
    sendJSON(['success' => true, 'message' => 'Mahasiswa berhasil dihapus']);
}

sendJSON(['success' => false, 'message' => 'Method tidak diizinkan'], 405);
