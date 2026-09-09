// app.js: Frontend controller untuk sistem presensi RFID HIMA (Dual-sync Firebase dan MySQL)

// Global Fetch Interceptor untuk Otomatisasi X-CSRF-Token pada Mutasi Lokal (Same-Origin)
const _originalFetch = window.fetch;
window.fetch = function(input, init = {}) {
  const options = { ...init };
  let method = options.method;
  if (!method && typeof input === 'object' && input !== null && 'method' in input) {
    method = input.method;
  }
  method = (method || 'GET').toUpperCase();

  // Hanya sematkan X-CSRF-Token pada request ke internal/origin sendiri
  let isSameOrigin = true;
  try {
    let urlStr = '';
    if (typeof input === 'string') {
      urlStr = input;
    } else if (input && typeof input.url === 'string') {
      urlStr = input.url;
    } else if (input && typeof input.href === 'string') {
      urlStr = input.href;
    }
    if (/^(https?:)?\/\//i.test(urlStr)) {
      const parsedUrl = new URL(urlStr, window.location.origin);
      isSameOrigin = parsedUrl.origin === window.location.origin;
    }
  } catch {
    isSameOrigin = false;
  }

  if (isSameOrigin && ['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (csrfToken) {
      if (options.headers instanceof Headers) {
        options.headers.set('X-CSRF-Token', csrfToken);
      } else if (Array.isArray(options.headers)) {
        options.headers.push(['X-CSRF-Token', csrfToken]);
      } else {
        options.headers = {
          ...(options.headers || {}),
          'X-CSRF-Token': csrfToken
        };
      }
    }
  }
  return _originalFetch.call(this, input, options);
};

