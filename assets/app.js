// ============================================
// app.js - Sistem Presensi Mahasiswa
// Frontend Logic (Vanilla JS)
// ============================================

const API = {
  checkUID:     'api/check_uid.php',
  students:     'api/students.php',
  attendance:   'api/attendance.php',
  unknownCards: 'api/unknown_cards.php',
  export:       'api/export.php',
};

// ============================================
// STATE
// ============================================
let state = {
  currentPanel:   'dashboard',
  students:       [],
  attendance:     [],
  unknownCards:   [],
  selectedDate:   new Date().toISOString().slice(0, 10),
  addMode:        false,         // tombol "Tambah Peserta" aktif
  editingStudent: null,
  pollInterval:   null,
  totalMhs:       0,
  totalHadir:     0,
  lastTapName:    '',
  lastTapTime:    '',
};

// ============================================
// TOAST NOTIFICATIONS
// ============================================
function showToast(message, type = 'info') {
  const container = document.getElementById('toast-container');
  const icons = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️' };

  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;
  toast.innerHTML = `<span>${icons[type] || 'ℹ️'}</span><span>${message}</span>`;
  container.appendChild(toast);

  setTimeout(() => {
    toast.classList.add('hiding');
    setTimeout(() => toast.remove(), 300);
  }, 3500);
}

// ============================================
// NAVIGATION
// ============================================
function showPanel(panelId) {
  state.currentPanel = panelId;

  document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));

  document.getElementById('panel-' + panelId)?.classList.add('active');
  document.querySelector(`[data-panel="${panelId}"]`)?.classList.add('active');

  // Update page title
  const titles = {
    dashboard:   ['📊 Dashboard', 'Rekap absensi hari ini'],
    tambah:      ['➕ Tambah Peserta', 'Daftarkan kartu RFID baru'],
    mahasiswa:   ['👥 Data Mahasiswa', 'Kelola data mahasiswa & kartu RFID'],
    rekap:       ['📋 Rekap Absensi', 'Riwayat kehadiran mahasiswa'],
  };

  if (titles[panelId]) {
    document.getElementById('page-title').textContent    = titles[panelId][0];
    document.getElementById('page-subtitle').textContent = titles[panelId][1];
  }

  // Load data sesuai panel
  if (panelId === 'dashboard') loadDashboard();
  if (panelId === 'tambah')    loadUnknownCards();
  if (panelId === 'mahasiswa') loadStudents();
  if (panelId === 'rekap')     loadRekap();
}

