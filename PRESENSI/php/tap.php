<?php
// ============================================================
// tap.php — Handler Utama Tap Kartu RFID
// Menerima POST dari Arduino, catat presensi ke DB
// ============================================================

header("Content-Type: application/json");
include "koneksi.php";

$uid = strtoupper(trim($_POST['uid'] ?? ''));

// ── 1. Validasi UID ──────────────────────────────────────────
if ($uid === '') {
    echo json_encode(["status" => "error", "pesan" => "UID kosong"]);
    exit;
}

// ── 2. Cari mahasiswa berdasarkan UID ───────────────────────
$stmt = $conn->prepare("SELECT id, nim, nama, prodi FROM mahasiswa WHERE uid = ?");
$stmt->bind_param("s", $uid);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    file_put_contents("status_alat.txt", "Kartu Tidak Terdaftar!");
    echo json_encode(["status" => "tidak_terdaftar"]);
    exit;
}

$mhs = $result->fetch_assoc();
$stmt->close();

// ── 3. Cari acara yang AKTIF hari ini ───────────────────────
$today = date('Y-m-d');
$stmt2 = $conn->prepare(
    "SELECT id, nama_acara, waktu_mulai, waktu_selesai, lokasi
     FROM acara
     WHERE status = 'aktif' AND tanggal = ?
     LIMIT 1"
);
$stmt2->bind_param("s", $today);
$stmt2->execute();
$result2 = $stmt2->get_result();

if ($result2->num_rows === 0) {
    file_put_contents("status_alat.txt", "Tidak Ada Acara Aktif!");
    echo json_encode([
        "status" => "tidak_ada_acara",
        "nama"   => $mhs['nama']
    ]);
    exit;
}

$acara = $result2->fetch_assoc();
$stmt2->close();

// ── 4. Cek duplikat: apakah mahasiswa sudah tap di acara ini ─
$stmt3 = $conn->prepare(
    "SELECT id FROM presensi WHERE acara_id = ? AND mahasiswa_id = ?"
);
$stmt3->bind_param("ii", $acara['id'], $mhs['id']);
$stmt3->execute();
$dup = $stmt3->get_result();

if ($dup->num_rows > 0) {
    $stmt3->close();
    file_put_contents("status_alat.txt", "Sudah Presensi: " . $mhs['nama']);
    echo json_encode([
        "status"      => "sudah_presensi",
        "nama"        => $mhs['nama'],
        "nim"         => $mhs['nim'],
        "nama_acara"  => $acara['nama_acara']
    ]);
    exit;
}
$stmt3->close();

// ── 5. Tentukan status hadir / terlambat ────────────────────
$now          = date('H:i:s');
$waktu_mulai  = $acara['waktu_mulai'];
$status_hadir = ($now <= $waktu_mulai) ? 'hadir' : 'terlambat';

// ── 6. Simpan presensi ───────────────────────────────────────
$waktu_tap = date('Y-m-d H:i:s');
$stmt4 = $conn->prepare(
    "INSERT INTO presensi (acara_id, mahasiswa_id, uid, waktu_tap, status_hadir)
     VALUES (?, ?, ?, ?, ?)"
);
$stmt4->bind_param("iisss", $acara['id'], $mhs['id'], $uid, $waktu_tap, $status_hadir);

if (!$stmt4->execute()) {
    echo json_encode(["status" => "error", "pesan" => "Gagal simpan presensi"]);
    $stmt4->close();
    exit;
}
$stmt4->close();

// ── 7. Update last_uid & status ─────────────────────────────
$conn->query("UPDATE last_uid SET uid = '" . $conn->real_escape_string($uid) . "' WHERE id = 1");
$statusMsg = ($status_hadir === 'hadir')
    ? "Hadir: " . $mhs['nama']
    : "Terlambat: " . $mhs['nama'];
file_put_contents("status_alat.txt", $statusMsg);

// ── 8. Respons ke Arduino ────────────────────────────────────
echo json_encode([
    "status"        => "berhasil",
    "status_hadir"  => $status_hadir,
    "nama"          => $mhs['nama'],
    "nim"           => $mhs['nim'],
    "prodi"         => $mhs['prodi'],
    "nama_acara"    => $acara['nama_acara'],
    "waktu_tap"     => $waktu_tap
]);
?>
