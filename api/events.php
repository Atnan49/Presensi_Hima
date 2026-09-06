<?php
// ============================================
// api/events.php
// CRUD Program Kerja / Acara HIMA
// GET    → daftar semua acara + info acara aktif
// POST   → buat acara baru
// PUT    → edit acara / ubah acara aktif
// DELETE → hapus acara
// ============================================

require_once '../config.php';
setCorsHeaders();

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// --- GET: Ambil daftar program kerja / acara ---
if ($method === 'GET') {
    $stmt = $db->query("
        SELECT e.id, e.name, e.description, e.event_date, e.is_active, e.created_at,
               COUNT(DISTINCT a.student_id) AS total_hadir
        FROM events e
        LEFT JOIN attendance a ON a.event_id = e.id
        GROUP BY e.id
        ORDER BY e.is_active DESC, e.event_date DESC, e.id DESC
    ");
    $events = $stmt->fetchAll();

    // Cari acara yang sedang aktif
    $activeEvent = null;
    foreach ($events as $ev) {
        if ($ev['is_active'] == 1) {
            $activeEvent = $ev;
            break;
        }
    }

    sendJSON([
        'success'      => true,
        'active_event' => $activeEvent,
        'data'         => $events
    ]);
}

// --- POST: Tambah acara baru ---
if ($method === 'POST') {
    checkApiAuth();
    $body        = json_decode(file_get_contents('php://input'), true) ?: [];
    $name        = strip_tags(trim($body['name'] ?? ''));
    $description = strip_tags(trim($body['description'] ?? ''));
    $eventDate   = trim($body['event_date'] ?? date('Y-m-d'));
    $isActive    = !empty($body['is_active']) ? 1 : 0;

    if (empty($name)) {
        sendJSON(['success' => false, 'message' => 'Nama program kerja / acara wajib diisi'], 400);
    }

    if ($isActive) {
        // Nonaktifkan acara lain jika acara ini dijadikan aktif
        $db->exec("UPDATE events SET is_active = 0");
    }

    $stmt = $db->prepare("INSERT INTO events (name, description, event_date, is_active) VALUES (?, ?, ?, ?)");
    $stmt->execute([$name, $description, $eventDate, $isActive]);

    $newId = (int)$db->lastInsertId();

    sendJSON([
        'success' => true,
        'message' => 'Program kerja / acara berhasil dibuat',
        'id'      => $newId
    ]);
}

// --- PUT: Update acara / set aktif ---
if ($method === 'PUT') {
    checkApiAuth();
    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $id     = (int)($body['id'] ?? 0);
    $action = trim($body['action'] ?? '');

    if (!$id) {
        sendJSON(['success' => false, 'message' => 'ID acara tidak valid'], 400);
    }

    // Aksi khusus: jadikan acara aktif
    if ($action === 'set_active') {
        $db->exec("UPDATE events SET is_active = 0");
        $stmt = $db->prepare("UPDATE events SET is_active = 1 WHERE id = ?");
        $stmt->execute([$id]);

        sendJSON(['success' => true, 'message' => 'Acara berhasil diaktifkan untuk presensi']);
    }

    // Update detail acara
    $name        = strip_tags(trim($body['name'] ?? ''));
    $description = strip_tags(trim($body['description'] ?? ''));
    $eventDate   = trim($body['event_date'] ?? date('Y-m-d'));
    $isActive    = !empty($body['is_active']) ? 1 : 0;

    if (empty($name)) {
        sendJSON(['success' => false, 'message' => 'Nama acara wajib diisi'], 400);
    }

    if ($isActive) {
        $db->exec("UPDATE events SET is_active = 0");
    }

    $stmt = $db->prepare("UPDATE events SET name = ?, description = ?, event_date = ?, is_active = ? WHERE id = ?");
    $stmt->execute([$name, $description, $eventDate, $isActive, $id]);

    sendJSON(['success' => true, 'message' => 'Data acara berhasil diperbarui']);
}

// --- DELETE: Hapus acara ---
if ($method === 'DELETE') {
    checkApiAuth();
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $id   = (int)($body['id'] ?? 0);

    if (!$id) {
        sendJSON(['success' => false, 'message' => 'ID acara tidak valid'], 400);
    }

    // Cek apakah acara ini sedang aktif
    $check = $db->prepare("SELECT is_active FROM events WHERE id = ?");
    $check->execute([$id]);
    $event = $check->fetch();

    if ($event && $event['is_active'] == 1) {
        // Cari acara lain untuk dijadikan aktif sebelum dihapus
        $other = $db->query("SELECT id FROM events WHERE id != $id ORDER BY id DESC LIMIT 1")->fetch();
        if ($other) {
            $db->exec("UPDATE events SET is_active = 1 WHERE id = " . (int)$other['id']);
        }
    }

    // Lepas relasi attendance agar data absensi tidak ikut terhapus (event_id jadi NULL)
    $db->prepare("UPDATE attendance SET event_id = NULL WHERE event_id = ?")->execute([$id]);

    $stmt = $db->prepare("DELETE FROM events WHERE id = ?");
    $stmt->execute([$id]);

    sendJSON(['success' => true, 'message' => 'Acara berhasil dihapus']);
}

sendJSON(['success' => false, 'message' => 'Method tidak diizinkan'], 405);