// ============================================
// LIVE CLOCK
// ============================================
function updateClock() {
  const now = new Date();
  const opts = { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' };
  document.getElementById('live-date').textContent = now.toLocaleDateString('id-ID', opts);
  document.getElementById('live-time').textContent = now.toLocaleTimeString('id-ID');
}

// ============================================
// DASHBOARD
// ============================================
async function loadDashboard() {
  try {
    const res = await fetch(`${API.attendance}?date=${state.selectedDate}`);
    const data = await res.json();

    state.totalHadir = data.total_hadir || 0;
    state.totalMhs   = data.total_mhs   || 0;
    state.attendance = data.data        || [];

    // Update stat cards
    document.getElementById('stat-hadir').textContent  = state.totalHadir;
    document.getElementById('stat-total').textContent  = state.totalMhs;
    document.getElementById('stat-alpha').textContent  = Math.max(0, state.totalMhs - state.totalHadir);

    // Persentase kehadiran
    const pct = state.totalMhs > 0 ? Math.round((state.totalHadir / state.totalMhs) * 100) : 0;
    document.getElementById('stat-persen').textContent = pct + '%';
    document.getElementById('bar-fill').style.width    = pct + '%';

    // Render tabel absensi hari ini
    renderDashboardTable(state.attendance);

    // Live feed (tap terakhir)
    if (state.attendance.length > 0) {
      const last = state.attendance[state.attendance.length - 1];
      updateLiveFeed(last.name, last.waktu);
    }

    // Unknown cards badge
    const ukRes = await fetch(API.unknownCards);
    const ukData = await ukRes.json();
    state.unknownCards = ukData.data || [];
    const badge = document.getElementById('unknown-badge');
    if (state.unknownCards.length > 0) {
      badge.textContent = state.unknownCards.length;
      badge.style.display = 'inline';
    } else {
      badge.style.display = 'none';
    }
  } catch (e) {
    console.error('Dashboard load error:', e);
  }
}

function renderDashboardTable(records) {
  const tbody = document.getElementById('dashboard-tbody');
  if (!records || records.length === 0) {
    tbody.innerHTML = `
      <tr><td colspan="5">
        <div class="empty-state">
          <div class="empty-icon">📭</div>
          <div class="empty-text">Belum ada absensi hari ini</div>
          <div class="empty-sub">Data akan muncul otomatis saat mahasiswa tap kartu</div>
        </div>
      </td></tr>`;
    return;
  }

  tbody.innerHTML = records.map((r, i) => `
    <tr>
      <td>${i + 1}</td>
      <td><span class="td-uid">${r.uid}</span></td>
      <td class="td-name">${r.name}</td>
      <td>${r.nim || '-'}</td>
      <td>${r.waktu}</td>
    </tr>
  `).join('');
}

function updateLiveFeed(name, time) {
  const el = document.getElementById('latest-tap');
  el.innerHTML = `<span class="tap-name">🎉 ${name}</span> <span class="tap-time">• ${time}</span>`;
}

// ============================================
// TAMBAH PESERTA (Unknown Cards)
// ============================================
async function loadUnknownCards() {
  try {
    const res  = await fetch(API.unknownCards);
    const data = await res.json();
    state.unknownCards = data.data || [];
    renderUnknownCards(state.unknownCards);
  } catch (e) {
    console.error(e);
  }
}

function renderUnknownCards(cards) {
  const tbody = document.getElementById('unknown-tbody');
  if (!cards || cards.length === 0) {
    tbody.innerHTML = `
      <tr><td colspan="5">
        <div class="empty-state">
          <div class="empty-icon">✅</div>
          <div class="empty-text">Tidak ada kartu yang belum terdaftar</div>
          <div class="empty-sub">Semua kartu yang pernah di-tap sudah terdaftar</div>
        </div>
      </td></tr>`;
    return;
  }

  tbody.innerHTML = cards.map(c => `
    <tr>
      <td><span class="td-uid font-mono">${c.uid}</span></td>
      <td><span class="badge badge-warning">${c.tap_count}x tap</span></td>
      <td>${formatDate(c.first_seen)}</td>
      <td>${formatDate(c.last_seen)}</td>
      <td>
        <button class="btn btn-primary btn-xs" onclick="openRegisterModal('${c.uid}')">
          📝 Daftarkan
        </button>
      </td>
    </tr>
  `).join('');
}

function openRegisterModal(uid) {
  document.getElementById('reg-uid').value  = uid;
  document.getElementById('reg-name').value = '';
  document.getElementById('reg-nim').value  = '';
  document.getElementById('modal-register').classList.add('open');
  setTimeout(() => document.getElementById('reg-name').focus(), 200);
}

function closeRegisterModal() {
  document.getElementById('modal-register').classList.remove('open');
}

async function submitRegister() {
  const uid  = document.getElementById('reg-uid').value.trim();
  const name = document.getElementById('reg-name').value.trim();
  const nim  = document.getElementById('reg-nim').value.trim();

  if (!name) { showToast('Nama tidak boleh kosong', 'warning'); return; }

  try {
    const res = await fetch(API.students, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ uid, name, nim }),
    });
    const data = await res.json();

    if (data.success) {
      showToast(`✅ ${name} berhasil didaftarkan!`, 'success');
      closeRegisterModal();
      loadUnknownCards();
      loadDashboard();
    } else {
      showToast(data.message || 'Gagal mendaftarkan', 'error');
    }
  } catch (e) {
    showToast('Koneksi error', 'error');
  }
}

// ============================================
// TAMBAH MANUAL (tanpa tap kartu)
// ============================================
function openAddManualModal() {
  document.getElementById('reg-uid').value  = '';
  document.getElementById('reg-name').value = '';
  document.getElementById('reg-nim').value  = '';
  document.getElementById('modal-register').classList.add('open');
  document.getElementById('modal-reg-title').textContent = '➕ Tambah Mahasiswa Manual';
  setTimeout(() => document.getElementById('reg-uid').focus(), 200);
}

