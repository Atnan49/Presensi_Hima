<?php
// API export data presensi format CSV dan Excel

require_once '../config.php';

// Pastikan hanya admin yang terautentikasi yang dapat mengakses ekspor data
if (!isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>401 Unauthorized</title><style>body{font-family:sans-serif;padding:40px;text-align:center;background:#0f172a;color:#f8fafc;}a{color:#38bdf8;}</style></head><body>';
    echo '<h2>401 - Akses Ditolak</h2>';
    echo '<p>Sesi login diperlukan untuk mengunduh laporan presensi mahasiswa.</p>';
    echo '<p><a href="../login.php">Klik di sini untuk Login Admin</a></p>';
    echo '</body></html>';
    exit;
}

$type   = $_GET['type']   ?? 'attendance';
$format = $_GET['format'] ?? 'xls';

try {
    $db = getDB();
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Gagal menghubungkan ke database MySQL lokal: " . $e->getMessage() . "\n";
    echo "Tip: Jika Anda menggunakan Firebase Cloud, gunakan tombol download langsung dari antarmuka Web Dashboard.\n";
    exit;
}

// Helper pencegah CSV / Excel Formula Injection
function sanitizeCsvFormula($val) {
    if ($val === null || $val === '') return '-';
    $str = (string)$val;
    if (isset($str[0]) && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $str;
    }
    return $str;
}

// --- EXPORT DAFTAR MAHASISWA ---
if ($type === 'students') {
    $stmt = $db->query("
        SELECT s.uid, s.name, s.nim,
               (SELECT COUNT(*) FROM attendance WHERE student_id = s.id) AS total_hadir,
               s.created_at
        FROM students s
        WHERE s.is_active = 1
        ORDER BY s.name ASC
    ");
    $rows = $stmt->fetchAll();

    if ($format === 'csv') {
        $filename = 'Daftar_Mahasiswa_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM
        $out = fopen('php://output', 'w');
        fputcsv($out, ['# SISTEM PRESENSI MAHASISWA RFID (HIMA)']);
        fputcsv($out, ['# MASTER DATA MAHASISWA TERDAFTAR']);
        fputcsv($out, ['# TOTAL: ' . count($rows) . ' Mahasiswa']);
        fputcsv($out, ['# WAKTU CETAK: ' . date('d/m/Y H:i:s')]);
        fputcsv($out, ['']);
        fputcsv($out, ['NO', 'UID KARTU', 'NAMA MAHASISWA', 'NIM', 'TOTAL HADIR', 'TERDAFTAR SEJAK']);
        foreach ($rows as $i => $r) {
            fputcsv($out, [
                $i + 1,
                sanitizeCsvFormula($r['uid']),
                sanitizeCsvFormula($r['name']),
                sanitizeCsvFormula($r['nim'] ?: '-'),
                $r['total_hadir'] . 'x',
                sanitizeCsvFormula($r['created_at'])
            ]);
        }
        fclose($out);
        exit;
    }

    $filename = 'Daftar_Mahasiswa_' . date('Ymd_His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    ?>
    <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
    <head>
      <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
      <style>
        body { font-family: 'Segoe UI', Calibri, Arial, sans-serif; margin: 0; padding: 20px; color: #0f172a; }
        .kop-box { border-bottom: 3px double #0f172a; padding-bottom: 12px; margin-bottom: 18px; }
        .inst-label { font-size: 10pt; font-weight: bold; color: #475569; letter-spacing: 0.12em; text-transform: uppercase; }
        .main-title { font-size: 18pt; font-weight: 900; color: #0f172a; margin-top: 3px; }
        .sub-title { font-size: 11pt; color: #64748b; margin-top: 4px; font-weight: 600; }
        
        table.data-table { border-collapse: collapse; width: 100%; margin-top: 15px; }
        table.data-table th { background-color: #0f172a; color: #ffffff; font-weight: bold; text-align: center; border: 1px solid #1e293b; padding: 12px 10px; font-size: 10pt; text-transform: uppercase; }
        table.data-table td { border: 1px solid #cbd5e1; padding: 10px 12px; font-size: 10pt; vertical-align: middle; }
        table.data-table tr:nth-child(even) { background-color: #f8fafc; }
        
        .text-center { text-align: center; }
        .td-uid { font-family: 'Consolas', monospace; font-weight: bold; background-color: #f1f5f9; text-align: center; mso-number-format:"\@"; }
        .nim { mso-number-format:"\@"; text-align: center; font-family: 'Consolas', monospace; }
        
        .footer-sign { margin-top: 40px; width: 100%; border-collapse: collapse; }
        .footer-sign td { border: none; padding: 10px; font-size: 10pt; }
      </style>
    </head>
    <body>
      <div class="kop-box">
        <div class="inst-label">HIMPUNAN MAHASISWA (HIMA) - SISTEM PRESENSI RFID</div>
        <div class="main-title">DAFTAR MAHASISWA TERDAFTAR</div>
        <div class="sub-title">Master Data Kartu RFID &amp; Identitas Mahasiswa</div>
        <div style="font-size: 9pt; color: #64748b; margin-top: 6px;">Total: <strong><?= count($rows) ?> Mahasiswa</strong> | Tanggal Export: <?= date('d/m/Y H:i:s') ?></div>
      </div>

      <table class="data-table">
        <thead>
          <tr>
            <th>No</th>
            <th>UID Kartu</th>
            <th>Nama Mahasiswa</th>
            <th>NIM</th>
            <th>Total Hadir</th>
            <th>Terdaftar Sejak</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $i => $r): ?>
          <tr>
            <td class="text-center" style="font-weight: bold;"><?= $i + 1 ?></td>
            <td class="td-uid"><?= htmlspecialchars($r['uid']) ?></td>
            <td style="font-weight: 600;"><?= htmlspecialchars($r['name']) ?></td>
            <td class="nim"><?= htmlspecialchars($r['nim'] ?: '-') ?></td>
            <td class="text-center" style="font-weight: bold; color: #0284c7;"><?= $r['total_hadir'] ?>x</td>
            <td class="text-center"><?= htmlspecialchars($r['created_at']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <table class="footer-sign">
        <tr>
          <td style="width: 60%;"></td>
          <td style="width: 40%; text-align: center;">
            <div>Petugas / Admin Presensi</div>
            <div style="height: 60px;"></div>
            <div style="font-weight: bold; border-bottom: 1px solid #0f172a; display: inline-block; padding-bottom: 2px; min-width: 170px;">( .................................................. )</div>
          </td>
        </tr>
      </table>
    </body>
    </html>
    <?php
    exit;
}

// --- EXPORT REKAP ABSENSI ---
$dateRaw   = trim($_GET['date'] ?? date('Y-m-d'));
$date      = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw) ? $dateRaw : date('Y-m-d');
$dateAll   = isset($_GET['all']) && $_GET['all'] === '1';
$eventId   = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;

// Validasi session_id hanya nilai yang diizinkan
$rawSessionId = trim($_GET['session_id'] ?? '');
$allowedSessions = ['sesi_1', 'sesi_2', 'sesi_3'];
$sessionId = in_array($rawSessionId, $allowedSessions, true) ? $rawSessionId : '';

$sessionLabel = '';
if ($sessionId === 'sesi_1') $sessionLabel = 'Sesi 1 (Datang)';
elseif ($sessionId === 'sesi_2') $sessionLabel = 'Sesi 2 (Ishoma)';
elseif ($sessionId === 'sesi_3') $sessionLabel = 'Sesi 3 (Pulang)';

// Ambil nama acara / program kerja aktif atau yang difilter
$activeEventName = '';
if ($eventId > 0) {
    $evStmt = $db->prepare("SELECT name FROM events WHERE id = ?");
    $evStmt->execute([$eventId]);
    $activeEventName = $evStmt->fetchColumn() ?: '';
}
if (empty($activeEventName)) {
    $evActive = $db->query("SELECT name FROM events WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($evActive) $activeEventName = $evActive;
}
$fallbackProker = !empty($activeEventName) ? $activeEventName : 'Kegiatan HIMA';
$prokerLabel    = 'Program Kerja: ' . $fallbackProker;

if ($dateAll) {
    $sql = "
        SELECT s.uid, s.name, s.nim,
               COALESCE(e.name, ?) AS proker_name,
               COALESCE(a.session_name, 'Sesi 1 (Datang)') AS session_name,
               DATE_FORMAT(a.tap_time, '%d/%m/%Y') AS tanggal,
               DATE_FORMAT(a.tap_time, '%H:%i') AS jam
        FROM attendance a
        JOIN students s ON s.id = a.student_id
        LEFT JOIN events e ON e.id = a.event_id
    ";
    $params = [$fallbackProker];
    $whereParts = [];
    if (!empty($sessionId)) {
        $whereParts[] = "a.session_id = ?";
        $params[] = $sessionId;
    }
    if ($eventId > 0) {
        $whereParts[] = "a.event_id = ?";
        $params[] = $eventId;
    }
    if (!empty($whereParts)) {
        $sql .= " WHERE " . implode(' AND ', $whereParts);
    }
    $sql .= " ORDER BY a.tap_time DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $filenameBase = 'Rekap_Absensi_Semua_' . date('Ymd_His');
    $title        = 'REKAPITULASI KESELURUHAN PRESENSI';
    $subtitle     = 'Semua Riwayat Kehadiran Mahasiswa' . (!empty($sessionLabel) ? " (Filter: {$sessionLabel})" : '');
} else {
    $sql = "
        SELECT s.uid, s.name, s.nim,
               COALESCE(e.name, ?) AS proker_name,
               COALESCE(a.session_name, 'Sesi 1 (Datang)') AS session_name,
               DATE_FORMAT(a.tap_time, '%d/%m/%Y') AS tanggal,
               DATE_FORMAT(a.tap_time, '%H:%i') AS jam
        FROM attendance a
        JOIN students s ON s.id = a.student_id
        LEFT JOIN events e ON e.id = a.event_id
        WHERE a.tap_date = ?
    ";
    $params = [$fallbackProker, $date];
    if (!empty($sessionId)) {
        $sql .= " AND a.session_id = ?";
        $params[] = $sessionId;
    }
    if ($eventId > 0) {
        $sql .= " AND a.event_id = ?";
        $params[] = $eventId;
    }
    $sql .= " ORDER BY a.tap_time ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $filenameBase = 'Absensi_' . $date . (!empty($sessionId) ? "_{$sessionId}" : '');
    $title        = 'REKAPITULASI PRESENSI MAHASISWA';
    $subtitle     = 'Tanggal: ' . date('d/m/Y', strtotime($date)) . (!empty($sessionLabel) ? " | Sesi: {$sessionLabel}" : '');
}

$rows = $stmt->fetchAll();

if ($format === 'csv') {
    $filename = $filenameBase . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    $out = fopen('php://output', 'w');
    fputcsv($out, ['# SISTEM PRESENSI MAHASISWA RFID (HIMA)']);
    fputcsv($out, ['# ' . $title]);
    fputcsv($out, ['# ' . $subtitle]);
    fputcsv($out, ['# ' . $prokerLabel]);
    fputcsv($out, ['# TOTAL HADIR: ' . count($rows) . ' Mahasiswa']);
    fputcsv($out, ['# WAKTU CETAK: ' . date('d/m/Y H:i:s')]);
    fputcsv($out, ['']);
    fputcsv($out, ['NO', 'NAMA MAHASISWA', 'NIM', 'PROGRAM KERJA', 'STATUS KEHADIRAN', 'SESI PRESENSI', 'TANGGAL', 'JAM TAP']);
    foreach ($rows as $i => $r) {
        fputcsv($out, [
            $i + 1,
            sanitizeCsvFormula($r['name']),
            sanitizeCsvFormula($r['nim'] ?: '-'),
            sanitizeCsvFormula($r['proker_name']),
            'HADIR',
            sanitizeCsvFormula($r['session_name']),
            sanitizeCsvFormula($r['tanggal']),
            sanitizeCsvFormula($r['jam'])
        ]);
    }
    fclose($out);
    exit;
}

$filename = $filenameBase . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');
echo "\xEF\xBB\xBF"; // UTF-8 BOM

?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
  <style>
    body { font-family: 'Segoe UI', Calibri, Arial, sans-serif; margin: 0; padding: 20px; color: #0f172a; }
    .kop-box { border-bottom: 3px double #0f172a; padding-bottom: 12px; margin-bottom: 18px; }
    .inst-label { font-size: 10pt; font-weight: bold; color: #475569; letter-spacing: 0.12em; text-transform: uppercase; }
    .main-title { font-size: 18pt; font-weight: 900; color: #0f172a; margin-top: 3px; }
    .sub-title { font-size: 11pt; color: #64748b; margin-top: 4px; font-weight: 600; }
    
    table.data-table { border-collapse: collapse; width: 100%; margin-top: 15px; }
    table.data-table th { background-color: #0f172a; color: #ffffff; font-weight: bold; text-align: center; border: 1px solid #1e293b; padding: 12px 10px; font-size: 10pt; text-transform: uppercase; }
    table.data-table td { border: 1px solid #cbd5e1; padding: 10px 12px; font-size: 10pt; vertical-align: middle; }
    table.data-table tr:nth-child(even) { background-color: #f8fafc; }
    
    .text-center { text-align: center; }
    .td-uid { font-family: 'Consolas', monospace; font-weight: bold; background-color: #f1f5f9; text-align: center; mso-number-format:"\@"; }
    .nim { mso-number-format:"\@"; text-align: center; font-family: 'Consolas', monospace; }
    .badge-hadir { background-color: #dcfce7; color: #15803d; font-weight: bold; text-align: center; border: 1px solid #86efac; }
    
    .footer-sign { margin-top: 40px; width: 100%; border-collapse: collapse; }
    .footer-sign td { border: none; padding: 10px; font-size: 10pt; }
  </style>
</head>
<body>
  <div class="kop-box">
    <div class="inst-label">HIMPUNAN MAHASISWA (HIMA) - SISTEM PRESENSI RFID</div>
    <div class="main-title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="sub-title"><?= htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') ?> &bull; <strong><?= htmlspecialchars($prokerLabel, ENT_QUOTES, 'UTF-8') ?></strong></div>
    <div style="font-size: 9pt; color: #64748b; margin-top: 6px;">Total Hadir: <strong><?= count($rows) ?> Mahasiswa</strong> | Tanggal Export: <?= date('d/m/Y H:i:s') ?></div>
  </div>

  <table class="data-table">
    <thead>
      <tr>
        <th>No</th>
        <th>Nama Mahasiswa</th>
        <th>NIM</th>
        <th>Program Kerja</th>
        <th>Status Kehadiran</th>
        <th>Sesi Presensi</th>
        <th>Tanggal</th>
        <th>Jam Tap</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
      <tr>
        <td colspan="8" class="text-center" style="padding: 20px; color: #94a3b8;">Tidak ada data absensi pada tanggal ini</td>
      </tr>
      <?php else: ?>
        <?php foreach ($rows as $i => $r): ?>
        <tr>
          <td class="text-center" style="font-weight: bold;"><?= $i + 1 ?></td>
          <td style="font-weight: 600;"><?= htmlspecialchars($r['name']) ?></td>
          <td class="nim"><?= htmlspecialchars($r['nim'] ?: '-') ?></td>
          <td style="font-weight: 500;"><?= htmlspecialchars($r['proker_name']) ?></td>
          <td class="badge-hadir">HADIR</td>
          <td class="text-center" style="font-weight: 600;"><?= htmlspecialchars($r['session_name']) ?></td>
          <td class="text-center"><?= htmlspecialchars($r['tanggal']) ?></td>
          <td class="text-center" style="font-weight: bold;"><?= htmlspecialchars($r['jam']) ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <table class="footer-sign">
    <tr>
      <td style="width: 60%;"></td>
      <td style="width: 40%; text-align: center;">
        <div>Petugas / Admin Presensi</div>
        <div style="height: 60px;"></div>
        <div style="font-weight: bold; border-bottom: 1px solid #0f172a; display: inline-block; padding-bottom: 2px; min-width: 170px;">( .................................................. )</div>
      </td>
    </tr>
  </table>
</body>
</html>
<?php
exit;
