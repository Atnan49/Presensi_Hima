<?php
require_once 'config.php';
checkAuth();
$currentUser = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sistem Presensi HIMATIF - RFID / ESP8266</title>
  <meta name="description" content="Dashboard Presensi Mahasiswa Berbasis RFID dan IoT ESP8266 HIMATIF UMS">
  <link rel="icon" type="image/x-icon" href="assets/Image/favicon.ico?v=2">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/Image/favicon-32x32.png?v=2">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/Image/favicon-16x16.png?v=2">
  <link rel="apple-touch-icon" sizes="180x180" href="assets/Image/apple-touch-icon.png?v=2">
  <link rel="manifest" href="site.webmanifest">
  <meta name="csrf-token" content="<?= htmlspecialchars(generateCsrfToken()) ?>">
  <link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: time() ?>">
</head>
<body>

<div class="app-wrapper">

  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="sidebar-logo">
      <div class="sidebar-brand">
        <img src="assets/Image/logo-himatif-light.png" alt="Logo HIMATIF UMS" class="sidebar-logo-img">
        <div class="sidebar-brand-text">
          <span class="sidebar-brand-badge">HIMATIF UMS</span>
          <h1 class="sidebar-brand-title">Sistem Presensi</h1>
        </div>
      </div>
      <div class="version">ESP8266 + RFID • Proker HIMA</div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-section-label">Navigasi Utama</div>

      <div class="nav-item active" data-panel="dashboard" onclick="showPanel('dashboard')">
        <span class="nav-indicator">[01]</span>
        <span>Dashboard</span>
      </div>

      <div class="nav-item" data-panel="tambah" onclick="showPanel('tambah')">
        <span class="nav-indicator">[02]</span>
        <span>Tambah Peserta</span>
        <span class="nav-badge" id="unknown-badge" style="display:none">0</span>
      </div>

      <div class="nav-item" data-panel="mahasiswa" onclick="showPanel('mahasiswa')">
        <span class="nav-indicator">[03]</span>
        <span>Data Mahasiswa</span>
      </div>

      <div class="nav-item" data-panel="rekap" onclick="showPanel('rekap')">
        <span class="nav-indicator">[04]</span>
        <span>Rekap Absensi</span>
      </div>

      <div class="nav-item" data-panel="events" onclick="showPanel('events')">
        <span class="nav-indicator">[05]</span>
        <span>Program Kerja</span>
      </div>
    </nav>

    <div class="sidebar-footer">
      <!-- Admin Profile Box -->
      <div class="admin-profile-box">
        <div class="admin-avatar">AD</div>
        <div class="admin-details">
          <div class="admin-name"><?= htmlspecialchars($currentUser['name'] ?? 'Administrator') ?></div>
          <div class="admin-role">@<?= htmlspecialchars($currentUser['username'] ?? 'admin') ?></div>
        </div>
        <div class="flex gap-1" style="margin-left: auto;">
          <button type="button" class="btn btn-secondary btn-xs" onclick="openChangePasswordModal()" title="Ubah Password Admin">
            KUNCI
          </button>
          <a href="logout.php" class="btn btn-danger btn-xs" title="Keluar dari sistem">
            KELUAR
          </a>
        </div>
      </div>

      <div class="esp-status">
        <div class="esp-dot" id="esp-dot"></div>
        <div class="esp-info">
          <div class="esp-label">Perangkat IoT</div>
          <div class="esp-status-text" id="esp-status-text">Menghubungkan...</div>
        </div>
      </div>
    </div>
  </aside>

  <!-- Main content -->
  <main class="main-content">

    <!-- Top Bar -->
    <header class="top-bar">
      <div>
        <h1 class="page-title" id="page-title">Dashboard</h1>
        <div class="page-subtitle" id="page-subtitle">Rekap absensi kehadiran hari ini</div>
      </div>
      <div class="top-bar-actions">
        <!-- Active Event Badge -->
        <div class="active-event-badge" id="active-event-badge" onclick="showPanel('events')" style="cursor: pointer; display: inline-flex; align-items: center; gap: 8px; background: var(--color-yellow); border: 2px solid #000; padding: 6px 12px; font-size: 11px; font-weight: 800; box-shadow: 2px 2px 0px #000;" title="Klik untuk kelola Program Kerja / Acara">
          <span style="background: #000; color: #fff; padding: 2px 6px; font-size: 10px; letter-spacing: 0.05em;">ACARA</span>
          <span id="active-event-title">Memuat acara...</span>
          <span id="active-event-date" class="font-mono text-xs" style="color: #333;"></span>
        </div>

        <!-- Active Session Selector -->
        <div class="session-selector-box" style="display: inline-flex; align-items: center; gap: 6px; background: #ffffff; border: 2px solid #000; padding: 4px 8px; box-shadow: 2px 2px 0px #000;">
          <span style="font-size: 10px; font-weight: 800; background: #000; color: #fff; padding: 2px 6px; letter-spacing: 0.05em;">SESI</span>
          <select id="select-active-session" onchange="onSessionChange(this.value)" style="border: none; background: transparent; font-family: var(--font-mono); font-size: 11px; font-weight: 700; cursor: pointer; padding: 2px 4px;">
            <option value="sesi_1">Sesi 1 (Datang / Pagi)</option>
            <option value="sesi_2">Sesi 2 (Setelah Ishoma / Siang)</option>
            <option value="sesi_3">Sesi 3 (Pulang / Penutupan)</option>
          </select>
        </div>

        <button class="btn btn-secondary btn-xs audio-toggle-btn" id="audio-toggle-btn" onclick="toggleAudioChime()" title="Aktifkan atau nonaktifkan notifikasi suara tap">
          <span id="audio-status-label">Suara: Aktif</span>
        </button>
        <div class="datetime-display">
          <div class="date" id="live-date">-</div>
          <div class="time" id="live-time">-</div>
        </div>
      </div>
    </header>

    <!-- Panel: Dashboard -->
    <section class="panel active" id="panel-dashboard">

      <!-- Live Feed -->
      <div class="live-feed">
        <div class="live-feed-header">
          <div class="live-dot"></div>
          <span>Status • Live Feed</span>
        </div>
        <div class="latest-tap" id="latest-tap">
          <span class="text-muted">Menunggu tap kartu RFID...</span>
        </div>
      </div>

      <!-- Stat Cards -->
      <div class="stats-grid">
        <div class="stat-card stat-card-success">
          <div class="stat-info">
            <div class="stat-label">Hadir Hari Ini</div>
            <div class="stat-value" id="stat-hadir">0</div>
          </div>
        </div>
        <div class="stat-card stat-card-primary">
          <div class="stat-info">
            <div class="stat-label">Total Mahasiswa</div>
            <div class="stat-value" id="stat-total">0</div>
          </div>
        </div>
        <div class="stat-card stat-card-danger" onclick="openAlphaModal()" style="cursor: pointer;" title="Klik untuk melihat daftar anggota yang belum hadir (Alpha)">
          <div class="stat-info">
            <div class="stat-label">Belum Hadir</div>
            <div class="stat-value" id="stat-alpha">0</div>
            <div class="font-mono text-xs mt-1" style="font-weight: 700; text-decoration: underline; color: var(--color-red);">Lihat daftar alpha</div>
          </div>
        </div>
        <div class="stat-card stat-card-warning">
          <div class="stat-info">
            <div class="stat-label">Tingkat Kehadiran</div>
            <div class="stat-value" id="stat-persen">0%</div>
          </div>
        </div>
      </div>

      <!-- Progress Bar Card -->
      <div class="card" style="padding: 20px;">
        <div class="flex items-center justify-between mb-1">
          <span class="text-sm font-bold text-secondary" style="text-transform: uppercase; letter-spacing: 0.05em;">Persentase Kehadiran Hari Ini</span>
          <span class="text-sm font-bold font-mono" id="stat-persen2">0%</span>
        </div>
        <div class="attendance-bar">
          <div class="attendance-bar-fill" id="bar-fill" style="width: 0%"></div>
        </div>
      </div>

      <!-- Tabel Absensi Hari Ini -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">Absensi Hari Ini</div>
          <div class="flex gap-2">
            <button class="btn btn-secondary btn-sm" onclick="loadDashboard()">Refresh</button>
            <button class="btn btn-primary btn-sm" onclick="exportAttendanceToday('csv')">Download CSV (Hari Ini)</button>
            <button class="btn btn-secondary btn-sm" onclick="exportAttendanceToday('excel')">Download Excel</button>
          </div>
        </div>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>No</th>
                <th>UID Kartu</th>
                <th>Nama Mahasiswa</th>
                <th>NIM</th>
                <th>Sesi</th>
                <th>Jam Tap</th>
              </tr>
            </thead>
            <tbody id="dashboard-tbody">
              <tr>
                <td colspan="6">
                  <div class="empty-state">
                    <div class="empty-text">Memuat data...</div>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>
    <!-- END PANEL DASHBOARD -->

    <!-- Panel: Tambah peserta -->
    <section class="panel" id="panel-tambah">

      <div class="alert-box alert-warning" style="margin-bottom: 24px;">
        <div class="alert-content">
          <div class="alert-title">Panduan Registrasi Kartu Mahasiswa</div>
          <div class="alert-desc">
            1. Tempelkan kartu RFID ke alat pembaca (reader).<br>
            2. Kartu baru akan otomatis muncul pada tabel di bawah ini.<br>
            3. Klik tombol <strong>"Daftarkan"</strong> lalu isi Nama Lengkap &amp; NIM.<br>
            4. Anda juga dapat memilih <strong>"Tambah Manual"</strong> bila UID kartu sudah diketahui.
          </div>
        </div>
      </div>

      <!-- Tombol Aksi Tambah -->
      <div class="flex gap-2 mb-3">
        <button class="btn btn-warning" onclick="openAddManualModal()">+ Tambah Manual</button>
        <button class="btn btn-secondary" onclick="loadUnknownCards()">Refresh Kartu</button>
      </div>

      <!-- Tabel Kartu Belum Terdaftar -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">
            Kartu Belum Terdaftar
            <span class="badge badge-warning" id="unknown-count-badge">0 kartu</span>
          </div>
        </div>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th style="width: 50px;">No</th>
                <th>UID Kartu</th>
                <th>Jumlah Tap</th>
                <th>Terakhir Terdeteksi</th>
                <th style="width: 130px;">Aksi</th>
              </tr>
            </thead>
            <tbody id="unknown-tbody">
              <tr>
                <td colspan="5">
                  <div class="empty-state">
                    <div class="empty-text">Memuat data...</div>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>
    <!-- END PANEL TAMBAH -->

    <!-- Panel: Data mahasiswa -->
    <section class="panel" id="panel-mahasiswa">
      <div class="card">
        <div class="card-header">
          <div class="card-title">Daftar Mahasiswa Terdaftar</div>
          <div class="flex gap-2 items-center" style="flex-wrap: wrap;">
            <div class="search-box">
              <input type="search" id="search-students" placeholder="Cari nama, NIM, atau UID..."
                     oninput="loadStudents(this.value)">
            </div>
            <button class="btn btn-warning btn-sm" onclick="openAddManualModal()">+ Tambah</button>
            <button class="btn btn-primary btn-sm" onclick="openImportModal()">+ Import CSV/Excel</button>
            <button class="btn btn-secondary btn-sm" onclick="exportStudents('csv')">Download CSV</button>
            <button class="btn btn-secondary btn-sm" onclick="exportStudents('excel')">Download Excel</button>
          </div>
        </div>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>No</th>
                <th>UID Kartu</th>
                <th>Nama Mahasiswa</th>
                <th>NIM</th>
                <th>Total Hadir</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody id="students-tbody">
              <tr>
                <td colspan="6">
                  <div class="empty-state">
                    <div class="empty-text">Memuat data mahasiswa...</div>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>
    <!-- END PANEL MAHASISWA -->

    <!-- Panel: Rekap absensi -->
    <section class="panel" id="panel-rekap">

      <!-- 1. Filter Tanggal & Export Actions Bar -->
      <div class="card" style="padding: 16px 20px; margin-bottom: 20px;">
        <div class="flex items-center justify-between gap-3" style="flex-wrap: wrap;">
          <!-- Date & Session Selector -->
          <div class="flex items-center gap-2" style="flex-wrap: wrap;">
            <label class="form-label" style="margin-bottom: 0; white-space: nowrap; font-size: 12px;">Tanggal:</label>
            <input type="date" id="rekap-date" onchange="loadRekap()" style="width: auto; min-width: 140px; padding: 6px 10px; font-size: 12px;">
            <button class="btn btn-secondary btn-sm" onclick="setQuickDate('today')" type="button">Hari Ini</button>
            <button class="btn btn-secondary btn-sm" onclick="setQuickDate('yesterday')" type="button">Kemarin</button>

            <label class="form-label" style="margin-bottom: 0; white-space: nowrap; font-size: 12px; margin-left: 6px;">Filter Sesi:</label>
            <select id="rekap-session-filter" onchange="loadRekap()" style="width: auto; min-width: 130px; padding: 6px 8px; font-size: 12px; font-family: var(--font-mono); border: 2px solid #000;">
              <option value="">Semua Sesi</option>
              <option value="sesi_1">Sesi 1 (Datang)</option>
              <option value="sesi_2">Sesi 2 (Ishoma)</option>
              <option value="sesi_3">Sesi 3 (Pulang)</option>
            </select>
          </div>

          <!-- Download Action Buttons -->
          <div class="flex gap-2" style="flex-wrap: wrap;">
            <button class="btn btn-primary btn-sm" onclick="exportAttendance('csv')">Download CSV</button>
            <button class="btn btn-secondary btn-sm" onclick="exportAttendance('excel')">Download Excel</button>
            <button class="btn btn-secondary btn-sm" onclick="exportAllAttendance('csv')">Download Semua (CSV)</button>
          </div>
        </div>
      </div>

      <!-- 2. Stat Cards Summary (3 Kolom Sejajar & Simetris) -->
      <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-bottom: 20px;">
        <div class="stat-card stat-card-success">
          <div class="stat-info">
            <div class="stat-label">Total Hadir</div>
            <div class="stat-value" id="rekap-total-hadir">0</div>
          </div>
        </div>
        <div class="stat-card stat-card-primary">
          <div class="stat-info">
            <div class="stat-label">Total Mahasiswa</div>
            <div class="stat-value" id="rekap-total-mhs">0</div>
          </div>
        </div>
        <div class="stat-card stat-card-warning">
          <div class="stat-info">
            <div class="stat-label">Persentase Kehadiran</div>
            <div class="stat-value" id="rekap-persen">0%</div>
          </div>
        </div>
      </div>

      <!-- 3. Tabel Rekap Kehadiran -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">Log Data Kehadiran</div>
          <div class="flex gap-2">
            <button class="btn btn-danger btn-sm" onclick="clearRekapByDate()">Hapus Rekap Tanggal Ini</button>
            <button class="btn btn-secondary btn-sm" onclick="loadRekap()">Refresh</button>
          </div>
        </div>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>No</th>
                <th>UID Kartu</th>
                <th>Nama Mahasiswa</th>
                <th>NIM</th>
                <th>Sesi Presensi</th>
                <th>Waktu Tap</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody id="rekap-tbody">
              <tr>
                <td colspan="7">
                  <div class="empty-state">
                    <div class="empty-text">Pilih tanggal untuk melihat rekap</div>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>
    <!-- END PANEL REKAP -->

    <!-- Panel: Program kerja dan acara -->
    <section class="panel" id="panel-events">
      <div class="card">
        <div class="card-header">
          <div>
            <div class="card-title">Manajemen Program Kerja &amp; Acara</div>
            <div class="text-xs text-muted font-mono mt-1">Pilih acara yang sedang berlangsung agar rekap absensi terhubung dengan kegiatan</div>
          </div>
          <div class="flex gap-2 items-center" style="flex-wrap: wrap;">
            <button class="btn btn-warning btn-sm" onclick="openCreateEventModal()">+ Buat Acara Baru</button>
            <button class="btn btn-secondary btn-sm" onclick="loadEvents()">Refresh Acara</button>
          </div>
        </div>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>No</th>
                <th>Nama Program Kerja / Acara</th>
                <th>Tanggal Pelaksanaan</th>
                <th>Total Hadir</th>
                <th>Status</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody id="events-tbody">
              <tr>
                <td colspan="6">
                  <div class="empty-state">
                    <div class="empty-text">Memuat data program kerja...</div>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>
    <!-- END PANEL PROGRAM KERJA -->

  </main>
