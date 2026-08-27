// ============================================================
// firebase-service.js - Realtime Database Sync for Dashboard
// Fully compatible with ESP8266 + Firebase Realtime Database
//   - /log_presensi (Live tap stream from ESP8266)
//   - /unknown_cards (Unknown RFID cards from ESP8266)
//   - /users (Registered student database)
// ============================================================

import { db, ref, onValue, set, remove } from "./firebase-config.js";

// Status koneksi Firebase
let isFirebaseConnected = false;
let isInitialLoad = true;

// Cache data cloud
export let cloudUnknownCards = [];
export let cloudUsers = {};
export let cloudLogs = [];

export function initFirebaseListeners() {
  console.log("[Firebase] Menginisialisasi Realtime Listener...");

  // 1. Monitor Status Koneksi Firebase
  const connectedRef = ref(db, ".info/connected");
  onValue(connectedRef, (snap) => {
    isFirebaseConnected = snap.val() === true;
    window.isFirebaseConnected = isFirebaseConnected;
    if (isFirebaseConnected) {
      console.log("[Firebase] Terhubung ke Cloud Realtime Database!");
      const espText = document.getElementById("esp-status-text");
      const espDot = document.getElementById("esp-dot");
      if (espText) espText.textContent = "ONLINE (CLOUD)";
      if (espDot) espDot.className = "esp-dot online";
    } else {
      console.log("[Firebase] Menunggu koneksi cloud...");
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
    if (typeof window.loadDashboard === 'function') {
      window.loadDashboard();
    }
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

  // 4. Realtime Listener: /log_presensi (Pushed by ESP8266 on every successful tap)
  const logPresensiRef = ref(db, "log_presensi");
  onValue(logPresensiRef, (snapshot) => {
    const data = snapshot.val();
    if (data && typeof data === 'object') {
      const keys = Object.keys(data);
      if (keys.length > 0) {
        cloudLogs = keys.map((k, idx) => {
          const item = data[k];
          const studentInfo = (cloudUsers && cloudUsers[item.uid]) || {};
          return {
            id: k,
            uid: item.uid,
            name: item.name || studentInfo.name || `Mahasiswa (${item.uid})`,
            nim: studentInfo.nim || item.nim || '-',
            waktu: item.waktu || new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }),
            date: item.date || new Date().toISOString().split('T')[0]
          };
        });

        window.cloudLogs = cloudLogs;

        const lastLog = cloudLogs[cloudLogs.length - 1];
        console.log("[Firebase] Log Presensi Baru:", lastLog);

        // Update Live Feed di Dashboard
        if (typeof window.updateLiveFeed === 'function') {
          window.updateLiveFeed(lastLog.name, lastLog.waktu);
        }

        // Tampilkan Toast jika bukan saat halaman baru dibuka
        if (!isInitialLoad && typeof window.showToast === 'function') {
          window.showToast(`Presensi Berhasil: ${lastLog.name} (${lastLog.uid})`, 'success');
        }

        // Refresh data dashboard & rekap
        if (typeof window.loadDashboard === 'function') {
          window.loadDashboard();
        }
        if (typeof window.loadRekap === 'function') {
          window.loadRekap();
        }
      }
    } else {
      cloudLogs = [];
      window.cloudLogs = [];
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

// Helper untuk menghapus user dari Firebase
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

// Expose helper ke window
window.registerUserToFirebase = registerUserToFirebase;
window.deleteUserFromFirebase = deleteUserFromFirebase;

// Jalankan otomatis saat script dimuat
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initFirebaseListeners);
} else {
  initFirebaseListeners();
}