// ============================================
// DATA MAHASISWA
// ============================================
async function loadStudents(search = '') {
  try {
    const url = search ? `${API.students}?search=${encodeURIComponent(search)}` : API.students;
    const res  = await fetch(url);
    const data = await res.json();
    state.students = data.data || [];
    renderStudentsTable(state.students);
  } catch (e) {
    console.error(e);
  }
}

function renderStudentsTable(students) {
  const tbody = document.getElementById('students-tbody');
  if (!students || students.length === 0) {
    tbody.innerHTML = `
      <tr><td colspan="6">
        <div class="empty-state">
          <div class="empty-icon">👥</div>
          <div class="empty-text">Belum ada mahasiswa terdaftar</div>
          <div class="empty-sub">Tap kartu atau tambah manual untuk mendaftarkan</div>
        </div>
      </td></tr>`;
    return;
  }

  tbody.innerHTML = students.map((s, i) => `
    <tr>
      <td>${i + 1}</td>
      <td><span class="td-uid">${s.uid}</span></td>
      <td class="td-name">${s.name}</td>
      <td>${s.nim || '-'}</td>
      <td><span class="badge badge-${s.total_hadir > 0 ? 'success' : 'warning'}">${s.total_hadir}x hadir</span></td>
      <td>
        <div class="flex gap-2">
          <button class="btn btn-secondary btn-xs" onclick="openEditModal(${s.id})">✏️ Edit</button>
          <button class="btn btn-danger btn-xs"    onclick="deleteStudent(${s.id}, '${s.name}')">🗑️</button>
        </div>
      </td>
    </tr>
  `).join('');
}

function openEditModal(id) {
  const s = state.students.find(x => x.id == id);
  if (!s) return;

  state.editingStudent = s;
  document.getElementById('edit-id').value   = s.id;
  document.getElementById('edit-uid').value  = s.uid;
  document.getElementById('edit-name').value = s.name;
  document.getElementById('edit-nim').value  = s.nim || '';
  document.getElementById('modal-edit').classList.add('open');
}

function closeEditModal() {
  document.getElementById('modal-edit').classList.remove('open');
  state.editingStudent = null;
}

async function submitEdit() {
  const id   = parseInt(document.getElementById('edit-id').value);
  const uid  = document.getElementById('edit-uid').value.trim();
  const name = document.getElementById('edit-name').value.trim();
  const nim  = document.getElementById('edit-nim').value.trim();

  if (!name) { showToast('Nama tidak boleh kosong', 'warning'); return; }

  try {
    const res = await fetch(API.students, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id, uid, name, nim }),
    });
    const data = await res.json();

    if (data.success) {
      showToast('Data berhasil diperbarui', 'success');
      closeEditModal();
      loadStudents();
    } else {
      showToast(data.message || 'Gagal update', 'error');
    }
  } catch (e) {
    showToast('Koneksi error', 'error');
  }
}

async function deleteStudent(id, name) {
  if (!confirm(`Yakin hapus mahasiswa "${name}"?\nData absensinya juga akan ikut terhapus.`)) return;

  try {
    const res = await fetch(API.students, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id }),
    });
    const data = await res.json();

    if (data.success) {
      showToast(`${name} berhasil dihapus`, 'success');
      loadStudents();
    } else {
      showToast(data.message || 'Gagal hapus', 'error');
    }
  } catch (e) {
    showToast('Koneksi error', 'error');
  }
}

// ============================================
// REKAP ABSENSI
// ============================================
async function loadRekap() {
  const date = document.getElementById('rekap-date')?.value || state.selectedDate;
  try {
    const res  = await fetch(`${API.attendance}?date=${date}`);
    const data = await res.json();
    state.attendance = data.data || [];
    renderRekapTable(state.attendance, data);
  } catch (e) {
    console.error(e);
  }
}