</div>

<!-- Modal: Register peserta -->
<div class="modal-overlay" id="modal-register">
  <div class="modal-box">
    <div class="modal-title" id="modal-reg-title">Daftarkan Mahasiswa</div>

    <div class="form-group">
      <label class="form-label">UID Kartu RFID *</label>
      <input type="text" id="reg-uid" placeholder="Contoh: A1B2C3D4" style="font-family: monospace; text-transform: uppercase;">
      <div class="text-xs text-muted mt-1 font-mono">UID otomatis terisi dari tap kartu, atau masukkan manual.</div>
    </div>

    <div class="form-group">
      <label class="form-label">Nama Mahasiswa *</label>
      <input type="text" id="reg-name" placeholder="Nama lengkap mahasiswa">
    </div>

    <div class="form-group">
      <label class="form-label">NIM (Nomor Induk Mahasiswa)</label>
      <input type="text" id="reg-nim" placeholder="Contoh: 2023001">
    </div>

    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="closeRegisterModal()">Batal</button>
      <button class="btn btn-primary" onclick="submitRegister()">Simpan Data</button>
    </div>
  </div>
</div>

<!-- Modal: Edit data mahasiswa -->
<div class="modal-overlay" id="modal-edit">
  <div class="modal-box">
    <div class="modal-title">Edit Data Mahasiswa</div>

    <input type="hidden" id="edit-id">

    <div class="form-group">
      <label class="form-label">UID Kartu RFID</label>
      <input type="text" id="edit-uid" placeholder="UID Kartu" style="font-family: monospace; text-transform: uppercase;">
    </div>

    <div class="form-group">
      <label class="form-label">Nama Mahasiswa *</label>
      <input type="text" id="edit-name" placeholder="Nama lengkap">
    </div>

    <div class="form-group">
      <label class="form-label">NIM</label>
      <input type="text" id="edit-nim" placeholder="NIM">
    </div>

    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="closeEditModal()">Batal</button>
      <button class="btn btn-primary" onclick="submitEdit()">Simpan Perubahan</button>
    </div>
  </div>