// Helper format tanggal lokal YYYY-MM-DD
function getLocalDateString(d = new Date()) {
  const year = d.getFullYear();
  const month = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

// State aplikasi
const state = {
  currentPanel: 'dashboard',
  selectedDate: getLocalDateString(),
  students: [],
  attendance: [],
  unknownCards: [],
  events: [],
  activeEvent: null,
  activeSession: { id: 'sesi_1', name: 'Sesi 1 (Datang / Pagi)' },
  totalHadir: 0,
  totalMhs: 0,
  pollInterval: null,
  editingStudent: null,
};

// Konfigurasi Sesi Presensi HIMA
const SESSION_CONFIG = {
  sesi_1: 'Sesi 1 (Datang / Pagi)',
  sesi_2: 'Sesi 2 (Setelah Ishoma / Siang)',
  sesi_3: 'Sesi 3 (Pulang / Penutupan)',
};

function formatSessionLabel(sessionId, sessionName) {
  if (sessionName) return sessionName;
  return SESSION_CONFIG[sessionId] || sessionId || 'Sesi 1';
}

function getSessionBadgeClass(sessionId) {
  if (sessionId === 'sesi_2') return 'badge-warning';
  if (sessionId === 'sesi_3') return 'badge-danger';
  return 'badge-primary';
}

async function onSessionChange(sessionId) {
  const sessionName = SESSION_CONFIG[sessionId] || sessionId;
  state.activeSession = { id: sessionId, name: sessionName };

  // Sinkronisasi ke Firebase Cloud agar pembaca RFID (ESP8266) langsung sinkron
  if (typeof window.setActiveSessionInFirebase === 'function') {
    try {
      await window.setActiveSessionInFirebase({ id: sessionId, name: sessionName });
    } catch (e) {
      console.warn('Firebase session sync notice:', e);
    }
  }

  showToast(`Sesi presensi aktif diubah ke: ${sessionName}`, 'info');
  loadDashboard();
}

function updateActiveSessionDisplay(sessionData) {
  if (!sessionData) return;
  const id = sessionData.id || 'sesi_1';
  const name = sessionData.name || SESSION_CONFIG[id] || id;

  state.activeSession = { id, name };

  const select = document.getElementById('select-active-session');
  if (select && select.value !== id) {
    select.value = id;
  }
}

// API Endpoint lokal
const API = {
  attendance:   'api/attendance.php',
  students:     'api/students.php',
  unknownCards: 'api/unknown_cards.php',
  events:       'api/events.php',
  admin:        'api/admin.php',
  export:       'api/export.php',
};

// Navigation and panel switching
function showPanel(name, updateHistory = true) {
  if (!name || typeof name !== 'string') {
    name = 'dashboard';
  }
  state.currentPanel = name;

  // Toggle active class di nav items
  document.querySelectorAll('.nav-item').forEach(el => {
    el.classList.toggle('active', el.dataset.panel === name);
  });

  // Toggle active class di panel sections (kompatibel class panel dan panel-section)
  document.querySelectorAll('.panel, .panel-section').forEach(el => {
    el.classList.remove('active');
  });

  // Tampilkan panel yang dipilih (fallback aman ke panel-dashboard jika target tidak ditemukan)
  let targetPanel = document.getElementById(`panel-${name}`) || document.getElementById(name);
  if (!targetPanel) {
    targetPanel = document.getElementById('panel-dashboard');
    name = 'dashboard';
    state.currentPanel = 'dashboard';
  }
  if (targetPanel) {
    targetPanel.classList.add('active');
  }

  // Update browser history untuk Clean / Pretty URL
  if (updateHistory && window.history && window.history.pushState) {
    const currentPath = window.location.pathname.replace(/\/+$/, '').split('/').pop();
    if (currentPath !== name) {
      window.history.pushState({ panel: name }, '', name);
    }
  }

  // Update header page title & subtitle
  const panelTitles = {
    dashboard: ['Dashboard', 'Rekap absensi kehadiran hari ini'],
    tambah:    ['Pendaftaran Kartu', 'Registrasi kartu RFID mahasiswa baru'],
    mahasiswa: ['Data Mahasiswa', 'Daftar mahasiswa terdaftar di sistem'],
    rekap:     ['Rekap Absensi', 'Laporan riwayat kehadiran mahasiswa'],
    events:    ['Program Kerja', 'Manajemen acara dan kegiatan organisasi'],
  };
  const [tTitle, tSub] = panelTitles[name] || ['Presensi HIMA', 'Sistem Presensi RFID'];
  const titleEl = document.getElementById('page-title');
  const subEl = document.getElementById('page-subtitle');
  if (titleEl) titleEl.textContent = tTitle;
  if (subEl) subEl.textContent = tSub;

  // Load data sesuai panel yang aktif
  if (name === 'dashboard') loadDashboard();
  if (name === 'tambah')    loadUnknownCards();
  if (name === 'mahasiswa') loadStudents();
  if (name === 'rekap')     loadRekap();
  if (name === 'events')    loadEvents();
}

// Realtime clock display
function updateClock() {
  const now  = new Date();
  const opts = { weekday: 'long', day: 'numeric', month: 'short', year: 'numeric' };
  const liveDate = document.getElementById('live-date');
  const liveTime = document.getElementById('live-time');
  
  if (liveDate) liveDate.textContent = now.toLocaleDateString('id-ID', opts).toUpperCase();
  if (liveTime) liveTime.textContent = now.toLocaleTimeString('id-ID');
}

// Dashboard logic and data loading
async function loadDashboard() {
  const today = state.selectedDate || getLocalDateString();

  // 1. Prioritaskan data dari Firebase Cloud
  if (window.isFirebaseConnected && window.cloudUsers) {
    const cloudUserKeys = Object.keys(window.cloudUsers || {});
    state.totalMhs = cloudUserKeys.length;
    
    // Ambil log kehadiran HARI INI dari cloudLogs
    const allLogs = window.cloudLogs || [];
    const logsToday = allLogs.filter(l => l.date === today);
    const uniqueUids = new Set(logsToday.map(l => l.uid));
    
    state.totalHadir = uniqueUids.size;
    state.attendance = logsToday;

    updateDashboardUI();
    return;
  }

  // 2. Fallback API lokal MySQL
  try {
    const res = await fetch(`${API.attendance}?date=${today}`);
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

  // Hitung kehadiran unik pada sesi aktif hari ini
  const activeSessId = state.activeSession?.id || 'sesi_1';
  const sessLogs = (state.attendance || []).filter(r => (r.session_id || 'sesi_1') === activeSessId);
  const sessUids = new Set(sessLogs.map(r => r.uid));
  const activeHadirCount = sessUids.size;

  if (elHadir) elHadir.textContent = activeHadirCount;
  if (elTotal) elTotal.textContent = state.totalMhs;
  if (elAlpha) elAlpha.textContent = Math.max(0, state.totalMhs - activeHadirCount);

  // Persentase kehadiran sesi aktif
  const pct = state.totalMhs > 0 ? Math.round((activeHadirCount / state.totalMhs) * 100) : 0;
  if (elPersen) elPersen.textContent = pct + '%';
  if (elPersen2) elPersen2.textContent = pct + '%';
  if (elBarFill) elBarFill.style.width = pct + '%';

  // Render tabel absensi hari ini (6 kolom)
  renderDashboardTable(state.attendance);

  // Live feed (tap terakhir hari ini)
  if (state.attendance.length > 0) {
    const last = state.attendance[0]; // paling baru
    updateLiveFeed(last.name, last.waktu, false);
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
      <tr><td colspan="6">
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
      <td><span class="td-uid font-mono">${escapeHtml(r.uid)}</span></td>
      <td class="td-name font-bold">${escapeHtml(r.name)}</td>
      <td class="font-mono">${escapeHtml(r.nim || '-')}</td>
      <td class="font-mono"><span class="badge ${getSessionBadgeClass(r.session_id)}">${escapeHtml(formatSessionLabel(r.session_id, r.session_name))}</span></td>
      <td class="font-mono font-bold">${escapeHtml(r.waktu)}</td>
    </tr>
  `).join('');
}

let lastRecordedTapTime = '';
let isInitialAppLoad = true;
setTimeout(() => {
  isInitialAppLoad = false;
}, 2000);

function updateLiveFeed(name, time, playSound = true) {
  const el = document.getElementById('latest-tap');
  if (!el) return;
  
  if (!name) {
    el.innerHTML = '<span class="text-muted">Menunggu tap kartu RFID...</span>';
    return;
  }

  el.innerHTML = `
    <span class="tap-name">${escapeHtml(name)}</span>
    <span class="tap-time font-mono font-bold">[${escapeHtml(time || '')}]</span>
  `;

  if (time && time !== lastRecordedTapTime) {
    lastRecordedTapTime = time;
    if (playSound && !isInitialAppLoad) {
      playTapChime();
    }
  }
}

// Panel tambah mahasiswa dan kartu belum terdaftar
async function loadUnknownCards() {
  // 1. Jika ada data cloud
  if (window.isFirebaseConnected && window.cloudUnknownCards) {
    state.unknownCards = window.cloudUnknownCards;
    renderUnknownCards(state.unknownCards);
    return;
  }

  // 2. Fallback API lokal MySQL
  try {
    const res = await fetch(API.unknownCards);
    const data = await res.json();
    state.unknownCards = data.data || [];
    renderUnknownCards(state.unknownCards);
  } catch (e) {
    console.error('Unknown cards load error:', e);
  }
}

function renderUnknownCards(cards) {
  const tbody = document.getElementById('unknown-tbody');
  const countBadge = document.getElementById('unknown-count-badge');
  if (countBadge) countBadge.textContent = `${(cards || []).length} KARTU`;
  if (!tbody) return;

  if (!cards || cards.length === 0) {
    tbody.innerHTML = `
      <tr><td colspan="5">
        <div class="empty-state">
          <div class="empty-text">TIDAK ADA KARTU BARU TERDETEKSI</div>
          <div class="empty-sub">Tempelkan kartu RFID baru ke alat untuk memunculkan UID otomatis di sini</div>
        </div>
      </td></tr>`;
    return;
  }

  tbody.innerHTML = cards.map((c, i) => `
    <tr>
      <td class="font-mono font-bold">${i + 1}</td>
      <td><span class="td-uid font-mono">${escapeHtml(c.uid)}</span></td>
      <td class="font-mono">${c.tap_count || 1}x</td>
      <td class="font-mono text-sm">${formatDate(c.last_seen)}</td>
      <td>
        <button class="btn btn-warning btn-sm" onclick="openRegisterModal('${escapeJsString(c.uid)}')">
          + Daftarkan
        </button>
      </td>
    </tr>
  `).join('');
}

// Modal Registrasi
function openRegisterModal(uid = '') {
  const modal = document.getElementById('modal-register');
  const input = document.getElementById('reg-uid');
  if (input) input.value = uid;
  document.getElementById('reg-name').value = '';
  document.getElementById('reg-nim').value  = '';
  if (modal) {
    modal.classList.add('active', 'open');
    setTimeout(() => {
      document.getElementById('reg-name')?.focus();
    }, 50);
  }
}

function openAddManualModal() {
  openRegisterModal('');
}

function closeRegisterModal() {
  const modal = document.getElementById('modal-register');
  if (modal) modal.classList.remove('active', 'open');
}

async function submitRegister() {
  const uid  = document.getElementById('reg-uid').value.trim().toUpperCase();
  const name = document.getElementById('reg-name').value.trim();
  const nim  = document.getElementById('reg-nim').value.trim();

  if (!uid)  { showToast('UID kartu tidak boleh kosong', 'warning'); return; }
  if (!name) { showToast('Nama mahasiswa wajib diisi', 'warning'); return; }

  // 1. Simpan ke Firebase Cloud
  if (typeof window.registerUserToFirebase === 'function') {
    await window.registerUserToFirebase(uid, name, nim);
  }

  // 2. Simpan ke MySQL jika aktif
  try {
    await fetch(API.students, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ uid, name, nim }),
    });
  } catch (e) {
    console.warn('MySQL save notice:', e);
  }

  showToast(`Mahasiswa "${name}" berhasil didaftarkan!`, 'success');
  closeRegisterModal();
  loadUnknownCards();
  loadDashboard();
}

// Panel data mahasiswa
async function loadStudents(searchQuery = '') {
  // 1. Jika ada data di Firebase Cloud
  if (window.isFirebaseConnected && window.cloudUsers) {
    renderCloudStudents(searchQuery);
    return;
  }

  // 2. Fallback API lokal MySQL
  try {
    const res = await fetch(API.students);
    const data = await res.json();
    state.students = data.data || [];
    renderStudentsTable(state.students, searchQuery);
  } catch (e) {
    console.error('Students load error:', e);
  }
}

function renderCloudStudents(searchQuery = '') {
  const users = window.cloudUsers || {};
  const list = Object.keys(users).map(uid => ({
    id: uid,
    uid: uid,
    name: users[uid].name || '-',
    nim: users[uid].nim || '-',
    created_at: users[uid].registered_at ? new Date(users[uid].registered_at).toISOString() : '-'
  }));

  state.students = list;
  renderStudentsTable(list, searchQuery);
}

function renderStudentsTable(students, searchQuery = '') {
  const tbody = document.getElementById('students-tbody');
  const count = document.getElementById('mhs-count-badge');
  if (count) count.textContent = `${(students || []).length} MAHASISWA`;
  if (!tbody) return;

  let displayList = students || [];
  if (searchQuery && typeof searchQuery === 'string') {
    const q = searchQuery.toLowerCase().trim();
    displayList = displayList.filter(s =>
      s.name.toLowerCase().includes(q) ||
      (s.nim && s.nim.toLowerCase().includes(q)) ||
      s.uid.toLowerCase().includes(q)
    );
  }

  if (displayList.length === 0) {
    tbody.innerHTML = `
      <tr><td colspan="6">
        <div class="empty-state">
          <div class="empty-text">TIDAK ADA DATA MAHASISWA</div>
          <div class="empty-sub">Daftarkan mahasiswa baru melalui tab "Tambah Mahasiswa"</div>
        </div>
      </td></tr>`;
    return;
  }

  tbody.innerHTML = displayList.map((s, i) => {
    let hadirCount = s.total_hadir;
    if (hadirCount === undefined && window.cloudLogs) {
      hadirCount = window.cloudLogs.filter(l => l.uid === s.uid).length;
    }
    hadirCount = hadirCount || 0;

    return `
    <tr>
      <td class="font-mono font-bold">${i + 1}</td>
      <td><span class="td-uid font-mono">${escapeHtml(s.uid)}</span></td>
      <td class="td-name font-bold">${escapeHtml(s.name)}</td>
      <td class="font-mono">${escapeHtml(s.nim || '-')}</td>
      <td class="font-mono font-bold"><span class="badge badge-warning">${hadirCount}x</span></td>
      <td>
        <div class="flex gap-2">
          <button class="btn btn-secondary btn-sm" onclick="openEditModal('${escapeJsString(s.uid)}', '${escapeJsString(s.name)}', '${escapeJsString(s.nim || '')}', '${escapeJsString(s.id || '')}')">
            Edit
          </button>
          <button class="btn btn-danger btn-sm" onclick="deleteStudent('${escapeJsString(s.uid)}', '${escapeJsString(s.name)}', '${escapeJsString(s.id || '')}')">
            Hapus
          </button>
        </div>
      </td>
    </tr>
  `}).join('');
}

function escapeHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function escapeJsString(str) {
  if (str === null || str === undefined) return '';
  return String(str)
    .replace(/\\/g, '\\\\')
    .replace(/'/g, "\\'")
    .replace(/"/g, '\\"')
    .replace(/\n/g, '\\n')
    .replace(/\r/g, '');
}

function searchStudents() {
  const query = document.getElementById('search-students')?.value || '';
  loadStudents(query);
}

// Modal Edit
function openEditModal(uid, name, nim, id) {
  const modal = document.getElementById('modal-edit');
  document.getElementById('edit-uid').value  = uid;
  document.getElementById('edit-name').value = name;
  document.getElementById('edit-nim').value  = nim;
  document.getElementById('edit-id').value   = id;
  if (modal) {
    modal.classList.add('active', 'open');
    setTimeout(() => {
      document.getElementById('edit-name')?.focus();
    }, 50);
  }
}

function closeEditModal() {
  const modal = document.getElementById('modal-edit');
  if (modal) modal.classList.remove('active', 'open');
}

async function submitEdit() {
  const uid   = document.getElementById('edit-uid').value.trim().toUpperCase();
  const name  = document.getElementById('edit-name').value.trim();
  const nim   = document.getElementById('edit-nim').value.trim();
  const rawId = document.getElementById('edit-id')?.value;
  const id    = parseInt(rawId) || 0;

  if (!name) { showToast('Nama mahasiswa tidak boleh kosong', 'warning'); return; }

  // 1. Update ke Firebase Cloud
  if (typeof window.registerUserToFirebase === 'function') {
    await window.registerUserToFirebase(uid, name, nim);
  }

  // 2. Update ke MySQL jika aktif
  try {
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

async function deleteStudent(uid, name, id = null) {
  if (!confirm(`Yakin hapus data mahasiswa "${name}"?\nData kartu akan dihapus dari sistem.`)) return;

  // 1. Hapus dari Firebase Cloud
  if (typeof window.deleteUserFromFirebase === 'function') {
    await window.deleteUserFromFirebase(uid);
  }

  // 2. Hapus dari MySQL jika aktif
  try {
    const numId = parseInt(id) || 0;
    await fetch(API.students, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: numId, uid }),
    });
  } catch (e) {
    console.warn('MySQL delete notice:', e);
  }

  showToast(`Mahasiswa "${name}" berhasil dihapus`, 'success');
  loadStudents();
  loadDashboard();
}

// Rekap absensi
async function loadRekap() {
  const dateInput = document.getElementById('rekap-date');
  if (dateInput && !dateInput.value) {
    dateInput.value = state.selectedDate || getLocalDateString();
  }
  const date = dateInput ? dateInput.value : state.selectedDate;
  const sessionFilter = document.getElementById('rekap-session-filter')?.value || '';

  // 1. Jika ada data presensi di Firebase Cloud
  if (window.isFirebaseConnected && window.cloudLogs) {
    let filteredLogs = (window.cloudLogs || []).filter(l => l.date === date);
    if (sessionFilter) {
      filteredLogs = filteredLogs.filter(l => (l.session_id || 'sesi_1') === sessionFilter);
    }
    state.attendance = filteredLogs;
    const summary = {
      total_hadir: new Set(filteredLogs.map(l => l.uid)).size,
      total_mhs: Object.keys(window.cloudUsers || {}).length
    };
    renderRekapTable(filteredLogs, summary);
    return;
  }

  // 2. Fallback ke MySQL
  try {
    const url = sessionFilter
      ? `${API.attendance}?date=${encodeURIComponent(date)}&session_id=${encodeURIComponent(sessionFilter)}`
      : `${API.attendance}?date=${encodeURIComponent(date)}`;
    const res  = await fetch(url);
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
      <tr><td colspan="7">
        <div class="empty-state">
          <div class="empty-text">TIDAK ADA DATA PRESENSI PADA TANGGAL / SESI INI</div>
          <div class="empty-sub">Pilih tanggal atau filter sesi lain untuk melihat riwayat kehadiran</div>
        </div>
      </td></tr>`;
    return;
  }

  tbody.innerHTML = records.map((r, i) => `
    <tr>
      <td class="font-mono font-bold">${i + 1}</td>
      <td><span class="td-uid font-mono">${escapeHtml(r.uid)}</span></td>
      <td class="td-name font-bold">${escapeHtml(r.name)}</td>
      <td class="font-mono">${escapeHtml(r.nim || '-')}</td>
      <td class="font-mono"><span class="badge ${getSessionBadgeClass(r.session_id)}">${escapeHtml(formatSessionLabel(r.session_id, r.session_name))}</span></td>
      <td class="font-mono font-bold">${escapeHtml(r.waktu)}</td>
      <td>
        <span class="badge badge-success font-mono">[TERCATAT]</span>
      </td>
    </tr>
  `).join('');
}

async function clearRekapByDate() {
  const dateInput = document.getElementById('rekap-date');
  const date = dateInput ? dateInput.value : state.selectedDate;
  if (!date) {
    showToast('Pilih tanggal terlebih dahulu', 'warning');
    return;
  }

  const confirmDate = prompt(`PERINGATAN: Tindakan ini akan menghapus permanen semua log presensi pada tanggal ${date} (Lokal & Cloud).\n\nKetik "${date}" di bawah untuk konfirmasi:`);
  if (confirmDate !== date) {
    if (confirmDate !== null) {
      showToast('Penghapusan dibatalkan (tanggal konfirmasi tidak cocok)', 'warning');
    }
    return;
  }

  let mysqlSuccess = false;
  let cloudDeleted = 0;

  // 1. Hapus dari database MySQL lokal via API
  try {
    const res = await fetch(API.attendance, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ date })
    });
    const data = await res.json();
    if (data.success) {
      mysqlSuccess = true;
    }
  } catch (e) {
    console.warn('MySQL clear attendance notice:', e);
  }

  // 2. Hapus dari database Firebase Cloud (jika terhubung)
  if (typeof window.clearRekapFromFirebase === 'function' && window.isFirebaseConnected) {
    try {
      cloudDeleted = await window.clearRekapFromFirebase(date);
    } catch (e) {
      console.warn('Firebase clear attendance notice:', e);
    }
  }

  if (mysqlSuccess || cloudDeleted > 0) {
    showToast(`Rekap kehadiran tanggal ${date} berhasil dibersihkan`, 'success');
    loadRekap();
    loadDashboard();
  } else {
    showToast('Tidak ada data yang dihapus atau gagal memproses permintaan', 'info');
  }
}

