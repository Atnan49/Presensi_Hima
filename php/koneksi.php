<?php
// Koneksi database presensi terpusat via config.php

require_once __DIR__ . '/../config.php';

$host = DB_HOST;
$user = DB_USER;
$pass = DB_PASS;
$db   = DB_NAME;
$port = DB_PORT;

$conn = @mysqli_connect($host, $user, $pass, $db, $port);

if (!$conn) {
    error_log("MySQLi Connection failure: " . mysqli_connect_error());
    http_response_code(500);
    die(json_encode([
        "status" => "error",
        "pesan"  => "Koneksi database gagal. Silakan periksa konfigurasi server."
    ]));
}

mysqli_set_charset($conn, "utf8mb4");
mysqli_query($conn, "SET time_zone = '+07:00'");
?>
