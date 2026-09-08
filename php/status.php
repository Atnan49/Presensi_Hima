<?php
// Monitoring status perangkat presensi

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $msg = $_POST['msg'] ?? null;
    if ($msg !== null) {
        $clean = strip_tags(trim(urldecode($msg)));
        file_put_contents(__DIR__ . "/status_alat.txt", substr($clean, 0, 100));
        echo "OK";
        exit;
    }
}

$status = file_exists(__DIR__ . "/status_alat.txt")
    ? file_get_contents(__DIR__ . "/status_alat.txt")
    : "Standby. Menunggu Kartu...";
echo htmlspecialchars(trim($status));
?>
