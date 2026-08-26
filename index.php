<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sistem Presensi Mahasiswa - ESP8266 + RFID</title>
  <meta name="description" content="Dashboard presensi mahasiswa berbasis RFID/NFC dengan ESP8266 dan PN532">
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<div class="app-wrapper">

  <!-- =================== SIDEBAR =================== -->
  <aside class="sidebar">
    <div class="sidebar-logo">
      <div class="logo-icon">📡</div>
      <h1>Sistem Presensi<br>Mahasiswa</h1>
      <div class="version">ESP8266 + PN532 • v1.0</div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-section-label">Menu Utama</div>

      <div class="nav-item active" data-panel="dashboard" onclick="showPanel('dashboard')">
        <span class="nav-icon">📊</span>
        <span>Dashboard</span>
      </div>

      <div class="nav-item" data-panel="tambah" onclick="showPanel('tambah')">
        <span class="nav-icon">➕</span>
        <span>Tambah Peserta</span>
        <span class="nav-badge" id="unknown-badge" style="display:none">0</span>
      </div>

      <div class="nav-item" data-panel="mahasiswa" onclick="showPanel('mahasiswa')">
        <span class="nav-icon">👥</span>
        <span>Data Mahasiswa</span>
      </div>

      <div class="nav-item" data-panel="rekap" onclick="showPanel('rekap')">
        <span class="nav-icon">📋</span>
        <span>Rekap Absensi</span>
      </div>
    </nav>

    <div class="sidebar-footer">
      <div class="esp-status">
        <div class="esp-dot" id="esp-dot"></div>
        <div class="esp-info">
          <div class="esp-label">Status ESP8266</div>
          <div class="esp-status-text" id="esp-status-text">Menghubungkan...</div>
        </div>
      </div>
    </div>
  </aside>

  <!-- =================== MAIN CONTENT =================== -->
  <main class="main-content">

    <!-- Top Bar -->
    <div class="top-bar">
      <div>
        <div class="page-title" id="page-title">📊 Dashboard</div>
        <div class="page-subtitle" id="page-subtitle">Rekap absensi hari ini</div>
      </div>
      <div class="top-bar-actions">
        <div class="datetime-display">
          <div class="date" id="live-date">—</div>
          <div class="time" id="live-time">—</div>
        </div>
      </div>
    </div>

    <!-- =========================================
         PANEL: DASHBOARD
         ========================================= -->
    <div class="panel active" id="panel-dashboard">

      <!-- Live Feed -->
      <div class="live-feed">
        <div class="live-feed-header">
          <div class="live-dot"></div>
          <span>Live • Auto Refresh</span>
        </div>
        <div class="latest-tap" id="latest-tap">
          <span style="color: var(--text-muted)">Menunggu tap kartu...</span>
        </div>
      </div>

      <!-- Stat Cards -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon green">✅</div>
          <div class="stat-info">
            <div class="stat-value" id="stat-hadir">0</div>
            <div class="stat-label">Hadir Hari Ini</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon blue">👥</div>
          <div class="stat-info">
            <div class="stat-value" id="stat-total">0</div>
            <div class="stat-label">Total Mahasiswa</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon orange">❌</div>
          <div class="stat-info">
            <div class="stat-value" id="stat-alpha">0</div>
            <div class="stat-label">Belum Hadir</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon purple">📈</div>
          <div class="stat-info">
            <div class="stat-value" id="stat-persen">0%</div>
            <div class="stat-label">Kehadiran</div>
          </div>
        </div>
      </div>

      <!-- Progress Bar -->
      <div class="card" style="padding: 18px 24px; margin-bottom: 20px;">
        <div class="flex items-center justify-between mb-1">
          <span class="text-sm text-secondary">Persentase Kehadiran Hari Ini</span>
          <span class="text-sm font-bold text-accent" id="stat-persen2">0%</span>
        </div>
        <div class="attendance-bar">
          <div class="attendance-bar-fill" id="bar-fill" style="width: 0%"></div>
        </div>
      </div>

      <!-- Tabel Absensi Hari Ini -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">📋 Absensi Hari Ini</div>
          <div class="flex gap-2">
            <button class="btn btn-secondary btn-sm" onclick="loadDashboard()">🔄 Refresh</button>
            <button class="btn btn-success btn-sm" onclick="exportAttendanceToday()">📊 Download Excel (Hari Ini)</button>
          </div>
        </div>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>No</th>
                <th>UID Kartu</th>
                <th>Nama</th>
                <th>NIM</th>
                <th>Jam Tap</th>
              </tr>
            </thead>
            <tbody id="dashboard-tbody">
              <tr><td colspan="5"><div class="empty-state"><div class="empty-icon">⏳</div><div class="empty-text">Memuat data...</div></div></td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <!-- END PANEL DASHBOARD -->

    <!-- =========================================
         PANEL: TAMBAH PESERTA
         ========================================= -->
    <div class="panel" id="panel-tambah">

      <div class="alert-box alert-info" style="margin-bottom: 20px;">
        <span class="alert-icon">💡</span>
        <div class="alert-content">
          <div class="alert-title">Cara Mendaftarkan Mahasiswa</div>
          <div class="alert-desc">
            Tap kartu RFID ke reader → kartu muncul di tabel di bawah →
            klik <strong>Daftarkan</strong> → isi Nama & NIM → simpan.<br>
            Atau klik <strong>Tambah Manual</strong> jika tahu UID kartu.
          </div>
        </div>
      </div>

      <!-- Tombol Tambah Manual -->
      <div class="flex gap-2 mb-2" style="margin-bottom: 20px;">
        <button class="btn btn-primary" onclick="openAddManualModal()">➕ Tambah Manual</button>
        <button class="btn btn-secondary" onclick="loadUnknownCards()">🔄 Refresh</button>
      </div>

      <!-- Tabel Kartu Belum Terdaftar -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">
            ⚠️ Kartu Belum Terdaftar
            <span class="badge badge-warning" id="unknown-count-badge">0 kartu</span>
          </div>
        </div>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>UID Kartu</th>
                <th>Tap Count</th>
                <th>Pertama Tap</th>
                <th>Terakhir Tap</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody id="unknown-tbody">
              <tr><td colspan="5"><div class="empty-state"><div class="empty-icon">⏳</div><div class="empty-text">Memuat...</div></div></td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <!-- END PANEL TAMBAH -->

    <!-- =========================================
         PANEL: DATA MAHASISWA
         ========================================= -->
    <div class="panel" id="panel-mahasiswa">
      <div class="card">
        <div class="card-header">
          <div class="card-title">👥 Daftar Mahasiswa</div>
          <div class="flex gap-2 items-center">
            <div class="search-box">
              <span class="search-icon">🔍</span>
              <input type="search" id="search-students" placeholder="Cari nama, NIM, atau UID..."
                     oninput="loadStudents(this.value)">
            </div>
            <button class="btn btn-primary btn-sm" onclick="openAddManualModal()">➕ Tambah</button>
            <button class="btn btn-success btn-sm" onclick="exportStudents()">⬇️ Export</button>
          </div>
        </div>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>No</th>
                <th>UID Kartu</th>
                <th>Nama</th>
                <th>NIM</th>
                <th>Total Hadir</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody id="students-tbody">
              <tr><td colspan="6"><div class="empty-state"><div class="empty-icon">⏳</div><div class="empty-text">Memuat...</div></div></td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <!-- END PANEL MAHASISWA -->

    <!-- =========================================
         PANEL: REKAP ABSENSI
         ========================================= -->
    <div class="panel" id="panel-rekap">

      <!-- Filter & Stats -->
      <div class="card" style="padding: 18px 24px; margin-bottom: 20px;">
        <div class="flex items-center gap-3" style="flex-wrap: wrap;">
          <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 180px;">
            <label class="form-label">Pilih Tanggal</label>
            <input type="date" id="rekap-date" onchange="loadRekap()">
          </div>
          <div style="flex: 1; min-width: 120px; text-align: center;">
            <div class="text-xs text-muted">Hadir</div>
            <div class="font-bold" style="font-size: 24px; color: var(--success)" id="rekap-total-hadir">0</div>
          </div>
          <div style="flex: 1; min-width: 120px; text-align: center;">
            <div class="text-xs text-muted">Total Mhs</div>
            <div class="font-bold" style="font-size: 24px; color: var(--accent)" id="rekap-total-mhs">0</div>
          </div>
          <div style="flex: 1; min-width: 120px; text-align: center;">
            <div class="text-xs text-muted">Persentase</div>
            <div class="font-bold" style="font-size: 24px; color: var(--primary)" id="rekap-persen">0%</div>
          </div>
          <div class="flex gap-2" style="align-self: flex-end; padding-bottom: 2px;">
            <button class="btn btn-success btn-sm" onclick="exportAttendance()">📊 Download Excel (Tanggal Ini)</button>
            <button class="btn btn-secondary btn-sm" onclick="exportAllAttendance()">📊 Download Semua (Excel)</button>
          </div>
        </div>
      </div>

      <!-- Tabel Rekap -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">📋 Data Kehadiran</div>
          <div class="flex gap-2">
            <button class="btn btn-danger btn-sm" onclick="clearRekapByDate()">🗑️ Hapus Rekap Tanggal Ini</button>
            <button class="btn btn-secondary btn-sm" onclick="loadRekap()">🔄 Refresh</button>
          </div>
        </div>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>No</th>
                <th>UID Kartu</th>
                <th>Nama</th>
                <th>NIM</th>
                <th>Waktu Tap</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody id="rekap-tbody">
              <tr><td colspan="6"><div class="empty-state"><div class="empty-icon">⏳</div><div class="empty-text">Pilih tanggal untuk melihat rekap</div></div></td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <!-- END PANEL REKAP -->

  </main>
