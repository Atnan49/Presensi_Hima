<?php
// ============================================================
// status.php — Status Alat (dibaca Python untuk monitoring)
// ============================================================

$msg = $_GET['msg'] ?? null;

if ($msg !== null) {
    file_put_contents("status_alat.txt", urldecode($msg));
    echo "OK";
} else {
    $status = file_exists("status_alat.txt")
        ? file_get_contents("status_alat.txt")
        : "Standby. Menunggu Kartu...";
    echo trim($status);
}
?>
