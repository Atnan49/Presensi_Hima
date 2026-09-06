<?php
// API autentikasi dan pengelolaan akun admin

require_once '../config.php';
setCorsHeaders();
checkApiAuth();

$db          = getDB();
$method      = $_SERVER['REQUEST_METHOD'];
$currentUser = getCurrentUser();
$adminId     = (int)($currentUser['id'] ?? 1);

// --- GET: Profil Admin ---
if ($method === 'GET') {
    $stmt = $db->prepare("SELECT id, username, name, created_at FROM admins WHERE id = ? LIMIT 1");
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch();

    if (!$admin) {
        sendJSON(['success' => false, 'message' => 'Admin tidak ditemukan'], 404);
    }

    sendJSON([
        'success' => true,
        'data'    => $admin
    ]);
}

// --- POST: Ganti Password ---
if ($method === 'POST') {
    $body            = json_decode(file_get_contents('php://input'), true) ?: [];
    $oldPassword     = $body['old_password'] ?? '';
    $newPassword     = $body['new_password'] ?? '';
    $confirmPassword = $body['confirm_password'] ?? '';

    if (empty($oldPassword) || empty($newPassword)) {
        sendJSON(['success' => false, 'message' => 'Password lama dan password baru wajib diisi'], 400);
    }

    if (strlen($newPassword) < 6) {
        sendJSON(['success' => false, 'message' => 'Password baru minimal 6 karakter'], 400);
    }

    if ($newPassword !== $confirmPassword) {
        sendJSON(['success' => false, 'message' => 'Konfirmasi password baru tidak cocok'], 400);
    }

    // Cek password lama
    $stmt = $db->prepare("SELECT password_hash FROM admins WHERE id = ? LIMIT 1");
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($oldPassword, $admin['password_hash'])) {
        sendJSON(['success' => false, 'message' => 'Password lama salah'], 400);
    }

    // Update password baru
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $updateStmt = $db->prepare("UPDATE admins SET password_hash = ? WHERE id = ?");
    $updateStmt->execute([$newHash, $adminId]);

    sendJSON([
        'success' => true,
        'message' => 'Password admin berhasil diubah. Silakan gunakan password baru pada login berikutnya.'
    ]);
}

sendJSON(['success' => false, 'message' => 'Method tidak diizinkan'], 405);
