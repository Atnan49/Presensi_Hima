// ============================================
// SISTEM PRESENSI MAHASISWA - FRONTEND SCRIPT
// Wireframe / Neo-Brutalist Architecture
// Cloud Firebase + Local MySQL Unified Sync
// ============================================

// State aplikasi
const state = {
  currentPanel: 'dashboard',
  selectedDate: new Date().toISOString().split('T')[0],
  students: [],
  attendance: [],
  unknownCards: [],
  totalHadir: 0,
  totalMhs: 0,
  pollInterval: null,
  editingStudent: null,
};

// API Endpoint lokal
const API = {
  attendance:   'api/attendance.php',
  students:     'api/students.php',
  unknownCards: 'api/unknown_cards.php',
  export:       'api/export.php',
};

// ============================================
// NAVIGATION / TAB SWITCHER
// ============================================
function showPanel(name) {
  state.currentPanel = name;

  // Toggle active class di nav items
  document.querySelectorAll('.nav-item').forEach(el => {
    el.classList.toggle('active', el.dataset.panel === name);
  });

  // Toggle active class di panel sections
  document.querySelectorAll('.panel-section').forEach(el => {
    el.classList.remove('active');
  });
  const target = document.getElementById(`panel-${name}`);
  if (target) target.classList.add('active');

  // Load data sesuai panel yang aktif
  if (name === 'dashboard') loadDashboard();
  if (name === 'tambah')    loadUnknownCards();
  if (name === 'mahasiswa') loadStudents();
  if (name === 'rekap')     loadRekap();
}

// ============================================
// CLOCK / REALTIME TIME DISPLAY
// ============================================
function updateClock() {
  const now  = new Date();
  const opts = { weekday: 'long', day: 'numeric', month: 'short', year: 'numeric' };
  const liveDate = document.getElementById('live-date');
  const liveTime = document.getElementById('live-time');
  
  if (liveDate) liveDate.textContent = now.toLocaleDateString('id-ID', opts).toUpperCase();
  if (liveTime) liveTime.textContent = now.toLocaleTimeString('id-ID');
}

// ============================================
// DASHBOARD
// ============================================
async function loadDashboard() {
  // 1. Prioritaskan data dari Firebase Cloud
  if (window.isFirebaseConnected && window.cloudUsers) {
    const cloudUserKeys = Object.keys(window.cloudUsers || {});
    state.totalMhs = cloudUserKeys.length;
    
    // Ambil log kehadiran hari ini dari cloudLogs
    const logs = window.cloudLogs || [];
    const uniqueUids = new Set(logs.map(l => l.uid));
    state.totalHadir = uniqueUids.size;
    state.attendance = logs;

    updateDashboardUI();
    return;
  }

  // 2. Fallback API lokal MySQL
  try {
    const res = await fetch(`${API.attendance}?date=${state.selectedDate}`);
    const data = await res.json();

    state.totalHadir = data.total_hadir || 0;
    state.totalMhs   = data.total_mhs   || 0;
    state.attendance = data.data        || [];

    updateDashboardUI();
  } catch (e) {
    console.error('Dashboard load error:', e);
    updateDashboardUI();
  }
}

function updateDashboardUI() {
  const elHadir = document.getElementById('stat-hadir');
  const elTotal = document.getElementById('stat-total');
  const elAlpha = document.getElementById('stat-alpha');
  const elPersen = document.getElementById('stat-persen');
  const elPersen2 = document.getElementById('stat-persen2');
  const elBarFill = document.getElementById('bar-fill');

  if (elHadir) elHadir.textContent = state.totalHadir;
  if (elTotal) elTotal.textContent = state.totalMhs;
  if (elAlpha) elAlpha.textContent = Math.max(0, state.totalMhs - state.totalHadir);

  // Persentase kehadiran
  const pct = state.totalMhs > 0 ? Math.round((state.totalHadir / state.totalMhs) * 100) : 0;
  if (elPersen) elPersen.textContent = pct + '%';
  if (elPersen2) elPersen2.textContent = pct + '%';
  if (elBarFill) elBarFill.style.width = pct + '%';

  // Render tabel absensi hari ini
  renderDashboardTable(state.attendance);

  // Live feed (tap terakhir)
  if (state.attendance.length > 0) {
    const last = state.attendance[state.attendance.length - 1];
    updateLiveFeed(last.name, last.waktu);
  }

  // Unknown cards badge
  const unknownList = window.cloudUnknownCards || state.unknownCards || [];
  const badge = document.getElementById('unknown-badge');
  if (badge) {
    if (unknownList.length > 0) {
      badge.textContent = unknownList.length;
      badge.style.display = 'inline-block';
    } else {
      badge.style.display = 'none';
    }
  }
}