function renderRekapTable(records, summary) {
  const tbody = document.getElementById('rekap-tbody');

  // Update summary
  if (summary) {
    document.getElementById('rekap-total-hadir').textContent = summary.total_hadir || 0;
    document.getElementById('rekap-total-mhs').textContent   = summary.total_mhs   || 0;
    const pct = summary.total_mhs > 0 ? Math.round((summary.total_hadir / summary.total_mhs) * 100) : 0;
    document.getElementById('rekap-persen').textContent      = pct + '%';
  }

  if (!records || records.length === 0) {
    tbody.innerHTML = `
      <tr><td colspan="6">
        <div class="empty-state">
          <div class="empty-icon">📋</div>
          <div class="empty-text">Tidak ada data absensi pada tanggal ini</div>
        </div>
      </td></tr>`;
    return;
  }

  tbody.innerHTML = records.map((r, i) => `
    <tr>
      <td>${i + 1}</td>
      <td><span class="td-uid">${r.uid}</span></td>
      <td class="td-name">${r.name}</td>
      <td>${r.nim || '-'}</td>
      <td>${r.waktu}</td>
      <td>
        <button class="btn btn-danger btn-xs" onclick="deleteAttendance(${r.id}, '${r.name}')">
          🗑️ Hapus
        </button>
      </td>
    </tr>
  `).join('');
}

async function deleteAttendance(id, name) {
  if (!confirm(`Yakin hapus data absensi milik "${name}"?`)) return;

  try {
    const res = await fetch(`${API.attendance}?action=delete&id=${id}`, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id }),
    });
    const data = await res.json();

    if (data.success) {
      showToast('✅ Data absensi berhasil dihapus', 'success');
      loadRekap();
      loadDashboard();
    } else {
      showToast(data.message || 'Gagal menghapus absensi', 'error');
    }
  } catch (e) {
    showToast('Koneksi error', 'error');
  }
}

async function clearRekapByDate() {
  const date = document.getElementById('rekap-date')?.value || state.selectedDate;
  if (!confirm(`Yakin hapus SEMUA data absensi pada tanggal ${date}?`)) return;

  try {
    const res = await fetch(`${API.attendance}?action=delete&date=${date}`, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ date }),
    });
    const data = await res.json();

    if (data.success) {
      showToast('✅ ' + data.message, 'success');
      loadRekap();
      loadDashboard();
    } else {
      showToast(data.message || 'Gagal menghapus rekap', 'error');
    }
  } catch (e) {
    showToast('Koneksi error', 'error');
  }
}

// ============================================
// EXPORT
// ============================================
function exportAttendance() {
  const date = document.getElementById('rekap-date')?.value || state.selectedDate;
  window.open(`${API.export}?type=attendance&date=${date}`, '_blank');
}

function exportAllAttendance() {
  window.open(`${API.export}?type=attendance&all=1`, '_blank');
}

function exportStudents() {
  window.open(`${API.export}?type=students`, '_blank');
}

// ============================================
// AUTO-REFRESH (Poll setiap 5 detik)
// ============================================
function startPolling() {
  if (state.pollInterval) clearInterval(state.pollInterval);
  state.pollInterval = setInterval(() => {
    if (state.currentPanel === 'dashboard') loadDashboard();
    if (state.currentPanel === 'tambah')    loadUnknownCards();
  }, 5000);
}

// ============================================
// HELPER: Format tanggal
// ============================================
function formatDate(str) {
  if (!str) return '-';
  const d = new Date(str);
  return d.toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' })
       + ' ' + d.toLocaleTimeString('id-ID', { hour:'2-digit', minute:'2-digit' });
}

// ============================================
// ESP STATUS CHECK
// ============================================
async function checkEspStatus() {
  const dot  = document.getElementById('esp-dot');
  const text = document.getElementById('esp-status-text');
  try {
    const res = await fetch('api/esp_status.php', { signal: AbortSignal.timeout(3000) });
    const data = await res.json();
    if (data.online) {
      dot.className  = 'esp-dot online';
      text.textContent = 'Online';
    } else {
      throw new Error('offline');
    }
  } catch {
    dot.className    = 'esp-dot offline';
    text.textContent = 'Offline';
  }
}

// ============================================
// INIT
// ============================================
document.addEventListener('DOMContentLoaded', () => {
  // Clock
  updateClock();
  setInterval(updateClock, 1000);

  // Set default rekap date ke hari ini
  const rekapDate = document.getElementById('rekap-date');
  if (rekapDate) rekapDate.value = state.selectedDate;

  // Load halaman dashboard
  showPanel('dashboard');

  // Polling auto-refresh
  startPolling();

  // ESP Status check setiap 10 detik
  setInterval(checkEspStatus, 10000);

  // ESC untuk tutup modal
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      closeRegisterModal();
      closeEditModal();
    }
  });

  // Close modal saat klik overlay
  document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) {
        closeRegisterModal();
        closeEditModal();
      }
    });
  });
});