// Export CSV dan Excel

function sanitizeCellForCsv(val) {
  let str = String(val ?? '');
  if (/^[=+\-@\t\r]/.test(str)) {
    return "'" + str;
  }
  return str;
}

// 1. Download CSV Bersih & Standar (Kompatibel Excel, Google Sheets, dll.)
function downloadCSV(filename, headers, rows) {
  if (!rows || rows.length === 0) {
    showToast('Tidak ada data untuk di-download', 'warning');
    return;
  }

  // UTF-8 BOM (\uFEFF)
  let csvContent = '\uFEFF';
  csvContent += headers.map(h => `"${sanitizeCellForCsv(h).replace(/"/g, '""')}"`).join(',') + '\r\n';

  rows.forEach(row => {
    csvContent += row.map(col => `"${sanitizeCellForCsv(col).replace(/"/g, '""')}"`).join(',') + '\r\n';
  });

  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.setAttribute('href', url);
  link.setAttribute('download', filename.endsWith('.csv') ? filename : `${filename}.csv`);
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
  showToast(`File CSV "${filename}.csv" berhasil di-download!`, 'success');
}

// 2. Download Excel (.xlsx Asli via SheetJS dengan Auto-Fit Kolom & Fallback XML Rapi)
function downloadExcel(filename, title, period, headers, rows) {
  if (!rows || rows.length === 0) {
    showToast('Tidak ada data untuk di-download', 'warning');
    return;
  }

  const dateNow = new Date().toLocaleDateString('id-ID', { day:'2-digit', month:'long', year:'numeric' }) + ' ' + new Date().toLocaleTimeString('id-ID');

  // JIKA LIBRARY SheetJS (XLSX) TERSEDIA -> BUAT FILE ASLI .XLSX BERSIH & RAPI
  if (typeof XLSX !== 'undefined') {
    try {
      const wsData = [
        ['SISTEM PRESENSI MAHASISWA BERBASIS RFID (HIMA)'],
        [title],
        [`Periode: ${period || '-'} | Waktu Cetak: ${dateNow}`],
        [], // baris kosong pemisah
        headers,
        ...rows.map(r => r.map(c => String(c ?? '-')))
      ];

      const ws = XLSX.utils.aoa_to_sheet(wsData);

      // Lebar kolom otomatis proporsional
      const colWidths = headers.map((h, i) => {
        let maxLen = h.length;
        rows.forEach(r => {
          const valStr = String(r[i] ?? '');
          if (valStr.length > maxLen) maxLen = valStr.length;
        });
        return { wch: Math.max(maxLen + 4, 10) };
      });

      // Override lebar khusus agar nyaman
      if (colWidths[0]) colWidths[0].wch = 6;  // No
      if (colWidths[1]) colWidths[1].wch = 28; // Nama
      if (colWidths[2]) colWidths[2].wch = 18; // NIM
      if (colWidths[3]) colWidths[3].wch = 20; // Status Kehadiran
      if (colWidths[4]) colWidths[4].wch = 20; // Sesi
      if (colWidths[5]) colWidths[5].wch = 14; // Tanggal
      if (colWidths[6]) colWidths[6].wch = 12; // Jam Tap

      ws['!cols'] = colWidths;

      // Merge judul utama baris 1-3
      const lastColIndex = headers.length - 1;
      ws['!merges'] = [
        { s: { r: 0, c: 0 }, e: { r: 0, c: lastColIndex } },
        { s: { r: 1, c: 0 }, e: { r: 1, c: lastColIndex } },
        { s: { r: 2, c: 0 }, e: { r: 2, c: lastColIndex } }
      ];

      const wb = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(wb, ws, 'Presensi');
      XLSX.writeFile(wb, `${filename}.xlsx`);
      showToast(`File Excel "${filename}.xlsx" berhasil di-download!`, 'success');
      return;
    } catch (e) {
      console.warn('SheetJS export notice, fallback ke XML:', e);
    }
  }

  // FALLBACK KE FORMAT EXCEL XML BERSIH (TABEL TUNGGAL RAPI TANPA KARTU MERUSAK GRID)
  const numCols = headers.length;
  let html = `
    <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
    <head>
      <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
      <style>
        body { font-family: Arial, sans-serif; font-size: 10pt; }
        table { border-collapse: collapse; width: 100%; }
        .header-title { font-size: 14pt; font-weight: bold; text-align: center; height: 30px; }
        .header-sub { font-size: 10pt; color: #555555; text-align: center; height: 20px; }
        th { background-color: #2563eb; color: #ffffff; font-weight: bold; border: 1px solid #1e40af; padding: 8px; text-align: center; }
        td { border: 1px solid #cbd5e1; padding: 6px 8px; vertical-align: middle; }
        .text-center { text-align: center; }
        .txt { mso-number-format:"\\@"; text-align: center; }
        .badge { background-color: #dcfce7; color: #166534; font-weight: bold; text-align: center; }
      </style>
    </head>
    <body>
      <table>
        <col width="50">
        <col width="220">
        <col width="140">
        <col width="160">
        <col width="160">
        <col width="120">
        <col width="100">
        <tr>
          <td colspan="${numCols}" class="header-title">${title}</td>
        </tr>
        <tr>
          <td colspan="${numCols}" class="header-sub">Sistem Presensi Mahasiswa Berbasis RFID (HIMA)</td>
        </tr>
        <tr>
          <td colspan="${numCols}" class="header-sub">Periode / Tanggal: ${period || '-'} | Waktu Cetak: ${dateNow}</td>
        </tr>
        <tr style="height: 12px;"><td colspan="${numCols}" style="border: none;"></td></tr>
        <tr>
          ${headers.map(h => `<th>${h}</th>`).join('')}
        </tr>
        ${rows.map((r, i) => `
          <tr style="${i % 2 === 1 ? 'background-color: #f8fafc;' : ''}">
            ${r.map((c, colIdx) => {
              let cls = '';
              if (colIdx === 0) cls = 'text-center';        // No
              else if (colIdx === 2) cls = 'txt';            // NIM
              else if (colIdx === 3 || String(c).includes('HADIR')) cls = 'badge'; // Status Kehadiran
              else if (colIdx === 4 || colIdx === 5 || colIdx === 6) cls = 'text-center'; // Sesi, Tanggal, Jam
              return `<td class="${cls}">${c ?? '-'}</td>`;
            }).join('')}
          </tr>
        `).join('')}
      </table>
    </body>
    </html>
  `;

  const blob = new Blob(['\uFEFF' + html], { type: 'application/vnd.ms-excel;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.setAttribute('href', url);
  link.setAttribute('download', `${filename}.xls`);
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
  showToast(`File Excel "${filename}.xls" berhasil di-download!`, 'success');
}

// 1. Export Data Hari Ini (Dashboard)
function exportAttendanceToday(format = 'csv') {
  const today = state.selectedDate || getLocalDateString();
  const allLogs = window.cloudLogs || state.attendance || [];
  const logsToday = allLogs.filter(l => (l.date || l.tap_date) === today);

  if (logsToday.length === 0) {
    showToast(`Belum ada data presensi hari ini (${today})`, 'warning');
    return;
  }

  const headers = ['No', 'Nama Mahasiswa', 'NIM', 'Status Kehadiran', 'Sesi Presensi', 'Tanggal', 'Jam Tap'];
  const rows = logsToday.map((r, i) => [
    i + 1,
    r.name,
    r.nim || '-',
    'HADIR',
    formatSessionLabel(r.session_id, r.session_name),
    r.date || r.tap_date || today,
    r.waktu
  ]);

  const filename = `Presensi_Hari_Ini_${today}`;
  if (format === 'excel' || format === 'xls') {
    downloadExcel(filename, 'REKAPITULASI PRESENSI MAHASISWA (HARI INI)', today, headers, rows);
  } else {
    downloadCSV(filename, headers, rows);
  }
}

// 2. Export Data Sesuai Tanggal Rekap
function exportAttendance(format = 'csv') {
  const date = document.getElementById('rekap-date')?.value || state.selectedDate || getLocalDateString();
  const sessionFilter = document.getElementById('rekap-session-filter')?.value || '';
  const allLogs = window.cloudLogs || state.attendance || [];
  let logsFiltered = allLogs.filter(l => (l.date || l.tap_date) === date);
  if (sessionFilter) {
    logsFiltered = logsFiltered.filter(l => (l.session_id || 'sesi_1') === sessionFilter);
  }

  if (logsFiltered.length === 0) {
    showToast(`Tidak ada data presensi pada tanggal ${date}${sessionFilter ? ' untuk ' + formatSessionLabel(sessionFilter) : ''}`, 'warning');
    return;
  }

  const headers = ['No', 'Nama Mahasiswa', 'NIM', 'Status Kehadiran', 'Sesi Presensi', 'Tanggal', 'Jam Tap'];
  const rows = logsFiltered.map((r, i) => [
    i + 1,
    r.name,
    r.nim || '-',
    'HADIR',
    formatSessionLabel(r.session_id, r.session_name),
    r.date || r.tap_date || date,
    r.waktu
  ]);

  const sessSuffix = sessionFilter ? `_${sessionFilter}` : '';
  const filename = `Rekap_Presensi_${date}${sessSuffix}`;
  if (format === 'excel' || format === 'xls') {
    downloadExcel(filename, `REKAP PRESENSI MAHASISWA (${date}${sessionFilter ? ' - ' + formatSessionLabel(sessionFilter) : ''})`, date, headers, rows);
  } else {
    downloadCSV(filename, headers, rows);
  }
}

// 3. Export Semua Riwayat Absensi
function exportAllAttendance(format = 'csv') {
  const allLogs = window.cloudLogs || state.attendance || [];

  if (allLogs.length === 0) {
    showToast('Tidak ada data riwayat presensi', 'warning');
    return;
  }

  const headers = ['No', 'Nama Mahasiswa', 'NIM', 'Status Kehadiran', 'Sesi Presensi', 'Tanggal', 'Jam Tap'];
  const rows = allLogs.map((r, i) => [
    i + 1,
    r.name,
    r.nim || '-',
    'HADIR',
    formatSessionLabel(r.session_id, r.session_name),
    r.date || r.tap_date || '-',
    r.waktu
  ]);

  const filename = `Rekap_Presensi_Keseluruhan_${getLocalDateString()}`;
  if (format === 'excel' || format === 'xls') {
    downloadExcel(filename, 'REKAP KESELURUHAN LOG PRESENSI MAHASISWA', 'Semua Riwayat', headers, rows);
  } else {
    downloadCSV(filename, headers, rows);
  }
}

// 4. Export Daftar Mahasiswa Terdaftar
function exportStudents(format = 'csv') {
  const students = state.students || [];

  if (students.length === 0) {
    showToast('Belum ada data mahasiswa terdaftar', 'warning');
    return;
  }

  const headers = ['No', 'UID Kartu', 'Nama Mahasiswa', 'NIM', 'Tanggal Terdaftar'];
  const rows = students.map((s, i) => [
    i + 1,
    s.uid,
    s.name,
    s.nim || '-',
    formatDate(s.created_at)
  ]);

  const filename = `Daftar_Mahasiswa_${getLocalDateString()}`;
  if (format === 'excel' || format === 'xls') {
    downloadExcel(filename, 'DAFTAR MAHASISWA TERDAFTAR', getLocalDateString(), headers, rows);
  } else {
    downloadCSV(filename, headers, rows);
  }
}

// Notifikasi toast
function showToast(msg, type = 'info') {
  const container = document.getElementById('toast-container');
  if (!container) return;

  const normalizedType = type === 'error' ? 'danger' : type;
  const labelText = (normalizedType === 'danger') ? 'ERROR' : (normalizedType === 'warning' ? 'PERINGATAN' : (normalizedType === 'success' ? 'SUKSES' : 'INFO'));

  const toast = document.createElement('div');
  toast.className = `toast toast-${normalizedType}`;

  const labelSpan = document.createElement('span');
  labelSpan.className = 'toast-label';
  labelSpan.textContent = labelText;

  const msgSpan = document.createElement('span');
  msgSpan.className = 'toast-msg font-mono text-sm';
  msgSpan.textContent = String(msg || ''); // Aman dari DOM XSS

  const closeBtn = document.createElement('button');
  closeBtn.className = 'toast-close';
  closeBtn.style.cssText = 'background:transparent;border:none;cursor:pointer;font-weight:bold;margin-left:auto;padding:0 4px;font-family:monospace;font-size:13px;';
  closeBtn.textContent = 'X';
  closeBtn.onclick = () => toast.remove();

  toast.appendChild(labelSpan);
  toast.appendChild(msgSpan);
  toast.appendChild(closeBtn);
  container.appendChild(toast);

  setTimeout(() => {
    toast.classList.add('hiding');
    setTimeout(() => toast.remove(), 300);
  }, 4000);
}

// Polling fallback lokal jika offline
function startPolling() {
  if (state.pollInterval) clearInterval(state.pollInterval);
  state.pollInterval = setInterval(() => {
    if (!window.isFirebaseConnected) {
      if (state.currentPanel === 'dashboard') loadDashboard();
      if (state.currentPanel === 'tambah')    loadUnknownCards();
    }
  }, 5000);
}

// Format tanggal dan waktu
function formatDate(str) {
  if (!str) return '-';
  const d = new Date(str);
  return d.toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }).toUpperCase()
       + ' ' + d.toLocaleTimeString('id-ID', { hour:'2-digit', minute:'2-digit' });
}

