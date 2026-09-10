// ============================================================
// firebase-service.js - Realtime Database Sync for Dashboard
// Fully compatible with ESP8266 + Firebase Realtime Database
//   - /log_presensi (Live tap stream from ESP8266)
//   - /unknown_cards (Unknown RFID cards from ESP8266)
//   - /users (Registered student database)
// ============================================================

import { db, ref, onValue, set, remove, update, query, limitToLast } from "./firebase-config.js";

// Status koneksi Firebase
let isFirebaseConnected = false;
let isInitialLoad = true;
let debounceDashboardTimer = null;

function triggerDebouncedDashboard() {
  if (debounceDashboardTimer) clearTimeout(debounceDashboardTimer);
  debounceDashboardTimer = setTimeout(() => {
    if (typeof window.loadDashboard === 'function') {
      window.loadDashboard();
    }
  }, 250);
}

// Cache data cloud
export let cloudUnknownCards = [];
export let cloudUsers = {};
export let cloudLogs = [];

// Helper untuk decode timestamp dari Firebase Push Key (cth: -O...)
function getTimestampFromFirebasePushId(id) {
  const PUSH_CHARS = '-0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcdefghijklmnopqrstuvwxyz';
  if (!id || typeof id !== 'string' || id.length < 8) return null;
  let time = 0;
  for (let i = 0; i < 8; i++) {
    const c = id.charAt(i);
    const index = PUSH_CHARS.indexOf(c);
    if (index === -1) return null;
    time = time * 64 + index;
  }
  return time;
}

// Helper format tanggal lokal YYYY-MM-DD
function getLocalDateString(d = new Date()) {
  const year = d.getFullYear();
  const month = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

// Helper ekstrak waktu & tanggal akurat dari log
function parseLogDateTime(item, key) {
  let waktuStr = item.waktu || null;
  let dateStr = item.date || null;
  let timestamp = null;

  // 1. Cek jika item memiliki timestamp epoch valid
  if (item.timestamp && typeof item.timestamp === 'number') {
    if (item.timestamp > 1000000000000) {
      timestamp = item.timestamp;
    } else if (item.timestamp > 1000000000) {
      timestamp = item.timestamp * 1000;
    }
  }

  // 2. Jika tidak ada timestamp valid, decode dari Firebase Push Key
  if (!timestamp && key) {
    const decoded = getTimestampFromFirebasePushId(key);
    if (decoded && decoded > 1577836800000 && decoded < 2524608000000) {
      timestamp = decoded;
    }
  }

  // 3. Konversi timestamp ke format waktu (HH.MM) & tanggal (YYYY-MM-DD)
  if (timestamp) {
    const dateObj = new Date(timestamp);
    if (!waktuStr) {
      waktuStr = dateObj.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }).replace(/:/g, '.');
    }
    if (!dateStr) {
      dateStr = getLocalDateString(dateObj);
    }
  }

  // 4. Fallback jika sama sekali tidak ada data waktu
  if (!waktuStr) {
    waktuStr = new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }).replace(/:/g, '.');
  }
  if (!dateStr) {
    dateStr = getLocalDateString(new Date());
  }

  return { waktu: waktuStr, date: dateStr, timestamp: timestamp || Date.now() };
}

