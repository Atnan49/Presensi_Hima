<?php
// ============================================================
// save_uid.php — Simpan UID terakhir (untuk monitoring Python)
// ============================================================

include "koneksi.php";

$uid = strtoupper(trim($_POST['uid'] ?? ''));

if ($uid !== '') {
    $stmt = $conn->prepare("UPDATE last_uid SET uid = ? WHERE id = 1");
    $stmt->bind_param("s", $uid);
    $stmt->execute();
    $stmt->close();
    file_put_contents("status_alat.txt", "Memproses: " . $uid);
}

echo "OK";
?>