// Monitoring status perangkat ESP8266
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

// Audio notification (Web Audio API - Singleton AudioContext)
let audioChimeEnabled = localStorage.getItem('presensi_audio_chime') !== 'disabled';
let _sharedAudioCtx = null;
let hasUserInteracted = false;

function unlockAudioContext() {
  hasUserInteracted = true;
  if (!_sharedAudioCtx) {
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    if (AudioCtx) {
      try {
        _sharedAudioCtx = new AudioCtx();
      } catch {}
    }
  }
  if (_sharedAudioCtx && _sharedAudioCtx.state === 'suspended') {
    _sharedAudioCtx.resume().catch(() => {});
  }
}

// Buka kunci AudioContext secara otomatis begitu user berinteraksi dengan halaman
['click', 'touchstart', 'keydown'].forEach(evt => {
  document.addEventListener(evt, unlockAudioContext, { once: true, passive: true });
});

function getSharedAudioContext() {
  if (!hasUserInteracted && !_sharedAudioCtx) {
    return null;
  }
  if (!_sharedAudioCtx) {
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    if (AudioCtx) {
      try {
        _sharedAudioCtx = new AudioCtx();
      } catch {}
    }
  }
  if (_sharedAudioCtx && _sharedAudioCtx.state === 'suspended' && hasUserInteracted) {
    _sharedAudioCtx.resume().catch(() => {});
  }
  return _sharedAudioCtx;
}