</div>

<!-- Modal: Buat program kerja dan acara -->
<div class="modal-overlay" id="modal-create-event">
  <div class="modal-box">
    <div class="modal-title">Buat Program Kerja / Acara Baru</div>

    <div class="form-group">
      <label class="form-label">Nama Acara / Program Kerja *</label>
      <input type="text" id="event-name" placeholder="Contoh: Rapat Pleno 1, Makrab HIMA, Workshop IT">
    </div>

    <div class="form-group">
      <label class="form-label">Tanggal Pelaksanaan</label>
      <input type="date" id="event-date">
    </div>

    <div class="form-group">
      <label class="form-label">Keterangan / Deskripsi Singkat</label>
      <input type="text" id="event-desc" placeholder="Contoh: Wajib untuk seluruh pengurus divisi">
    </div>

    <div class="form-group" style="margin-top: 12px;">
      <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; cursor: pointer;">
        <input type="checkbox" id="event-active" checked style="width: 18px; height: 18px; accent-color: #000;">
        Langsung jadikan acara aktif saat ini
      </label>
      <div class="text-xs text-muted font-mono mt-1">Perangkat RFID dan web akan langsung mencatat presensi untuk acara ini.</div>
    </div>

    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="closeCreateEventModal()">Batal</button>
      <button class="btn btn-primary" onclick="submitCreateEvent()">Simpan Acara</button>
    </div>
  </div>