function renderDashboardTable(records) {
  const tbody = document.getElementById('dashboard-tbody');
  if (!tbody) return;

  if (!records || records.length === 0) {
    tbody.innerHTML = `
      <tr><td colspan="5">
        <div class="empty-state">
          <div class="empty-text">BELUM ADA ABSENSI HARI INI</div>
          <div class="empty-sub">Data akan muncul secara real-time saat kartu RFID di-tap</div>
        </div>
      </td></tr>`;
    return;
  }

  tbody.innerHTML = records.map((r, i) => `
    <tr>
      <td class="font-mono font-bold">${i + 1}</td>
      <td><span class="td-uid font-mono">${r.uid}</span></td>
      <td class="td-name">${r.name}</td>
      <td class="font-mono">${r.nim || '-'}</td>
      <td class="font-mono">${r.waktu}</td>
    </tr>
  `).join('');
}

function updateLiveFeed(name, time) {
  const el = document.getElementById('latest-tap');
  if (!el) return;
  el.innerHTML = `<span class="tap-name">[HADIR] ${name}</span> <span class="tap-time">• ${time} WIB</span>`;
}

// ============================================
// TAMBAH PESERTA (Unknown Cards)
// ============================================
async function loadUnknownCards() {
  // 1. Cek apakah ada data kartu belum terdaftar dari Firebase Cloud
  if (window.cloudUnknownCards && window.cloudUnknownCards.length > 0) {
    state.unknownCards = window.cloudUnknownCards;
    renderUnknownCards(state.unknownCards);
    return;
  }

  // 2. Fallback cek ke API lokal MySQL
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
  const countBadge = document.getElementById('unknown-count-badge');
  if (countBadge) countBadge.textContent = `${cards.length} KARTU`;
  if (!tbody) return;

  if (!cards || cards.length === 0) {
    tbody.innerHTML = `
      <tr><td colspan="5">
        <div class="empty-state">
          <div class="empty-text">SEMUA KARTU SUDAH TERDAFTAR</div>
          <div class="empty-sub">Tidak ada kartu RFID tidak dikenal yang sedang menunggu</div>
        </div>
      </td></tr>`;
    return;
  }

  tbody.innerHTML = cards.map(c => `
    <tr>
      <td><span class="td-uid font-mono">${c.uid}</span></td>
      <td><span class="badge badge-warning font-mono">${c.tap_count}x TAP</span></td>
      <td class="font-mono text-sm">${formatDate(c.first_seen)}</td>
      <td class="font-mono text-sm">${formatDate(c.last_seen)}</td>
      <td>
        <button class="btn btn-primary btn-xs" onclick="openRegisterModal('${c.uid}')">
          DAFTARKAN
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
  setTimeout(() => document.getElementById('reg-name').focus(), 150);
}

function closeRegisterModal() {
  document.getElementById('modal-register').classList.remove('open');
}

async function submitRegister() {
  const uid  = document.getElementById('reg-uid').value.trim();
  const name = document.getElementById('reg-name').value.trim();
  const nim  = document.getElementById('reg-nim').value.trim();

  if (!name) { showToast('Nama mahasiswa tidak boleh kosong', 'warning'); return; }

  // 1. Simpan ke Firebase Cloud (agar ESP8266 langsung mengenali kartu)
  if (typeof window.registerUserToFirebase === 'function') {
    await window.registerUserToFirebase(uid, name, nim);
  }

  // 2. Simpan ke database lokal MySQL jika API aktif
  try {
    const res = await fetch(API.students, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ uid, name, nim }),
    });
    const data = await res.json();
    if (data.success) {
      console.log('MySQL student registered');
    }
  } catch (e) {
    console.warn('MySQL API notice:', e);
  }

  showToast(`${name} berhasil didaftarkan`, 'success');
  closeRegisterModal();
  loadUnknownCards();
  loadDashboard();
  loadStudents();
}

// ============================================
// TAMBAH MANUAL (tanpa tap kartu)
// ============================================
function openAddManualModal() {
  document.getElementById('reg-uid').value  = '';
  document.getElementById('reg-name').value = '';
  document.getElementById('reg-nim').value  = '';
  document.getElementById('modal-register').classList.add('open');
  document.getElementById('modal-reg-title').textContent = 'Tambah Mahasiswa Manual';
  setTimeout(() => document.getElementById('reg-uid').focus(), 150);
}

// ============================================
// DATA MAHASISWA
// ============================================
async function loadStudents(search = '') {
  // 1. Jika ada data mahasiswa di Firebase Cloud
  if (window.isFirebaseConnected && window.cloudUsers) {
    let list = Object.keys(window.cloudUsers).map(uid => {
      const u = window.cloudUsers[uid];
      const tapCount = (window.cloudLogs || []).filter(l => l.uid === uid).length;
      return {
        id: uid,
        uid: uid,
        name: u.name || 'Mahasiswa',
        nim: u.nim || '-',
        total_hadir: tapCount,
        created_at: u.registered_at ? new Date(u.registered_at).toISOString() : new Date().toISOString()
      };
    });

    if (search) {
      const q = search.toLowerCase();
      list = list.filter(s => s.name.toLowerCase().includes(q) || s.nim.toLowerCase().includes(q) || s.uid.toLowerCase().includes(q));
    }

    state.students = list;
    renderStudentsTable(state.students);
    return;
  }

  // 2. Fallback API lokal MySQL
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
  if (!tbody) return;

  if (!students || students.length === 0) {
    tbody.innerHTML = `
      <tr><td colspan="6">
        <div class="empty-state">
          <div class="empty-text">BELUM ADA MAHASISWA TERDAFTAR</div>
          <div class="empty-sub">Silakan tap kartu RFID atau gunakan tombol Tambah Manual</div>
        </div>
      </td></tr>`;
    return;
  }

  tbody.innerHTML = students.map((s, i) => `
    <tr>
      <td class="font-mono font-bold">${i + 1}</td>
      <td><span class="td-uid font-mono">${s.uid}</span></td>
      <td class="td-name">${s.name}</td>
      <td class="font-mono">${s.nim || '-'}</td>
      <td><span class="badge ${s.total_hadir > 0 ? 'badge-success' : 'badge-info'} font-mono">${s.total_hadir}x HADIR</span></td>
      <td>
        <div class="flex gap-2">
          <button class="btn btn-secondary btn-xs" onclick="openEditModal('${s.id}')">EDIT</button>
          <button class="btn btn-danger btn-xs" onclick="deleteStudent('${s.id}', '${s.name}')">HAPUS</button>
        </div>
      </td>
    </tr>
  `).join('');
}

function openEditModal(id) {
  const s = state.students.find(x => x.id == id || x.uid == id);
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
  const uid  = document.getElementById('edit-uid').value.trim();
  const name = document.getElementById('edit-name').value.trim();
  const nim  = document.getElementById('edit-nim').value.trim();

  if (!name) { showToast('Nama mahasiswa tidak boleh kosong', 'warning'); return; }

  // 1. Update ke Firebase Cloud
  if (typeof window.registerUserToFirebase === 'function') {
    await window.registerUserToFirebase(uid, name, nim);
  }

  // 2. Update ke MySQL jika aktif
  try {
    const id = parseInt(document.getElementById('edit-id').value) || 0;
    await fetch(API.students, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id, uid, name, nim }),
    });
  } catch (e) {
    console.warn('MySQL update notice:', e);
  }

  showToast('Data mahasiswa berhasil diperbarui', 'success');
  closeEditModal();
  loadStudents();
  loadDashboard();
}

async function deleteStudent(id, name) {
  if (!confirm(`Yakin hapus data mahasiswa "${name}"?\nData kartu akan dihapus dari sistem.`)) return;

  // 1. Hapus dari Firebase Cloud
  if (typeof window.deleteUserFromFirebase === 'function') {
    await window.deleteUserFromFirebase(id);
  }

  // 2. Hapus dari MySQL jika aktif
  try {
    await fetch(API.students, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id }),
    });
  } catch (e) {
    console.warn('MySQL delete notice:', e);
  }

  showToast(`Mahasiswa "${name}" berhasil dihapus`, 'success');
  loadStudents();
  loadDashboard();
}

// ============================================
// REKAP ABSENSI
// ============================================
async function loadRekap() {
  const date = document.getElementById('rekap-date')?.value || state.selectedDate;

  // 1. Jika ada data presensi di Firebase Cloud
  if (window.isFirebaseConnected && window.cloudLogs && window.cloudLogs.length > 0) {
    state.attendance = window.cloudLogs;
    const summary = {
      total_hadir: new Set(window.cloudLogs.map(l => l.uid)).size,
      total_mhs: Object.keys(window.cloudUsers || {}).length
    };
    renderRekapTable(state.attendance, summary);
    return;
  }

  // 2. Fallback ke MySQL
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
  if (!tbody) return;

  // Update summary stats
  if (summary) {
    const elHadir = document.getElementById('rekap-total-hadir');
    const elMhs   = document.getElementById('rekap-total-mhs');
    const elPersen= document.getElementById('rekap-persen');
    
    if (elHadir) elHadir.textContent = summary.total_hadir || 0;
    if (elMhs)   elMhs.textContent   = summary.total_mhs   || 0;
    const pct = summary.total_mhs > 0 ? Math.round((summary.total_hadir / summary.total_mhs) * 100) : 0;
    if (elPersen) elPersen.textContent = pct + '%';
  }

  if (!records || records.length === 0) {
    tbody.innerHTML = `
      <tr><td colspan="6">
        <div class="empty-state">
          <div class="empty-text">TIDAK ADA DATA PRESENSI PADA TANGGAL INI</div>
          <div class="empty-sub">Pilih tanggal lain untuk melihat riwayat kehadiran</div>
        </div>
      </td></tr>`;
    return;
  }

  tbody.innerHTML = records.map((r, i) => `
    <tr>
      <td class="font-mono font-bold">${i + 1}</td>
      <td><span class="td-uid font-mono">${r.uid}</span></td>
      <td class="td-name">${r.name}</td>
      <td class="font-mono">${r.nim || '-'}</td>
      <td class="font-mono">${r.waktu}</td>
      <td>
        <span class="badge badge-success font-mono">[TERCATAT]</span>
      </td>
    </tr>
  `).join('');
}

// ============================================
// EXPORT EXCEL
// ============================================
function exportExcel() {
  const date = document.getElementById('rekap-date')?.value || state.selectedDate;
  window.open(`${API.export}?date=${date}`, '_blank');
}

// ============================================
// TOAST NOTIFIKASI
// ============================================
function showToast(msg, type = 'info') {
  const container = document.getElementById('toast-container');
  if (!container) return;

  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;
  toast.innerHTML = `
    <span class="toast-msg font-mono text-sm">${msg}</span>
    <button class="toast-close" onclick="this.parentElement.remove()">✕</button>
  `;
  container.appendChild(toast);

  setTimeout(() => {
    toast.classList.add('fade-out');
    setTimeout(() => toast.remove(), 300);
  }, 4000);
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
// HELPER: Format Tanggal
// ============================================
function formatDate(str) {
  if (!str) return '-';
  const d = new Date(str);
  return d.toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }).toUpperCase()
       + ' ' + d.toLocaleTimeString('id-ID', { hour:'2-digit', minute:'2-digit' });
}

// ============================================
// ESP STATUS CHECK
// ============================================
async function checkEspStatus() {
  const dot  = document.getElementById('esp-dot');
  const text = document.getElementById('esp-status-text');
  if (!dot || !text) return;

  // Jika terhubung ke Firebase Realtime Database Cloud
  if (window.isFirebaseConnected) {
    dot.className    = 'esp-dot online';
    text.textContent = 'ONLINE (CLOUD)';
    return;
  }

  // Cek fallback ke API lokal jika tidak ada Firebase
  try {
    const res = await fetch('api/esp_status.php', { signal: AbortSignal.timeout(3000) });
    const data = await res.json();
    if (data.online) {
      dot.className  = 'esp-dot online';
      text.textContent = 'ONLINE (LOCAL)';
    } else {
      throw new Error('offline');
    }
  } catch {
    dot.className    = 'esp-dot offline';
    text.textContent = 'OFFLINE';
  }
}

// ============================================
// INISIALISASI
// ============================================
document.addEventListener('DOMContentLoaded', () => {
  // Jalankan jam
  updateClock();
  setInterval(updateClock, 1000);

  // Set default tanggal hari ini pada input rekap
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

  // Expose methods to global window for Firebase Realtime integration
  window.showToast = showToast;
  window.showPanel = showPanel;
  window.loadDashboard = loadDashboard;
  window.loadUnknownCards = loadUnknownCards;
  window.loadStudents = loadStudents;
  window.loadRekap = loadRekap;
  window.updateLiveFeed = updateLiveFeed;
  window.renderUnknownCards = renderUnknownCards;
  window.renderDashboardTable = renderDashboardTable;
  window.renderStudentsTable = renderStudentsTable;
});
