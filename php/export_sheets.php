<?php
// ============================================================
// export_sheets.php
// Endpoint untuk Google Apps Script mengambil data presensi
// Akses: GET /PRESENSI/php/export_sheets.php?acara_id=1&token=RAHASIA
//
// Parameter (opsional):
//   acara_id = filter by acara tertentu (default: semua)
//   token    = keamanan sederhana agar tidak sembarang diakses
// ============================================================

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");   // izinkan Google Apps Script

include "koneksi.php";

// ── Token keamanan akses Google Apps Script ──────────────────
$secretToken = getenv('SHEETS_SECRET_TOKEN') ?: (defined('DEVICE_API_KEY') ? DEVICE_API_KEY : '');
$token       = (string)($_GET['token'] ?? $_SERVER['HTTP_X_SHEETS_TOKEN'] ?? '');

if (empty($secretToken) || empty($token) || !hash_equals($secretToken, $token)) {
    http_response_code(403);
    echo json_encode(["status" => "error", "pesan" => "Akses ditolak: Token otentikasi tidak valid atau belum dikonfigurasi"]);
    exit;
}

$acara_id = isset($_GET['acara_id']) ? (int)$_GET['acara_id'] : 0;

// ── Query data presensi ──────────────────────────────────────
if ($acara_id > 0) {
    // Filter per acara
    $stmt = $conn->prepare(
        "SELECT
            m.nim,
            m.nama,
            m.prodi,
            m.angkatan,
            a.nama_acara,
            DATE_FORMAT(a.tanggal, '%d/%m/%Y')   AS tanggal,
            a.lokasi,
            DATE_FORMAT(p.waktu_tap, '%H:%i:%s') AS waktu_tap,
            p.status_hadir
         FROM presensi p
         JOIN mahasiswa m ON m.id = p.mahasiswa_id
         JOIN acara     a ON a.id = p.acara_id
         WHERE p.acara_id = ?
         ORDER BY p.waktu_tap ASC"
    );
    $stmt->bind_param("i", $acara_id);
} else {
    // Semua data
    $stmt = $conn->prepare(
        "SELECT
            m.nim,
            m.nama,
            m.prodi,
            m.angkatan,
            a.nama_acara,
            DATE_FORMAT(a.tanggal, '%d/%m/%Y')   AS tanggal,
            a.lokasi,
            DATE_FORMAT(p.waktu_tap, '%H:%i:%s') AS waktu_tap,
            p.status_hadir
         FROM presensi p
         JOIN mahasiswa m ON m.id = p.mahasiswa_id
         JOIN acara     a ON a.id = p.acara_id
         ORDER BY a.tanggal DESC, p.waktu_tap ASC"
    );
}

$stmt->execute();
$result = $stmt->get_result();

$rows = [];
// Header kolom (baris pertama di Sheets)
$rows[] = ["NIM", "Nama", "Prodi", "Angkatan", "Nama Acara", "Tanggal", "Lokasi", "Waktu Tap", "Status"];

while ($row = $result->fetch_assoc()) {
    $rows[] = [
        $row['nim'],
        $row['nama'],
        $row['prodi'],
        $row['angkatan'],
        $row['nama_acara'],
        $row['tanggal'],
        $row['lokasi'],
        $row['waktu_tap'],
        ucfirst($row['status_hadir'])
    ];
}
$stmt->close();

echo json_encode([
    "status"     => "ok",
    "updated_at" => date('Y-m-d H:i:s'),
    "total"      => count($rows) - 1,   // minus header
    "data"       => $rows
]);
?>