</div>

<!-- Modal: Daftar anggota belum hadir -->
<div class="modal-overlay" id="modal-alpha">
  <div class="modal-box" style="max-width: 650px; width: 95%;">
    <div class="flex items-center justify-between" style="border-bottom: 2px solid #000; padding-bottom: 12px; margin-bottom: 16px;">
      <div>
        <div class="modal-title" style="margin-bottom: 4px; color: var(--color-red);">Daftar Anggota Belum Hadir (Alpha)</div>
        <div class="text-xs font-mono text-muted" id="alpha-date-title">Tanggal: Hari Ini</div>
      </div>
      <span class="badge badge-danger font-mono" id="alpha-count-badge">0 ANGGOTA</span>
    </div>

    <div class="table-wrapper" style="max-height: 340px; overflow-y: auto; border: 2px solid #000; margin-bottom: 16px;">
      <table>
        <thead>
          <tr>
            <th>No</th>
            <th>Nama Anggota</th>
            <th>NIM</th>
            <th>UID Kartu</th>
          </tr>
        </thead>
        <tbody id="alpha-tbody">
          <tr><td colspan="4"><div class="empty-state"><div class="empty-text">Memuat...</div></div></td></tr>
        </tbody>
      </table>
    </div>

    <div class="modal-actions" style="display: flex; justify-content: space-between; align-items: center;">
      <button class="btn btn-success btn-sm" onclick="copyAlphaListToWhatsApp()">
        Salin Format WhatsApp
      </button>
      <button class="btn btn-secondary btn-sm" onclick="closeAlphaModal()">
        Tutup
      </button>
    </div>
  </div>