function playTapChime() {
  if (!audioChimeEnabled || !hasUserInteracted) return;
  try {
    const ctx = getSharedAudioContext();
    if (!ctx || ctx.state !== 'running') return;
    
    const now = ctx.currentTime;
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    
    osc.type = 'sine';
    osc.frequency.setValueAtTime(587.33, now);        // D5 note
    osc.frequency.setValueAtTime(880.00, now + 0.09); // A5 note
    
    gain.gain.setValueAtTime(0.12, now);
    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.35);
    
    osc.connect(gain);
    gain.connect(ctx.destination);
    
    osc.start(now);
    osc.stop(now + 0.35);
  } catch (e) {
    // Autoplay policy fallback
  }
}

function toggleAudioChime() {
  unlockAudioContext();
  audioChimeEnabled = !audioChimeEnabled;
  localStorage.setItem('presensi_audio_chime', audioChimeEnabled ? 'enabled' : 'disabled');
  updateAudioToggleUI();
  if (audioChimeEnabled) {
    if (_sharedAudioCtx && _sharedAudioCtx.state === 'suspended') {
      _sharedAudioCtx.resume().then(() => playTapChime()).catch(() => {});
    } else {
      playTapChime();
    }
  }
}

function updateAudioToggleUI() {
  const label = document.getElementById('audio-status-label');
  if (label) {
    label.textContent = audioChimeEnabled ? 'Suara: Aktif' : 'Suara: Nonaktif';
  }
}

// Quick date filter shortcuts
function setQuickDate(preset) {
  const input = document.getElementById('rekap-date');
  if (!input) return;

  const d = new Date();
  if (preset === 'yesterday') {
    d.setDate(d.getDate() - 1);
  }
  const dateStr = getLocalDateString(d);
  input.value = dateStr;
  state.selectedDate = dateStr;
  loadRekap();
}