export function initFirebaseListeners() {
  console.log("[Firebase] Menginisialisasi Realtime Listener...");

  // 1. Monitor Status Koneksi Cloud Browser
  const connectedRef = ref(db, ".info/connected");
  onValue(connectedRef, (snap) => {
    isFirebaseConnected = snap.val() === true;
    window.isFirebaseConnected = isFirebaseConnected;
    if (!isFirebaseConnected) {
      const espText = document.getElementById("esp-status-text");
      const espDot = document.getElementById("esp-dot");
      if (espText) espText.textContent = "OFFLINE";
      if (espDot) espDot.className = "esp-dot offline";
    }
  });

  // 1b. Real IoT Hardware Heartbeat Telemetry: /devices/esp8266
  const deviceRef = ref(db, "devices/esp8266");
  onValue(deviceRef, (snapshot) => {
    const data = snapshot.val();
    const espText = document.getElementById("esp-status-text");
    const espDot = document.getElementById("esp-dot");
    if (!espText || !espDot) return;

    if (data && data.last_seen && (Date.now() - data.last_seen < 45000)) {
      espText.textContent = "ONLINE (CLOUD)";
      espDot.className = "esp-dot online";
    } else {
      espText.textContent = "OFFLINE";
      espDot.className = "esp-dot offline";
    }
  });

  // 2. Realtime Listener: /users (Sinkronisasi Daftar Mahasiswa di Cloud)
  const usersRef = ref(db, "users");
  onValue(usersRef, (snapshot) => {
    const data = snapshot.val() || {};
    cloudUsers = data;
    window.cloudUsers = data;
    console.log(`[Firebase] ${Object.keys(data).length} data mahasiswa terdaftar di Cloud.`);

    // Refresh view data mahasiswa & dashboard jika fungsi sudah tersedia
    if (typeof window.renderCloudStudents === 'function') {
      window.renderCloudStudents();
    }
    triggerDebouncedDashboard();
  });

  // 3. Realtime Listener: /unknown_cards (Pushed by ESP8266 when unknown RFID is tapped)
  const unknownCardsRef = ref(db, "unknown_cards");
  onValue(unknownCardsRef, (snapshot) => {
    const data = snapshot.val();
    const badge = document.getElementById("unknown-badge");
    const countBadge = document.getElementById("unknown-count-badge");
    
    if (data && typeof data === 'object') {
      const uids = Object.keys(data);
      const count = uids.length;

      if (badge) {
        if (count > 0) {
          badge.textContent = count;
          badge.style.display = "inline-block";
        } else {
          badge.style.display = "none";
        }
      }

      if (countBadge) {
        countBadge.textContent = `${count} KARTU`;
      }

      // Format data untuk tabel web
      cloudUnknownCards = uids.map(uid => {
        const item = data[uid];
        return {
          uid: uid,
          tap_count: typeof item === 'object' && item.tap_count ? item.tap_count : 1,
          first_seen: typeof item === 'object' && item.first_seen ? item.first_seen : new Date().toISOString(),
          last_seen: typeof item === 'object' && item.last_seen ? item.last_seen : new Date().toISOString()
        };
      });

      window.cloudUnknownCards = cloudUnknownCards;

      // Render tabel secara langsung!
      if (typeof window.renderUnknownCards === 'function') {
        window.renderUnknownCards(cloudUnknownCards);
      }

      // Tampilkan toast notifikasi jika ada kartu baru masuk
      if (!isInitialLoad && count > 0 && typeof window.showToast === 'function') {
        const lastUid = uids[uids.length - 1];
        window.showToast(`Kartu Baru Terdeteksi: ${lastUid}`, 'warning');
      }
    } else {
      cloudUnknownCards = [];
      window.cloudUnknownCards = [];
      if (badge) badge.style.display = "none";
      if (countBadge) countBadge.textContent = "0 KARTU";
      if (typeof window.renderUnknownCards === 'function') {
        window.renderUnknownCards([]);
      }
    }
  });

  // 4. Realtime Listener: /log_presensi (Pushed by ESP8266 on every tap, limited to last 100 for high performance)
  const logPresensiRef = query(ref(db, "log_presensi"), limitToLast(100));
  onValue(logPresensiRef, (snapshot) => {
    const data = snapshot.val();
    if (data && typeof data === 'object') {
      const keys = Object.keys(data);
      if (keys.length > 0) {
        cloudLogs = keys.map((k) => {
          const item = data[k] || {};
          const studentInfo = (cloudUsers && cloudUsers[item.uid]) || {};
          const parsed = parseLogDateTime(item, k);

          return {
            id: k,
            uid: item.uid || '-',
            name: item.name || studentInfo.name || `Mahasiswa (${item.uid})`,
            nim: studentInfo.nim || item.nim || '-',
            session_id: item.session_id || 'sesi_1',
            session_name: item.session_name || 'Sesi 1 (Datang)',
            event_name: item.event_name || 'Kegiatan HIMA Umum',
            waktu: parsed.waktu,
            date: parsed.date,
            timestamp: parsed.timestamp
          };
        });

        // Urutkan dari yang terbaru ke terlama
        cloudLogs.sort((a, b) => b.timestamp - a.timestamp);
        window.cloudLogs = cloudLogs;

        const latestLog = cloudLogs[0];
        console.log("[Firebase] Log Presensi Terkini:", latestLog);

        // Update Live Feed di Dashboard dengan log terbaru (hanya bunyikan suara jika bukan initial load)
        if (latestLog && typeof window.updateLiveFeed === 'function') {
          window.updateLiveFeed(latestLog.name, latestLog.waktu, !isInitialLoad);
        }

        // Tampilkan Toast jika bukan saat halaman baru pertama kali dibuka
        if (!isInitialLoad && latestLog && typeof window.showToast === 'function') {
          const sessLabel = latestLog.session_name ? ` [${latestLog.session_name}]` : '';
          window.showToast(`Presensi: ${latestLog.name}${sessLabel} (${latestLog.waktu})`, 'success');
        }

        // Refresh data dashboard & rekap dengan debounce
        triggerDebouncedDashboard();
        if (typeof window.loadRekap === 'function') {
          window.loadRekap();
        }
      }
    } else {
      cloudLogs = [];
      window.cloudLogs = [];
      triggerDebouncedDashboard();
      if (typeof window.loadRekap === 'function') window.loadRekap();
    }
  });

  // 5. Realtime Listener: /active_event (Acara/Program Kerja Aktif)
  const activeEventRef = ref(db, "active_event");
  onValue(activeEventRef, (snapshot) => {
    const data = snapshot.val();
    window.cloudActiveEvent = data;
    if (typeof window.updateActiveEventDisplay === 'function') {
      window.updateActiveEventDisplay(data);
    }
  });

  // 6. Realtime Listener: /active_session (Sesi Presensi Aktif)
  const activeSessionRef = ref(db, "active_session");
  onValue(activeSessionRef, (snapshot) => {
    const data = snapshot.val();
    window.cloudActiveSession = data;
    if (typeof window.updateActiveSessionDisplay === 'function') {
      window.updateActiveSessionDisplay(data);
    }
  });

  // Tandai initial load selesai setelah 1.5 detik
  setTimeout(() => {
    isInitialLoad = false;
  }, 1500);
}