</div>

<!-- Modal: Import batch mahasiswa -->
<div class="modal-overlay" id="modal-import">
  <div class="modal-box" style="max-width: 600px; width: 95%;">
    <div class="modal-title">Import Massal Data Mahasiswa</div>
    <div class="text-xs text-muted font-mono mb-3">Upload file CSV atau Excel (.xlsx) dengan kolom: <strong>UID</strong>, <strong>Nama</strong>, dan <strong>NIM</strong>.</div>

    <div class="form-group">
      <input type="file" id="import-file-input" accept=".csv, .xlsx, .xls" onchange="handleImportFile(event)"
             style="border: 2px dashed #000; padding: 20px; width: 100%; background: var(--bg-alt); cursor: pointer; text-align: center;">
    </div>

    <div class="flex items-center justify-between mb-2">
      <div class="font-mono text-xs font-bold">Preview Data Terdeteksi:</div>
      <span class="badge badge-primary font-mono" id="import-count-badge">0 DATA</span>
    </div>

    <div class="table-wrapper" style="max-height: 220px; overflow-y: auto; border: 2px solid #000; margin-bottom: 16px;">
      <table>
        <thead>
          <tr>
            <th>No</th>
            <th>UID</th>
            <th>Nama</th>
            <th>NIM</th>
          </tr>
        </thead>
        <tbody id="import-preview-tbody">
          <tr>
            <td colspan="4">
              <div class="empty-state">
                <div class="empty-text">PILIH FILE TERLEBIH DAHULU</div>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="closeImportModal()">Batal</button>
      <button class="btn btn-primary" id="btn-submit-import" onclick="submitBatchImport()" disabled>Mulai Import</button>
    </div>
  </div>
