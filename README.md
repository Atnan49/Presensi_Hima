# Sistem Presensi Mahasiswa Berbasis RFID (Presensi HIMA)

Sistem Presensi Mahasiswa terintegrasi berbasis kartu RFID / NFC dengan antarmuka Web Neo-Brutalist (*Wireframe-Inspired*), Cloud Database (Firebase Realtime Database), Server Lokal (PHP/MySQL), Aplikasi Desktop GUI (Python/Tkinter), serta Hardware IoT (ESP8266 NodeMCU + PN532 / RC522).

---

## Fitur Utama

- **Web Dashboard (Neo-Brutalist / Real-time Sync)**:
  - Tampilan *Clean Wireframe* monokrom elegan berorientasi keterbacaan tinggi.
  - Sinkronisasi instan *sub-detik* dengan Firebase Realtime Database saat kartu di-tap.
  - Live Feed pemantauan tap kartu secara langsung.
  - Manajemen data Mahasiswa (Tambah, Edit, Hapus).
  - Pendaftaran otomatis kartu belum terdaftar (*Unknown Cards*).
  - Rekapitulasi & Export data presensi ke format Excel (.xlsx).
  - Indikator status perangkat IoT (Online / Offline).

- **Hardware & IoT (ESP8266 + NFC PN532 / RC522)**:
  - Kompatibel dengan NFC PN532 (SPI) dan RFID RC522.
  - Dua mode operasi:
    1. **Mode Firebase Cloud** (`arduino/presensi_firebase`): Kirim data langsung ke internet tanpa butuh laptop/localhost aktif.
    2. **Mode Localhost HTTP** (`arduino/presensi_esp8266` & `arduino/presensi_rfid`): Kirim via HTTP REST ke Laragon/XAMPP.
  - Modulasi nada buzzer interaktif (berhasil, kartu baru, error).
  - Tampilan LCD 16x2 I2C dengan pesan sambutan nama mahasiswa.

- **Desktop Application (Python Tkinter)**:
  - Antarmuka GUI interaktif dengan Tkinter.
  - Dashboard statistik kehadiran & status perangkat.
  - Export laporan presensi ke Excel.

---

## Struktur Direktori

```text
├── api/                    # REST API backend PHP (attendance, students, check_uid, dll.)
├── arduino/                # Source code firmware ESP8266 (Arduino IDE)
│   ├── presensi_firebase/  # Firmware Cloud Firebase Realtime Database (Rekomendasi)
│   ├── presensi_esp8266/   # Firmware Localhost PN532 (Mode SPI)
│   └── presensi_rfid/      # Firmware Localhost RC522 (Mode SPI)
├── assets/                 # Frontend assets
│   ├── style.css           # Neo-Brutalism wireframe design system
│   ├── app.js              # Frontend dashboard logic
│   ├── firebase-config.js  # Konfigurasi Firebase Web SDK
│   └── firebase-service.js # Listener Realtime Database cloud
├── exports/                # Direktori output file export Excel
├── php/                    # Modul backend PHP
├── python/                 # Source code aplikasi desktop GUI Python
├── sql/                    # Skema database MySQL (presensi.sql)
├── config.php              # Konfigurasi database MySQL & server
├── index.php               # Entry point antarmuka Web Dashboard
└── README.md
```

---

## Panduan Penggunaan

### 1. Web Dashboard
1. Jalankan web server lokal (Laragon / XAMPP / PHP CLI):
   ```bash
   php -S 127.0.0.1:8099 -t .
   ```
2. Buka browser pada alamat `http://localhost:8099/index.php`.
3. Web dashboard akan langsung terhubung ke Firebase Cloud secara otomatis.

### 2. Firmware ESP8266 (Mode Firebase Cloud)
1. Buka Arduino IDE.
2. Install library yang dibutuhkan melalui *Library Manager*:
   - `Firebase Arduino Client Library for ESP8266 and ESP32` (by Mobizt)
   - `Adafruit PN532` (by Adafruit)
   - `LiquidCrystal I2C` (by Frank de Brabander)
3. Buka file `arduino/presensi_firebase/presensi_firebase.ino`.
4. Sesuaikan `WIFI_SSID` dan `WIFI_PASSWORD` dengan hotspot/Wi-Fi Anda.
5. Upload ke board `NodeMCU 1.0 (ESP-12E Module)`.

### 3. Database MySQL (Opsional jika ingin backup lokal)
1. Buka phpMyAdmin / Laragon.
2. Buat database baru bernama `presensi`.
3. Import file `sql/presensi.sql`.
4. Sesuaikan kredensial di `config.php` jika diperlukan.