// Helper untuk mendaftarkan user langsung ke Firebase /users/{uid}
export async function registerUserToFirebase(uid, name, nim) {
  try {
    const userRef = ref(db, `users/${uid}`);
    await set(userRef, {
      name: name,
      nim: nim || "",
      registered_at: Date.now()
    });

    // Hapus dari unknown_cards di cloud
    const unknownRef = ref(db, `unknown_cards/${uid}`);
    await set(unknownRef, null);

    console.log(`[Firebase] User ${name} (${uid}) berhasil disimpan ke cloud.`);
    return true;
  } catch (error) {
    console.error("[Firebase] Gagal menyimpan user ke cloud:", error);
    return false;
  }
}

// Helper untuk sinkronisasi acara aktif ke Firebase
export async function setActiveEventInFirebase(eventData) {
  try {
    const eventRef = ref(db, "active_event");
    await set(eventRef, eventData);
    console.log("[Firebase] Acara aktif berhasil disinkronkan ke cloud:", eventData);
    return true;
  } catch (error) {
    console.error("[Firebase] Gagal update acara aktif di cloud:", error);
    return false;
  }
}

// Helper untuk sinkronisasi sesi presensi aktif ke Firebase
export async function setActiveSessionInFirebase(sessionData, optionalName) {
  try {
    let payload;
    if (typeof sessionData === 'object' && sessionData !== null) {
      payload = {
        id: sessionData.id || 'sesi_1',
        name: sessionData.name || 'Sesi 1 (Datang)'
      };
    } else {
      payload = {
        id: String(sessionData || 'sesi_1'),
        name: String(optionalName || sessionData || 'Sesi 1 (Datang)')
      };
    }

    const sessionRef = ref(db, "active_session");
    await set(sessionRef, payload);
    console.log("[Firebase] Sesi presensi aktif berhasil disinkronkan ke cloud:", payload);
    return true;
  } catch (error) {
    console.error("[Firebase] Gagal update sesi aktif di cloud:", error);
    return false;
  }
}

