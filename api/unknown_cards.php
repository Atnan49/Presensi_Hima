<?php
// ============================================
// api/unknown_cards.php
// Ambil daftar kartu yang belum terdaftar
// GET    → daftar unknown cards
// DELETE → hapus (setelah didaftarkan)
// ============================================

require_once '../config.php';
setCorsHeaders();

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $db->query("
        SELECT id, uid, first_seen, last_seen, tap_count
        FROM unknown_cards
        ORDER BY last_seen DESC
    ");
    $cards = $stmt->fetchAll();
    sendJSON(['success' => true, 'data' => $cards]);
}

if ($method === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true);
    $uid  = strtoupper(trim($body['uid'] ?? ''));
    if (empty($uid)) {
        sendJSON(['success' => false, 'message' => 'UID tidak boleh kosong'], 400);
    }
    $db->prepare("DELETE FROM unknown_cards WHERE uid = ?")->execute([$uid]);
    sendJSON(['success' => true, 'message' => 'Kartu unknown berhasil dihapus']);
}

sendJSON(['success' => false, 'message' => 'Method tidak diizinkan'], 405);