// Event and proker management
async function loadEvents() {
  try {
    const res = await fetch(API.events);
    const data = await res.json();
    if (data.success) {
      state.events = data.data || [];
      state.activeEvent = data.active_event || null;
      renderEventsTable(state.events);
      updateActiveEventDisplay(state.activeEvent);
    }
  } catch (e) {
    console.error('Error loading events:', e);
  }
}

function formatEventTime(startTime, endTime) {
  if (!startTime) return '';
  const start = startTime.substring(0, 5);
  if (endTime) {
    const end = endTime.substring(0, 5);
    return `${start} - ${end} WIB`;
  }
  return `${start} WIB`;
}

function updateActiveEventDisplay(eventData) {
  const ev = eventData || state.activeEvent;
  const badgeEl = document.getElementById('active-event-badge');
  const titleEl = document.getElementById('active-event-title');
  const dateEl  = document.getElementById('active-event-date');

  if (ev && ev.name && (ev.is_active == 1 || ev.is_active === undefined)) {
    if (badgeEl) {
      badgeEl.style.display = 'inline-flex';
      badgeEl.style.background = 'var(--color-yellow)';
      badgeEl.style.color = '#000000';
    }
    if (titleEl) titleEl.textContent = ev.name;
    if (dateEl) {
      const dateText = ev.event_date || ev.date ? formatDate(ev.event_date || ev.date) : '';
      const timeText = formatEventTime(ev.start_time, ev.end_time);
      dateEl.textContent = [dateText, timeText].filter(Boolean).join(' • ');
    }
  } else {
    if (badgeEl) {
      badgeEl.style.display = 'inline-flex';
      badgeEl.style.background = '#e2e8f0';
      badgeEl.style.color = '#475569';
    }
    if (titleEl) titleEl.textContent = 'Tidak Ada Acara Aktif';
    if (dateEl)  dateEl.textContent = '-';
  }
}

function renderEventsTable(events) {
  const tbody = document.getElementById('events-tbody');
  const badge = document.getElementById('events-count-badge');
  if (badge) badge.textContent = `${(events || []).length} PROGRAM KERJA`;
  if (!tbody) return;

  if (!events || events.length === 0) {
    tbody.innerHTML = `
      <tr><td colspan="6">
        <div class="empty-state">
          <div class="empty-text">BELUM ADA PROGRAM KERJA / ACARA</div>
          <div class="empty-sub">Buat program kerja baru untuk memisahkan data presensi tiap kegiatan</div>
        </div>
      </td></tr>`;
    return;
  }

  tbody.innerHTML = events.map((ev, i) => {
    const isActive = ev.is_active == 1;
    const timeStr = formatEventTime(ev.start_time, ev.end_time);
    return `
    <tr class="${isActive ? 'row-active-event' : ''}">
      <td class="font-mono font-bold">${i + 1}</td>
      <td class="font-bold">
        ${escapeHtml(ev.name)}
        ${isActive ? '<span class="badge badge-warning ml-2 font-mono">[SEDANG BERJALAN]</span>' : ''}
        ${ev.description ? `<div class="text-xs text-muted mt-1 font-mono">${escapeHtml(ev.description)}</div>` : ''}
      </td>
      <td class="font-mono text-sm">
        <div>${formatDate(ev.event_date)}</div>
        ${timeStr ? `<div class="text-xs font-mono font-bold mt-1" style="color: var(--text-secondary);"><span class="badge badge-outline" style="font-size: 10px; padding: 1px 5px; margin-right: 4px;">WAKTU</span>${timeStr}</div>` : ''}
      </td>
      <td class="font-mono font-bold"><span class="badge badge-outline">${ev.total_hadir || 0} Mahasiswa</span></td>
      <td>
        <span class="badge ${isActive ? 'badge-success' : 'badge-secondary'} font-mono">
          ${isActive ? 'AKTIF' : 'SELESAI / NONAKTIF'}
        </span>
      </td>
      <td>
        <div class="flex gap-2">
          ${!isActive ? `
            <button class="btn btn-warning btn-sm" onclick="toggleEventActive(${ev.id}, true)">
              Aktifkan
            </button>
          ` : `
            <button class="btn btn-secondary btn-sm" onclick="toggleEventActive(${ev.id}, false)">
              Nonaktifkan
            </button>
          `}
          <button class="btn btn-danger btn-sm" onclick="deleteEvent(${ev.id})">
            Hapus
          </button>
        </div>
      </td>
    </tr>
  `;
  }).join('');
}

function openCreateEventModal() {
  const modal = document.getElementById('modal-create-event');
  const dateInput = document.getElementById('event-date');
  if (dateInput) dateInput.value = getLocalDateString();
  const startTimeInput = document.getElementById('event-start-time');
  if (startTimeInput) startTimeInput.value = '08:00';
  const endTimeInput = document.getElementById('event-end-time');
  if (endTimeInput) endTimeInput.value = '';
  document.getElementById('event-name').value = '';
  document.getElementById('event-desc').value = '';
  if (modal) modal.classList.add('active', 'open');
}

function closeCreateEventModal() {
  const modal = document.getElementById('modal-create-event');
  if (modal) modal.classList.remove('active', 'open');
}

async function submitCreateEvent() {
  const name        = document.getElementById('event-name')?.value.trim();
  const description = document.getElementById('event-desc')?.value.trim();
  const event_date  = document.getElementById('event-date')?.value || getLocalDateString();
  const start_time  = document.getElementById('event-start-time')?.value || '08:00';
  const end_time    = document.getElementById('event-end-time')?.value || null;
  const is_active   = document.getElementById('event-active')?.checked ? 1 : 0;

  if (!name) {
    showToast('Nama program kerja wajib diisi', 'warning');
    return;
  }

  try {
    const res = await fetch(API.events, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name, description, event_date, start_time, end_time, is_active })
    });
    const data = await res.json();
    if (data.success) {
      showToast('Program kerja berhasil dibuat!', 'success');
      closeCreateEventModal();

      // Jika diset aktif, sinkronkan ke Firebase Realtime Database
      if (is_active && typeof window.setActiveEventInFirebase === 'function') {
        window.setActiveEventInFirebase({
          id: data.id,
          name,
          event_date,
          date: event_date,
          start_time,
          end_time,
          is_active: 1
        });
      }

      loadEvents();
    } else {
      showToast(data.message || 'Gagal membuat acara', 'danger');
    }
  } catch (e) {
    console.error(e);
    showToast('Terjadi kesalahan saat membuat acara', 'danger');
  }
}

async function toggleEventActive(id, activate) {
  const action = activate ? 'set_active' : 'set_inactive';
  try {
    const res = await fetch(API.events, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id, action })
    });
    const data = await res.json();
    if (data.success) {
      showToast(activate ? 'Acara presensi berhasil diaktifkan!' : 'Acara presensi berhasil dinonaktifkan.', 'success');
      
      const targetEv = (state.events || []).find(e => e.id == id);
      if (typeof window.setActiveEventInFirebase === 'function') {
        if (activate && targetEv) {
          window.setActiveEventInFirebase({
            id: targetEv.id,
            name: targetEv.name,
            event_date: targetEv.event_date,
            date: targetEv.event_date,
            start_time: targetEv.start_time,
            end_time: targetEv.end_time,
            is_active: 1
          });
        } else {
          window.setActiveEventInFirebase(null);
        }
      }

      loadEvents();
      loadDashboard();
    } else {
      showToast(data.message || 'Gagal mengubah status acara', 'danger');
    }
  } catch (e) {
    console.error(e);
    showToast('Gagal mengubah status acara aktif', 'danger');
  }
}

function setActiveEvent(id) {
  return toggleEventActive(id, true);
}

async function deleteEvent(id) {
  const ev = (state.events || []).find(e => e.id == id);
  const name = ev ? ev.name : 'Acara';
  if (!confirm(`Yakin ingin menghapus program kerja "${name}"?\nData presensi lama tidak akan hilang tetapi status acara akan dilepas.`)) return;

  try {
    const res = await fetch(API.events, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id })
    });
    const data = await res.json();
    if (data.success) {
      showToast('Acara berhasil dihapus', 'success');

      // Jika acara yang dihapus sedang aktif, kosongkan di Firebase Cloud
      if (ev && ev.is_active == 1 && typeof window.setActiveEventInFirebase === 'function') {
        window.setActiveEventInFirebase(null);
      }

      loadEvents();
      loadDashboard();
    } else {
      showToast(data.message || 'Gagal menghapus acara', 'danger');
    }
  } catch (e) {
    console.error(e);
    showToast('Terjadi kesalahan saat menghapus acara', 'danger');
  }
}