// Helper untuk menghapus user dari cloud Firebase
export async function deleteUserFromFirebase(uid) {
  try {
    const userRef = ref(db, `users/${uid}`);
    await remove(userRef);
    console.log(`[Firebase] User (${uid}) berhasil dihapus dari cloud.`);
    return true;
  } catch (error) {
    console.error("[Firebase] Gagal menghapus user dari cloud:", error);
    return false;
  }
}

// Helper untuk menghapus log kehadiran dari Firebase berdasarkan tanggal
export async function clearRekapFromFirebase(date) {
  if (!window.isFirebaseConnected) return 0;
  try {
    const logs = window.cloudLogs || [];
    const logsToDelete = logs.filter(l => l.date === date);

    if (logsToDelete.length > 0) {
      for (const item of logsToDelete) {
        if (item.id) {
          await remove(ref(db, `log_presensi/${item.id}`));
        }
      }
    }

    // Bersihkan juga node attendance_today agar kartu bisa tap ulang jika dibutuhkan
    await clearAttendanceTodayNode(date);

    console.log(`[Firebase] Berhasil membersihkan ${logsToDelete.length} data presensi (${date})`);
    return logsToDelete.length;
  } catch (err) {
    console.error("[Firebase] Gagal menghapus rekap cloud:", err);
    throw err;
  }
}

// Helper untuk membersihkan node /attendance_today pada tanggal tertentu
export async function clearAttendanceTodayNode(date) {
  if (!window.isFirebaseConnected || !date) return;
  try {
    const sessionIds = new Set(['sesi_1', 'sesi_2', 'sesi_3']);
    if (window.cloudLogs && Array.isArray(window.cloudLogs)) {
      window.cloudLogs.forEach(l => {
        if (l.session_id) sessionIds.add(l.session_id);
      });
    }
    for (const s of sessionIds) {
      const nodeRef = ref(db, `attendance_today/${date}_${s}`);
      await remove(nodeRef);
    }
  } catch (err) {
    console.warn("[Firebase] Gagal membersihkan node attendance_today:", err);
  }
}

// Helper batch mendaftarkan user secara atomik multi-path (1 network request)
export async function batchRegisterUsersToFirebase(studentsList) {
  if (!window.isFirebaseConnected || !studentsList || studentsList.length === 0) return true;
  try {
    const updates = {};
    const now = Date.now();
    for (const s of studentsList) {
      if (s.uid && s.name) {
        updates[`users/${s.uid}`] = {
          name: s.name,
          nim: s.nim || "",
          registered_at: now
        };
        updates[`unknown_cards/${s.uid}`] = null;
      }
    }
    await update(ref(db), updates);
    console.log(`[Firebase] Batch ${studentsList.length} users updated atomically.`);
    return true;
  } catch (error) {
    console.error("[Firebase] Batch register error:", error);
    return false;
  }
}

// Expose helper ke window
window.registerUserToFirebase = registerUserToFirebase;
window.batchRegisterUsersToFirebase = batchRegisterUsersToFirebase;
window.deleteUserFromFirebase = deleteUserFromFirebase;
window.setActiveEventInFirebase = setActiveEventInFirebase;
window.setActiveSessionInFirebase = setActiveSessionInFirebase;
window.clearRekapFromFirebase = clearRekapFromFirebase;
window.clearAttendanceTodayNode = clearAttendanceTodayNode;

// Jalankan otomatis saat script dimuat
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initFirebaseListeners);
} else {
  initFirebaseListeners();
}
