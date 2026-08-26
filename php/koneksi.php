<?php
// ============================================================
// koneksi.php — Koneksi ke Database presensi_rfid
// ============================================================

$host   = "localhost";
$user   = "root";
$pass   = "root";      // sesuaikan password MAMP Anda
$db     = "presensi_rfid";
$port   = 3306;          // MAMP default: 3306 atau 8889 (cek MAMP > Preferences > Ports)

$conn = mysqli_connect($host, $user, $pass, $db, $port);

if (!$conn) {
    http_response_code(500);
    die(json_encode([
        "status" => "error",
        "pesan"  => "Koneksi database gagal: " . mysqli_connect_error()
    ]));
}

mysqli_set_charset($conn, "utf8mb4");
?>