</div>

<!-- Modal: Ubah password admin -->
<div class="modal-overlay" id="modal-password">
  <div class="modal-box" style="max-width: 440px;">
    <div class="modal-title">Ubah Password Administrator</div>
    <div class="text-xs text-muted font-mono mb-3">Pastikan password baru kuat dan mudah Anda ingat.</div>

    <form id="form-change-password" onsubmit="event.preventDefault(); submitChangePassword();">
      <div class="form-group">
        <label class="form-label" for="pwd-old">Password Lama *</label>
        <input type="password" id="pwd-old" placeholder="Masukkan password lama" autocomplete="current-password" required>
      </div>

      <div class="form-group">
        <label class="form-label" for="pwd-new">Password Baru * (Min. 6 karakter)</label>
        <input type="password" id="pwd-new" placeholder="Masukkan password baru" autocomplete="new-password" required>
      </div>

      <div class="form-group">
        <label class="form-label" for="pwd-confirm">Konfirmasi Password Baru *</label>
        <input type="password" id="pwd-confirm" placeholder="Ulangi password baru" autocomplete="new-password" required>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="closeChangePasswordModal()">Batal</button>
        <button type="submit" class="btn btn-warning">Simpan Password</button>
      </div>
    </form>
  </div>
</div>

<!-- Toast Notifications Container -->
<div class="toast-container" id="toast-container"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="assets/app.js?v=<?= @filemtime(__DIR__ . '/assets/app.js') ?: time() ?>"></script>
<script type="module" src="assets/firebase-service.js?v=<?= @filemtime(__DIR__ . '/assets/firebase-service.js') ?: time() ?>"></script>
</body>
</html>