// Absent member roster modal
let currentAbsentStudents = [];

async function openAlphaModal() {
  const modal = document.getElementById('modal-alpha');
  const tbody = document.getElementById('alpha-tbody');
  const countBadge = document.getElementById('alpha-count-badge');
  const titleDate = document.getElementById('alpha-date-title');

  const todayStr = state.selectedDate || getLocalDateString();
  const sessName = state.activeSession?.name || 'Sesi 1 (Datang / Pagi)';
  const sessId   = state.activeSession?.id   || 'sesi_1';

  if (titleDate) {
    titleDate.textContent = `Tanggal: ${todayStr} • ${state.activeEvent?.name || 'Kegiatan Umum'} • [${sessName}]`;
  }

  // Jika state.students belum dimuat, muat dari Cloud atau MySQL
  if (!state.students || state.students.length === 0) {
    if (window.cloudUsers && Object.keys(window.cloudUsers).length > 0) {
      state.students = Object.keys(window.cloudUsers).map(uid => ({
        id: uid,
        uid: uid,
        name: window.cloudUsers[uid].name || '-',
        nim: window.cloudUsers[uid].nim || '-'
      }));
    } else {
      try {
        const res = await fetch(API.students);
        const data = await res.json();
        state.students = data.data || [];
      } catch (e) {
        console.warn('Students fetch notice for alpha:', e);
      }
    }
  }

  // Kumpulkan semua mahasiswa terdaftar
  const allStudents = state.students || [];

  // Kumpulkan UID yang sudah hadir hari ini pada SESI AKTIF
  const presentUids = new Set();
  (state.attendance || []).forEach(r => {
    const sId = r.session_id || 'sesi_1';
    if (sId === sessId && r.uid) {
      presentUids.add(r.uid);
    }
  });

  // Cari yang belum hadir pada sesi aktif ini (hanya mahasiswa yang berstatus aktif)
  currentAbsentStudents = allStudents.filter(s => (s.is_active === undefined || s.is_active == 1) && !presentUids.has(s.uid));

  if (countBadge) countBadge.textContent = `${currentAbsentStudents.length} ANGGOTA`;

  if (!tbody) return;

  if (currentAbsentStudents.length === 0) {
    tbody.innerHTML = `
      <tr><td colspan="4">
        <div class="empty-state">
          <div class="empty-text" style="color: var(--color-green);">SEMUA ANGGOTA TELAH HADIR PADA SESI INI!</div>
          <div class="empty-sub">Tingkat kehadiran mencapai 100% untuk ${escapeHtml(sessName)}.</div>
        </div>
      </td></tr>`;
  } else {
    tbody.innerHTML = currentAbsentStudents.map((s, i) => `
      <tr>
        <td class="font-mono font-bold">${i + 1}</td>
        <td class="font-bold">${escapeHtml(s.name)}</td>
        <td class="font-mono">${escapeHtml(s.nim || '-')}</td>
        <td class="font-mono"><span class="td-uid">${escapeHtml(s.uid)}</span></td>
      </tr>
    `).join('');
  }

  if (modal) modal.classList.add('active', 'open');
}

function closeAlphaModal() {
  const modal = document.getElementById('modal-alpha');
  if (modal) modal.classList.remove('active', 'open');
}

function copyAlphaListToWhatsApp() {
  if (currentAbsentStudents.length === 0) {
    showToast('Tidak ada anggota yang belum hadir', 'info');
    return;
  }

  const eventName = state.activeEvent?.name || 'Kegiatan Organisasi';
  const sessName  = state.activeSession?.name || 'Sesi 1';
  const dateStr   = state.selectedDate || getLocalDateString();

  let text = `*DAFTAR ANGGOTA BELUM PRESENSI*\n`;
  text += `Acara: ${eventName}\n`;
  text += `Sesi: ${sessName}\n`;
  text += `Tanggal: ${dateStr}\n`;
  text += `Total Belum Hadir: ${currentAbsentStudents.length} Orang\n\n`;
  text += `----------------------------------------\n`;

  currentAbsentStudents.forEach((s, i) => {
    text += `${i + 1}. ${s.name} (NIM: ${s.nim || '-'})\n`;
  });

  text += `----------------------------------------\n`;
  text += `Diharapkan segera melakukan presensi kehadiran di meja registrasi. Terima kasih.`;

  const fallbackCopy = (val) => {
    const ta = document.createElement('textarea');
    ta.value = val;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try {
      document.execCommand('copy');
      showToast('Daftar belum hadir disalin ke clipboard.', 'success');
    } catch {
      showToast('Gagal menyalin ke clipboard', 'danger');
    }
    document.body.removeChild(ta);
  };

  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(() => {
      showToast('Daftar belum hadir disalin ke clipboard.', 'success');
    }).catch(() => fallbackCopy(text));
  } else {
    fallbackCopy(text);
  }
}

// Batch student registration import
let importedRowsCache = [];

function openImportModal() {
  const modal = document.getElementById('modal-import');
  importedRowsCache = [];
  const tbody = document.getElementById('import-preview-tbody');
  const countBadge = document.getElementById('import-count-badge');
  const btnSubmit = document.getElementById('btn-submit-import');
  const fileInput = document.getElementById('import-file-input');

  if (fileInput) fileInput.value = '';
  if (countBadge) countBadge.textContent = '0 DATA';
  if (btnSubmit) btnSubmit.disabled = true;
  if (tbody) {
    tbody.innerHTML = `
      <tr><td colspan="4">
        <div class="empty-state">
          <div class="empty-text">PILIH FILE CSV / EXCEL (.XLSX)</div>
          <div class="empty-sub">File harus memiliki kolom: UID (atau RFID), Nama (atau Name), dan NIM</div>
        </div>
      </td></tr>`;
  }

  if (modal) modal.classList.add('active', 'open');
}

function closeImportModal() {
  const modal = document.getElementById('modal-import');
  if (modal) modal.classList.remove('active', 'open');
}

function handleImportFile(event) {
  const file = event.target.files[0];
  if (!file) return;

  const reader = new FileReader();
  reader.onload = function(e) {
    try {
      const data = new Uint8Array(e.target.result);
      const workbook = XLSX.read(data, { type: 'array' });
      const firstSheetName = workbook.SheetNames[0];
      const worksheet = workbook.Sheets[firstSheetName];
      const json = XLSX.utils.sheet_to_json(worksheet, { defval: '' });

      if (!json || json.length === 0) {
        showToast('File tidak memiliki baris data', 'warning');
        return;
      }

      // Normalisasi kolom
      importedRowsCache = json.map(row => {
        let uid = '', name = '', nim = '';
        for (const k of Object.keys(row)) {
          const lk = k.toLowerCase().trim();
          if (lk.includes('uid') || lk.includes('rfid') || lk.includes('kartu')) {
            uid = String(row[k]).trim().toUpperCase();
          } else if (lk.includes('nama') || lk.includes('name')) {
            name = String(row[k]).trim();
          } else if (lk.includes('nim') || lk.includes('npm') || lk.includes('nrp')) {
            nim = String(row[k]).trim();
          }
        }
        return { uid, name, nim };
      }).filter(r => r.name && r.uid);

      renderImportPreview(importedRowsCache);
    } catch (err) {
      console.error(err);
      showToast('Gagal membaca berkas. Pastikan format Excel/CSV valid.', 'danger');
    }
  };
  reader.readAsArrayBuffer(file);
}

