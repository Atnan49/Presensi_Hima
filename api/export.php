<?php
// ============================================
// api/export.php
// Export data absensi ke Excel / Spreadsheet (.xls)
// GET?type=attendance&date=YYYY-MM-DD → export absensi tanggal tertentu
// GET?type=attendance&all=1           → export semua absensi
// GET?type=students                   → export daftar mahasiswa
// ============================================

require_once '../config.php';

$db   = getDB();
$type = $_GET['type'] ?? 'attendance';

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
      <!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Daftar Mahasiswa</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->
      <style>
        table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; }
        th { background-color: #4F8EF7; color: white; font-weight: bold; text-align: center; border: 1px solid #CCCCCC; padding: 8px; }
        td { border: 1px solid #CCCCCC; padding: 6px; text-align: left; }
        .text-center { text-align: center; }
        .nim { mso-number-format:"\@"; }
      </style>
    </head>
    <body>
      <h2>DAFTAR MAHASISWA TERDAFTAR</h2>
      <p>Tanggal Export: <?= date('d/m/Y H:i:s') ?></p>
      <table>
        <thead>
          <tr>
            <th>No</th>
            <th>Nama Mahasiswa</th>
            <th>NIM</th>
            <th>Total Hadir</th>
            <th>Terdaftar Sejak</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $i => $r): ?>
          <tr>
            <td class="text-center"><?= $i + 1 ?></td>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td class="nim"><?= htmlspecialchars($r['nim'] ?: '-') ?></td>
            <td class="text-center"><?= $r['total_hadir'] ?>x</td>
            <td><?= htmlspecialchars($r['created_at']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </body>
    </html>
    <?php
    exit;
}

// --- EXPORT REKAP ABSENSI ---
$date    = $_GET['date'] ?? date('Y-m-d');
$dateAll = isset($_GET['all']) && $_GET['all'] === '1';

if ($dateAll) {
    $stmt = $db->prepare("
        SELECT s.uid, s.name, s.nim,
               DATE_FORMAT(a.tap_time, '%d/%m/%Y') AS tanggal,
               DATE_FORMAT(a.tap_time, '%H:%i:%s') AS jam
        FROM attendance a
        JOIN students s ON s.id = a.student_id
        ORDER BY a.tap_time DESC
    ");
    $stmt->execute();
    $filename = 'Rekap_Absensi_Semua_' . date('Ymd_His') . '.xls';
    $title    = 'REKAP ABSENSI KESELURUHAN';
} else {
    $stmt = $db->prepare("
        SELECT s.uid, s.name, s.nim,
               DATE_FORMAT(a.tap_time, '%d/%m/%Y') AS tanggal,
               DATE_FORMAT(a.tap_time, '%H:%i:%s') AS jam
        FROM attendance a
        JOIN students s ON s.id = a.student_id
        WHERE a.tap_date = ?
        ORDER BY a.tap_time ASC
    ");
    $stmt->execute([$date]);
    $filename = 'Absensi_' . $date . '.xls';
    $title    = 'REKAP ABSENSI TANGGAL ' . date('d/m/Y', strtotime($date));
}

$rows = $stmt->fetchAll();

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF"; // UTF-8 BOM

?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
  <!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Absensi Mahasiswa</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->
  <style>
    table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; }
    th { background-color: #2563EB; color: white; font-weight: bold; text-align: center; border: 1px solid #CCCCCC; padding: 8px; }
    td { border: 1px solid #CCCCCC; padding: 6px; text-align: left; }
    .text-center { text-align: center; }
    .nim { mso-number-format:"\@"; }
    .txt { mso-number-format:"\@"; text-align: center; }
  </style>
</head>
<body>
  <h2><?= $title ?></h2>
  <p>Total Hadir: <strong><?= count($rows) ?> Mahasiswa</strong> | Di-download pada: <?= date('d/m/Y H:i:s') ?></p>
  <table>
    <thead>
      <tr>
        <th>No</th>
        <th>Nama Mahasiswa</th>
        <th>NIM</th>
        <th>Tanggal</th>
        <th>Jam Tap</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
      <tr>
        <td colspan="5" class="text-center">Tidak ada data absensi pada tanggal ini</td>
      </tr>
      <?php else: ?>
        <?php foreach ($rows as $i => $r): ?>
        <tr>
          <td class="text-center"><?= $i + 1 ?></td>
          <td><?= htmlspecialchars($r['name']) ?></td>
          <td class="nim"><?= htmlspecialchars($r['nim'] ?: '-') ?></td>
          <td class="txt"><?= htmlspecialchars($r['tanggal']) ?></td>
          <td class="txt"><?= htmlspecialchars($r['jam']) ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</body>
</html>
<?php
exit;
