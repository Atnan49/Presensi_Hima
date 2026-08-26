<?php
// ============================================================
// get_mahasiswa.php — CRUD Mahasiswa
// Actions: list, tambah, edit, hapus, get_by_id
// ============================================================

header("Content-Type: application/json");
include "koneksi.php";

$action = $_REQUEST['action'] ?? 'list';

// ── LIST ─────────────────────────────────────────────────────
if ($action === 'list') {
    $search = trim($_GET['search'] ?? '');
    if ($search !== '') {
        $like = "%" . $conn->real_escape_string($search) . "%";
        $sql  = "SELECT * FROM mahasiswa
                 WHERE nama LIKE '$like' OR nim LIKE '$like' OR prodi LIKE '$like'
                 ORDER BY nama ASC";
    } else {
        $sql = "SELECT * FROM mahasiswa ORDER BY nama ASC";
    }
    $result = $conn->query($sql);
    $data   = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    echo json_encode(["status" => "ok", "data" => $data]);
    exit;
}

// ── GET BY ID ────────────────────────────────────────────────
if ($action === 'get_by_id') {
    $id   = (int)($_GET['id'] ?? 0);
    $stmt = $conn->prepare("SELECT * FROM mahasiswa WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    echo json_encode(["status" => "ok", "data" => $row]);
    exit;
}

// ── TAMBAH ───────────────────────────────────────────────────
if ($action === 'tambah') {
    $uid      = strtoupper(trim($_POST['uid']      ?? ''));
    $nim      = trim($_POST['nim']      ?? '');
    $nama     = trim($_POST['nama']     ?? '');
    $prodi    = trim($_POST['prodi']    ?? '');
    $angkatan = (int)($_POST['angkatan'] ?? date('Y'));

    if ($uid === '' || $nim === '' || $nama === '') {
        echo json_encode(["status" => "error", "pesan" => "UID, NIM, dan Nama wajib diisi"]);
        exit;
    }

    $stmt = $conn->prepare(
        "INSERT INTO mahasiswa (uid, nim, nama, prodi, angkatan) VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("ssssi", $uid, $nim, $nama, $prodi, $angkatan);

    if ($stmt->execute()) {
        echo json_encode(["status" => "ok", "pesan" => "Mahasiswa berhasil ditambahkan", "id" => $conn->insert_id]);
    } else {
        $err = $conn->errno === 1062 ? "UID atau NIM sudah terdaftar" : $conn->error;
        echo json_encode(["status" => "error", "pesan" => $err]);
    }
    $stmt->close();
    exit;
}

// ── EDIT ─────────────────────────────────────────────────────
if ($action === 'edit') {
    $id       = (int)($_POST['id'] ?? 0);
    $uid      = strtoupper(trim($_POST['uid']      ?? ''));
    $nim      = trim($_POST['nim']      ?? '');
    $nama     = trim($_POST['nama']     ?? '');
    $prodi    = trim($_POST['prodi']    ?? '');
    $angkatan = (int)($_POST['angkatan'] ?? date('Y'));

    if ($id === 0 || $uid === '' || $nim === '' || $nama === '') {
        echo json_encode(["status" => "error", "pesan" => "Data tidak lengkap"]);
        exit;
    }

    $stmt = $conn->prepare(
        "UPDATE mahasiswa SET uid=?, nim=?, nama=?, prodi=?, angkatan=? WHERE id=?"
    );
    $stmt->bind_param("ssssii", $uid, $nim, $nama, $prodi, $angkatan, $id);

    if ($stmt->execute()) {
        echo json_encode(["status" => "ok", "pesan" => "Data berhasil diubah"]);
    } else {
        $err = $conn->errno === 1062 ? "UID atau NIM sudah digunakan mahasiswa lain" : $conn->error;
        echo json_encode(["status" => "error", "pesan" => $err]);
    }
    $stmt->close();
    exit;
}

// ── HAPUS ────────────────────────────────────────────────────
if ($action === 'hapus') {
    $id   = (int)($_POST['id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM mahasiswa WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        echo json_encode(["status" => "ok", "pesan" => "Mahasiswa dihapus"]);
    } else {
        echo json_encode(["status" => "error", "pesan" => $conn->error]);
    }
    $stmt->close();
    exit;
}

// ── SCAN UID (cek UID terakhir dibaca Arduino) ──────────────
if ($action === 'scan_uid') {
    $result = $conn->query("SELECT uid FROM last_uid WHERE id=1");
    $row    = $result->fetch_assoc();
    echo json_encode(["status" => "ok", "uid" => $row['uid'] ?? '']);
    exit;
}

// ── CLEAR LAST UID ──────────────────────────────────────────
if ($action === 'clear_uid') {
    $conn->query("UPDATE last_uid SET uid='' WHERE id=1");
    echo json_encode(["status" => "ok"]);
    exit;
}

echo json_encode(["status" => "error", "pesan" => "Action tidak dikenal"]);
?>