</div>

<!-- =========================================
     MODAL: Register / Tambah Peserta
     ========================================= -->
<div class="modal-overlay" id="modal-register">
  <div class="modal-box">
    <div class="modal-title" id="modal-reg-title">📝 Daftarkan Mahasiswa</div>

    <div class="form-group">
      <label class="form-label">UID Kartu RFID *</label>
      <input type="text" id="reg-uid" placeholder="Contoh: A1B2C3D4" style="font-family: monospace; text-transform: uppercase;">
      <div class="text-xs text-muted mt-1">UID otomatis terisi dari tap kartu, atau isi manual</div>
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
      <button class="btn btn-primary" onclick="submitRegister()">💾 Simpan</button>
    </div>
  </div>
</div>

<!-- =========================================
     MODAL: Edit Mahasiswa
     ========================================= -->
<div class="modal-overlay" id="modal-edit">
  <div class="modal-box">
    <div class="modal-title">✏️ Edit Data Mahasiswa</div>

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
      <button class="btn btn-primary" onclick="submitEdit()">💾 Update</button>
    </div>
  </div>
</div>

<!-- Toast Notifications -->
<div class="toast-container" id="toast-container"></div>

<script src="assets/app.js"></script>
<script>
  // Export hari ini (dari dashboard)
  function exportAttendanceToday() {
    const today = new Date().toISOString().slice(0, 10);
    window.open(`api/export.php?type=attendance&date=${today}`, '_blank');
  }
</script>

</body>
</html>
