<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sistem Presensi Mahasiswa - RFID / ESP8266</title>
  <meta name="description" content="Dashboard Presensi Mahasiswa Berbasis RFID dan IoT ESP8266 dengan Sistem Desain Neo-Brutalism">
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<div class="app-wrapper">

  <!-- =================== SIDEBAR =================== -->
  <aside class="sidebar">
    <div class="sidebar-logo">
      <div class="logo-box">RFID</div>
      <h1>Sistem Presensi<br>Mahasiswa</h1>
      <div class="version">ESP8266 + RFID • v1.0</div>
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
    </nav>

    <div class="sidebar-footer">
      <div class="esp-status">
        <div class="esp-dot" id="esp-dot"></div>
        <div class="esp-info">
          <div class="esp-label">Perangkat IoT</div>
          <div class="esp-status-text" id="esp-status-text">Menghubungkan...</div>
        </div>
      </div>
    </div>
  </aside>

  <!-- =================== MAIN CONTENT =================== -->
  <main class="main-content">

    <!-- Top Bar -->
    <header class="top-bar">
      <div>
        <h1 class="page-title" id="page-title">Dashboard</h1>
        <div class="page-subtitle" id="page-subtitle">Rekap absensi kehadiran hari ini</div>
      </div>
      <div class="top-bar-actions">
        <div class="datetime-display">
          <div class="date" id="live-date">—</div>
          <div class="time" id="live-time">—</div>
        </div>
      </div>
    </header>

    <!-- =========================================
         PANEL: DASHBOARD
         ========================================= -->
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
        <div class="stat-card">
          <div class="stat-info">
            <div class="stat-label">Hadir Hari Ini</div>
            <div class="stat-value" id="stat-hadir">0</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-info">
            <div class="stat-label">Total Mahasiswa</div>
            <div class="stat-value" id="stat-total">0</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-info">
            <div class="stat-label">Belum Hadir</div>
            <div class="stat-value" id="stat-alpha">0</div>
          </div>
        </div>
        <div class="stat-card">
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
            <button class="btn btn-primary btn-sm" onclick="exportAttendanceToday()">Download Excel (Hari Ini)</button>
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
                <th>Jam Tap</th>
              </tr>
            </thead>
            <tbody id="dashboard-tbody">
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
    <!-- END PANEL DASHBOARD -->

    <!-- =========================================
         PANEL: TAMBAH PESERTA
         ========================================= -->
    <section class="panel" id="panel-tambah">

      <div class="alert-box alert-info" style="margin-bottom: 24px;">
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
        <button class="btn btn-primary" onclick="openAddManualModal()">Tambah Manual</button>
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
                <th>UID Kartu</th>
                <th>Jumlah Tap</th>
                <th>Pertama Kali Terdeteksi</th>
                <th>Terakhir Terdeteksi</th>
                <th>Aksi</th>
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

    <!-- =========================================
         PANEL: DATA MAHASISWA
         ========================================= -->
    <section class="panel" id="panel-mahasiswa">
      <div class="card">
        <div class="card-header">
          <div class="card-title">Daftar Mahasiswa Terdaftar</div>
          <div class="flex gap-2 items-center" style="flex-wrap: wrap;">
            <div class="search-box">
              <input type="search" id="search-students" placeholder="Cari nama, NIM, atau UID..."
                     oninput="loadStudents(this.value)">
            </div>
            <button class="btn btn-primary btn-sm" onclick="openAddManualModal()">Tambah</button>
            <button class="btn btn-secondary btn-sm" onclick="exportStudents()">Export Data</button>
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

    <!-- =========================================
         PANEL: REKAP ABSENSI
         ========================================= -->
    <section class="panel" id="panel-rekap">

      <!-- Filter & Stats -->
      <div class="card" style="padding: 20px; margin-bottom: 24px;">
        <div class="flex items-center gap-3" style="flex-wrap: wrap;">
          <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 180px;">
            <label class="form-label">Pilih Tanggal Presensi</label>
            <input type="date" id="rekap-date" onchange="loadRekap()">
          </div>
          <div class="stat-card" style="flex: 1; min-width: 140px; margin-bottom: 0; padding: 12px 16px; box-shadow: var(--shadow-sm);">
            <div>
              <div class="text-xs text-muted font-bold" style="text-transform: uppercase;">Total Hadir</div>
              <div class="font-mono font-bold" style="font-size: 24px; color: var(--text-main);" id="rekap-total-hadir">0</div>
            </div>
          </div>
          <div class="stat-card" style="flex: 1; min-width: 140px; margin-bottom: 0; padding: 12px 16px; box-shadow: var(--shadow-sm);">
            <div>
              <div class="text-xs text-muted font-bold" style="text-transform: uppercase;">Total Mahasiswa</div>
              <div class="font-mono font-bold" style="font-size: 24px; color: var(--text-main);" id="rekap-total-mhs">0</div>
            </div>
          </div>
          <div class="stat-card" style="flex: 1; min-width: 140px; margin-bottom: 0; padding: 12px 16px; box-shadow: var(--shadow-sm);">
            <div>
              <div class="text-xs text-muted font-bold" style="text-transform: uppercase;">Persentase</div>
              <div class="font-mono font-bold" style="font-size: 24px; color: var(--text-main);" id="rekap-persen">0%</div>
            </div>
          </div>
          <div class="flex gap-2" style="align-self: flex-end; padding-bottom: 2px;">
            <button class="btn btn-primary btn-sm" onclick="exportAttendance()">Download Excel (Tanggal Ini)</button>
            <button class="btn btn-secondary btn-sm" onclick="exportAllAttendance()">Download Semua (Excel)</button>
          </div>
        </div>
      </div>

      <!-- Tabel Rekap Kehadiran -->
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
                <th>Waktu Tap</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody id="rekap-tbody">
              <tr>
                <td colspan="6">
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

  </main>
</div>

<!-- =========================================
     MODAL: Register / Tambah Peserta
     ========================================= -->
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

<!-- =========================================
     MODAL: Edit Mahasiswa
     ========================================= -->
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

<!-- Toast Notifications Container -->
<div class="toast-container" id="toast-container"></div>

<script src="assets/app.js"></script>
<script type="module" src="assets/firebase-service.js"></script>
<script>
  // Export data kehadiran hari ini langsung dari dashboard
  function exportAttendanceToday() {
    const today = new Date().toISOString().slice(0, 10);
    window.open(`api/export.php?type=attendance&date=${today}`, '_blank');
  }
</script>

</body>
</html>
