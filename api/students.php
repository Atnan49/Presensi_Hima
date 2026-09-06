<?php
// API master data mahasiswa dan batch import

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

// --- POST: Tambah mahasiswa baru (Tunggal atau Batch) ---
if ($method === 'POST') {
    checkApiAuth();
    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    // Opsi 1: Batch Import massal dalam transaksi tunggal
    if (!empty($body['batch']) && is_array($body['students'] ?? null)) {
        $studentsList = $body['students'];
        $inserted = 0;
        $db->beginTransaction();
        try {
            $stmtUpsert = $db->prepare("
                INSERT INTO students (uid, name, nim, is_active)
                VALUES (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE name = VALUES(name), nim = VALUES(nim), is_active = 1
            ");
            $stmtDelUnknown = $db->prepare("DELETE FROM unknown_cards WHERE uid = ?");

            foreach ($studentsList as $item) {
                $u = strtoupper(trim($item['uid'] ?? ''));
                $n = strip_tags(trim($item['name'] ?? ''));
                $m = strip_tags(trim($item['nim'] ?? ''));
                if (!empty($u) && !empty($n)) {
                    $stmtUpsert->execute([$u, $n, $m]);
                    $stmtDelUnknown->execute([$u]);
                    $inserted++;
                }
            }
            $db->commit();
            sendJSON([
                'success' => true,
                'message' => "Berhasil memproses import {$inserted} data mahasiswa",
                'count'   => $inserted
            ]);
        } catch (Exception $e) {
            $db->rollBack();
            sendJSON(['success' => false, 'message' => 'Gagal memproses batch import data'], 500);
        }
    }

    $uid  = strtoupper(trim($body['uid'] ?? ''));
    $name = strip_tags(trim($body['name'] ?? ''));
    $nim  = strip_tags(trim($body['nim'] ?? ''));

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
    checkApiAuth();
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($body['id'] ?? 0);
    $name = strip_tags(trim($body['name'] ?? ''));
    $nim  = strip_tags(trim($body['nim'] ?? ''));
    $uid  = strtoupper(trim($body['uid'] ?? ''));
    $active = isset($body['is_active']) ? (int)$body['is_active'] : 1;

    if (empty($name)) {
        sendJSON(['success' => false, 'message' => 'Nama mahasiswa wajib diisi'], 400);
    }

    if ($id > 0) {
        $db->prepare("UPDATE students SET name=?, nim=?, uid=?, is_active=? WHERE id=?")
           ->execute([$name, $nim, $uid, $active, $id]);
    } elseif (!empty($uid)) {
        // Fallback update berdasarkan UID jika ID tidak ada (misal dari Cloud Firebase)
        $check = $db->prepare("SELECT id FROM students WHERE uid = ?");
        $check->execute([$uid]);
        $existing = $check->fetch();

        if ($existing) {
            $db->prepare("UPDATE students SET name=?, nim=?, is_active=? WHERE uid=?")
               ->execute([$name, $nim, $active, $uid]);
        } else {
            // Jika belum ada di MySQL, insert otomatis
            $db->prepare("INSERT INTO students (uid, name, nim, is_active) VALUES (?, ?, ?, ?)")
               ->execute([$uid, $name, $nim, $active]);
        }
    } else {
        sendJSON(['success' => false, 'message' => 'ID atau UID mahasiswa wajib diisi'], 400);
    }

    sendJSON(['success' => true, 'message' => 'Data berhasil diperbarui']);
}

// --- DELETE: Hapus mahasiswa ---
if ($method === 'DELETE') {
    checkApiAuth();
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($body['id'] ?? 0);
    $uid  = strtoupper(trim($body['uid'] ?? ''));

    if ($id > 0) {
        $db->prepare("DELETE FROM students WHERE id = ?")->execute([$id]);
        sendJSON(['success' => true, 'message' => 'Mahasiswa berhasil dihapus']);
    } elseif (!empty($uid)) {
        $db->prepare("DELETE FROM students WHERE uid = ?")->execute([$uid]);
        sendJSON(['success' => true, 'message' => 'Mahasiswa berhasil dihapus']);
    } else {
        sendJSON(['success' => false, 'message' => 'ID atau UID tidak valid'], 400);
    }
}

sendJSON(['success' => false, 'message' => 'Method tidak diizinkan'], 405);
