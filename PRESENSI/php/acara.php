<?php
// ============================================================
// get_acara.php — CRUD Acara/Kegiatan
// Actions: list, tambah, edit, hapus, aktifkan, selesaikan
// ============================================================

header("Content-Type: application/json");
include "koneksi.php";

$action = $_REQUEST['action'] ?? 'list';

// ── LIST ─────────────────────────────────────────────────────
if ($action === 'list') {
    $filter = trim($_GET['filter'] ?? '');  // 'aktif', 'draft', 'selesai', '' = semua
    if ($filter !== '') {
        $f   = $conn->real_escape_string($filter);
        $sql = "SELECT a.*,
                    (SELECT COUNT(*) FROM presensi p WHERE p.acara_id = a.id) AS jumlah_hadir
                FROM acara a
                WHERE a.status = '$f'
                ORDER BY a.tanggal DESC";
    } else {
        $sql = "SELECT a.*,
                    (SELECT COUNT(*) FROM presensi p WHERE p.acara_id = a.id) AS jumlah_hadir
                FROM acara a
                ORDER BY a.tanggal DESC";
    }
    $result = $conn->query($sql);
    $data   = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    echo json_encode(["status" => "ok", "data" => $data]);
    exit;
}

// ── GET AKTIF (untuk dashboard Python & Arduino) ─────────────
if ($action === 'get_aktif') {
    $today = date('Y-m-d');
    $stmt  = $conn->prepare(
        "SELECT * FROM acara WHERE status='aktif' AND tanggal=? LIMIT 1"
    );
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        echo json_encode(["status" => "ok", "data" => $row]);
    } else {
        echo json_encode(["status" => "tidak_ada", "data" => null]);
    }
    exit;
}

// ── TAMBAH ───────────────────────────────────────────────────
if ($action === 'tambah') {
    $nama_acara    = trim($_POST['nama_acara']    ?? '');
    $tanggal       = trim($_POST['tanggal']       ?? '');
    $waktu_mulai   = trim($_POST['waktu_mulai']   ?? '08:00');
    $waktu_selesai = trim($_POST['waktu_selesai'] ?? '10:00');
    $lokasi        = trim($_POST['lokasi']        ?? '');
    $deskripsi     = trim($_POST['deskripsi']     ?? '');

    if ($nama_acara === '' || $tanggal === '') {
        echo json_encode(["status" => "error", "pesan" => "Nama acara dan tanggal wajib diisi"]);
        exit;
    }

    $stmt = $conn->prepare(
        "INSERT INTO acara (nama_acara, tanggal, waktu_mulai, waktu_selesai, lokasi, deskripsi)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("ssssss", $nama_acara, $tanggal, $waktu_mulai, $waktu_selesai, $lokasi, $deskripsi);

    if ($stmt->execute()) {
        echo json_encode(["status" => "ok", "pesan" => "Acara berhasil ditambahkan", "id" => $conn->insert_id]);
    } else {
        echo json_encode(["status" => "error", "pesan" => $conn->error]);
    }
    $stmt->close();
    exit;
}

// ── EDIT ─────────────────────────────────────────────────────
if ($action === 'edit') {
    $id            = (int)($_POST['id'] ?? 0);
    $nama_acara    = trim($_POST['nama_acara']    ?? '');
    $tanggal       = trim($_POST['tanggal']       ?? '');
    $waktu_mulai   = trim($_POST['waktu_mulai']   ?? '08:00');
    $waktu_selesai = trim($_POST['waktu_selesai'] ?? '10:00');
    $lokasi        = trim($_POST['lokasi']        ?? '');
    $deskripsi     = trim($_POST['deskripsi']     ?? '');

    $stmt = $conn->prepare(
        "UPDATE acara SET nama_acara=?, tanggal=?, waktu_mulai=?, waktu_selesai=?, lokasi=?, deskripsi=?
         WHERE id=?"
    );
    $stmt->bind_param("ssssssi", $nama_acara, $tanggal, $waktu_mulai, $waktu_selesai, $lokasi, $deskripsi, $id);

    if ($stmt->execute()) {
        echo json_encode(["status" => "ok", "pesan" => "Acara berhasil diubah"]);
    } else {
        echo json_encode(["status" => "error", "pesan" => $conn->error]);
    }
    $stmt->close();
    exit;
}

// ── AKTIFKAN ─────────────────────────────────────────────────
if ($action === 'aktifkan') {
    $id = (int)($_POST['id'] ?? 0);
    // Set semua jadi draft dulu, lalu aktifkan yang dipilih
    $conn->query("UPDATE acara SET status='draft' WHERE status='aktif'");
    $stmt = $conn->prepare("UPDATE acara SET status='aktif' WHERE id=?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        echo json_encode(["status" => "ok", "pesan" => "Acara diaktifkan"]);
    } else {
        echo json_encode(["status" => "error", "pesan" => $conn->error]);
    }
    $stmt->close();
    exit;
}

// ── SELESAIKAN ───────────────────────────────────────────────
if ($action === 'selesaikan') {
    $id   = (int)($_POST['id'] ?? 0);
    $stmt = $conn->prepare("UPDATE acara SET status='selesai' WHERE id=?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        echo json_encode(["status" => "ok", "pesan" => "Acara ditandai selesai"]);
    } else {
        echo json_encode(["status" => "error", "pesan" => $conn->error]);
    }
    $stmt->close();
    exit;
}

// ── HAPUS ────────────────────────────────────────────────────
if ($action === 'hapus') {
    $id   = (int)($_POST['id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM acara WHERE id=?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        echo json_encode(["status" => "ok", "pesan" => "Acara dihapus"]);
    } else {
        echo json_encode(["status" => "error", "pesan" => $conn->error]);
    }
    $stmt->close();
    exit;
}

echo json_encode(["status" => "error", "pesan" => "Action tidak dikenal"]);
?>