function renderImportPreview(rows) {
  const tbody = document.getElementById('import-preview-tbody');
  const countBadge = document.getElementById('import-count-badge');
  const btnSubmit = document.getElementById('btn-submit-import');

  if (countBadge) countBadge.textContent = `${rows.length} DATA SIAP`;
  if (btnSubmit) btnSubmit.disabled = rows.length === 0;

  if (!tbody) return;

  if (rows.length === 0) {
    tbody.innerHTML = `
      <tr><td colspan="4">
        <div class="empty-state">
          <div class="empty-text text-danger">TIDAK ADA DATA VALID TERDETEKSI</div>
          <div class="empty-sub">Pastikan file memiliki header kolom UID dan Nama</div>
        </div>
      </td></tr>`;
    return;
  }

  tbody.innerHTML = rows.slice(0, 50).map((r, i) => `
    <tr>
      <td class="font-mono font-bold">${i + 1}</td>
      <td class="font-mono"><span class="td-uid">${escapeHtml(r.uid)}</span></td>
      <td class="font-bold">${escapeHtml(r.name)}</td>
      <td class="font-mono">${escapeHtml(r.nim || '-')}</td>
    </tr>
  `).join('');

  if (rows.length > 50) {
    tbody.innerHTML += `<tr><td colspan="4" class="text-center font-mono text-muted py-2">... dan ${rows.length - 50} data lainnya</td></tr>`;
  }
}

async function submitBatchImport() {
  if (importedRowsCache.length === 0) return;

  const btnSubmit = document.getElementById('btn-submit-import');
  if (btnSubmit) {
    btnSubmit.disabled = true;
    btnSubmit.textContent = 'Mengimport...';
  }

  try {
    // 1. Simpan massal ke MySQL via batch API
    const res = await fetch(API.students, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ batch: true, students: importedRowsCache })
    });
    const data = await res.json();

    // 2. Sinkronkan ke Firebase jika fungsi tersedia
    if (typeof window.registerUserToFirebase === 'function' && window.isFirebaseConnected) {
      for (const item of importedRowsCache) {
        try {
          await window.registerUserToFirebase(item.uid, item.name, item.nim);
        } catch (e) {}
      }
    }

    if (data.success) {
      showToast(data.message || `Berhasil mengimport ${importedRowsCache.length} mahasiswa!`, 'success');
    } else {
      showToast(data.message || 'Gagal mengimport data', 'danger');
    }
  } catch (e) {
    console.error('Batch import error:', e);
    showToast('Terjadi kesalahan saat mengimport data', 'danger');
  } finally {
    closeImportModal();
    loadStudents();
    loadDashboard();
  }
}

// Administrator password management
function openChangePasswordModal() {
  const modal = document.getElementById('modal-password');
  document.getElementById('pwd-old').value = '';
  document.getElementById('pwd-new').value = '';
  document.getElementById('pwd-confirm').value = '';
  if (modal) modal.classList.add('active', 'open');
}

function closeChangePasswordModal() {
  const modal = document.getElementById('modal-password');
  if (modal) modal.classList.remove('active', 'open');
}

async function submitChangePassword() {
  const old_password     = document.getElementById('pwd-old')?.value || '';
  const new_password     = document.getElementById('pwd-new')?.value || '';
  const confirm_password = document.getElementById('pwd-confirm')?.value || '';

  if (!old_password || !new_password || !confirm_password) {
    showToast('Semua field password wajib diisi', 'warning');
    return;
  }

  if (new_password.length < 6) {
    showToast('Password baru minimal 6 karakter', 'warning');
    return;
  }

  if (new_password !== confirm_password) {
    showToast('Konfirmasi password tidak cocok', 'warning');
    return;
  }

  try {
    const res = await fetch(API.admin, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ old_password, new_password, confirm_password })
    });
    const data = await res.json();
    if (data.success) {
      showToast(data.message || 'Password admin berhasil diubah!', 'success');
      closeChangePasswordModal();
    } else {
      showToast(data.message || 'Gagal mengubah password', 'danger');
    }
  } catch (e) {
    console.error(e);
    showToast('Terjadi kesalahan saat mengubah password', 'danger');
  }
}

// Application bootstrap and event listeners
document.addEventListener('DOMContentLoaded', () => {
  // Jalankan jam
  updateClock();
  setInterval(updateClock, 1000);

  // Inisialisasi status audio toggle
  updateAudioToggleUI();

  // Set default tanggal hari ini pada input rekap
  const rekapDate = document.getElementById('rekap-date');
  if (rekapDate) rekapDate.value = state.selectedDate;

  // Load acara aktif & initial panel
  loadEvents();
  const initialPanel = document.body.dataset.initialPanel || 'dashboard';
  showPanel(initialPanel, false);

  // Listener popstate browser (tombol back / forward untuk Clean URL)
  window.addEventListener('popstate', (e) => {
    const p = (e.state && e.state.panel)
      ? e.state.panel
      : (window.location.pathname.replace(/\/+$/, '').split('/').pop() || 'dashboard');
    const validPanels = ['dashboard', 'tambah', 'mahasiswa', 'rekap', 'events'];
    showPanel(validPanels.includes(p) ? p : 'dashboard', false);
  });

  // Polling auto-refresh
  startPolling();

  // ESP Status check setiap 10 detik
  setInterval(checkEspStatus, 10000);

  // ESC untuk tutup modal
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      closeRegisterModal();
      closeEditModal();
      closeCreateEventModal();
      closeAlphaModal();
      closeImportModal();
      closeChangePasswordModal();
    }
  });

  // Close modal saat klik overlay
  document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) {
        closeRegisterModal();
        closeEditModal();
        closeCreateEventModal();
        closeAlphaModal();
        closeImportModal();
        closeChangePasswordModal();
      }
    });
  });

  // Expose methods to global window
  window.showToast = showToast;
  window.showPanel = showPanel;
  window.loadDashboard = loadDashboard;
  window.loadUnknownCards = loadUnknownCards;
  window.loadStudents = loadStudents;
  window.loadRekap = loadRekap;
  window.loadEvents = loadEvents;
  window.onSessionChange = onSessionChange;
  window.updateActiveSessionDisplay = updateActiveSessionDisplay;
  window.formatSessionLabel = formatSessionLabel;
  window.updateLiveFeed = updateLiveFeed;
  window.updateActiveEventDisplay = updateActiveEventDisplay;
  window.renderUnknownCards = renderUnknownCards;
  window.renderDashboardTable = renderDashboardTable;
  window.renderStudentsTable = renderStudentsTable;
  window.renderCloudStudents = renderCloudStudents;
  window.openRegisterModal = openRegisterModal;
  window.closeRegisterModal = closeRegisterModal;
  window.submitRegister = submitRegister;
  window.openAddManualModal = openAddManualModal;
  window.openEditModal = openEditModal;
  window.closeEditModal = closeEditModal;
  window.submitEdit = submitEdit;
  window.deleteStudent = deleteStudent;
  window.searchStudents = searchStudents;
  window.clearRekapByDate = clearRekapByDate;
  window.openCreateEventModal = openCreateEventModal;
  window.closeCreateEventModal = closeCreateEventModal;
  window.submitCreateEvent = submitCreateEvent;
  window.setActiveEvent = setActiveEvent;
  window.toggleEventActive = toggleEventActive;
  window.deleteEvent = deleteEvent;
  window.openAlphaModal = openAlphaModal;
  window.closeAlphaModal = closeAlphaModal;
  window.copyAlphaListToWhatsApp = copyAlphaListToWhatsApp;
  window.openImportModal = openImportModal;
  window.closeImportModal = closeImportModal;
  window.handleImportFile = handleImportFile;
  window.submitBatchImport = submitBatchImport;
  window.openChangePasswordModal = openChangePasswordModal;
  window.closeChangePasswordModal = closeChangePasswordModal;
  window.submitChangePassword = submitChangePassword;
  window.downloadCSV = downloadCSV;
  window.downloadExcel = downloadExcel;
  window.exportAttendanceToday = exportAttendanceToday;
  window.exportAttendance = exportAttendance;
  window.exportAllAttendance = exportAllAttendance;
  window.exportStudents = exportStudents;
  window.toggleAudioChime = toggleAudioChime;
  window.setQuickDate = setQuickDate;
  window.playTapChime = playTapChime;

  // Antislop R-32: Keyboard accessibility (Escape key closes open modals)
  window.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal-overlay.active, .modal-overlay.open').forEach(m => {
        m.classList.remove('active', 'open');
      });
    }
  });
});
