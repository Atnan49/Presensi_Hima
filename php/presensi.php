<?php
// ============================================================
// get_presensi.php — Data Presensi per Acara
// Digunakan Python app untuk tampil & export Excel
// ============================================================

header("Content-Type: application/json");
include "koneksi.php";

$action   = $_REQUEST['action'] ?? 'list';
$acara_id = (int)($_GET['acara_id'] ?? 0);

// ── LIST PRESENSI per ACARA ──────────────────────────────────
if ($action === 'list') {
    if ($acara_id === 0) {
        echo json_encode(["status" => "error", "pesan" => "acara_id diperlukan"]);
        exit;
    }

    $stmt = $conn->prepare(
        "SELECT p.id, p.waktu_tap, p.status_hadir,
                m.nim, m.nama, m.prodi, m.angkatan,
                a.nama_acara, a.tanggal, a.lokasi
         FROM presensi p
         JOIN mahasiswa m ON m.id = p.mahasiswa_id
         JOIN acara     a ON a.id = p.acara_id
         WHERE p.acara_id = ?
         ORDER BY p.waktu_tap ASC"
    );
    $stmt->bind_param("i", $acara_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data   = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    $stmt->close();
    echo json_encode(["status" => "ok", "data" => $data]);
    exit;
}

// ── STATISTIK ACARA ──────────────────────────────────────────
if ($action === 'statistik') {
    if ($acara_id === 0) {
        echo json_encode(["status" => "error", "pesan" => "acara_id diperlukan"]);
        exit;
    }

    $total_mhs  = $conn->query("SELECT COUNT(*) as c FROM mahasiswa")->fetch_assoc()['c'];
    $total_hadir = $conn->query("SELECT COUNT(*) as c FROM presensi WHERE acara_id=$acara_id")->fetch_assoc()['c'];
    $terlambat   = $conn->query("SELECT COUNT(*) as c FROM presensi WHERE acara_id=$acara_id AND status_hadir='terlambat'")->fetch_assoc()['c'];

    echo json_encode([
        "status"        => "ok",
        "total_mhs"     => (int)$total_mhs,
        "total_hadir"   => (int)$total_hadir,
        "terlambat"     => (int)$terlambat,
        "tidak_hadir"   => (int)$total_mhs - (int)$total_hadir
    ]);
    exit;
}

// ── DASHBOARD STATS (semua acara) ────────────────────────────
if ($action === 'dashboard') {
    $total_mhs   = (int)$conn->query("SELECT COUNT(*) as c FROM mahasiswa")->fetch_assoc()['c'];
    $total_acara = (int)$conn->query("SELECT COUNT(*) as c FROM acara")->fetch_assoc()['c'];
    $today       = date('Y-m-d');
    $acara_aktif = $conn->query(
        "SELECT * FROM acara WHERE status='aktif' AND tanggal='$today' LIMIT 1"
    )->fetch_assoc();

    // 10 presensi terbaru
    $recent = [];
    $res = $conn->query(
        "SELECT p.waktu_tap, p.status_hadir, m.nama, m.nim, m.prodi, a.nama_acara
         FROM presensi p
         JOIN mahasiswa m ON m.id = p.mahasiswa_id
         JOIN acara     a ON a.id = p.acara_id
         ORDER BY p.waktu_tap DESC LIMIT 10"
    );
    while ($row = $res->fetch_assoc()) {
        $recent[] = $row;
    }

    echo json_encode([
        "status"       => "ok",
        "total_mhs"    => $total_mhs,
        "total_acara"  => $total_acara,
        "acara_aktif"  => $acara_aktif,
        "recent"       => $recent
    ]);
    exit;
}

echo json_encode(["status" => "error", "pesan" => "Action tidak dikenal"]);
?>
