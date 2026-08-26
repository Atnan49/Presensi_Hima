# Sistem Presensi Mahasiswa Berbasis RFID (Presensi HIMA)

Sistem Presensi Mahasiswa terintegrasi berbasis kartu RFID dengan dukungan antarmuka Web (PHP/MySQL), Aplikasi Desktop GUI (Python/Tkinter), serta Hardware IoT (ESP8266 / Arduino Uno + RFID-RC522).

---

## 🚀 Fitur Utama

- **Web Application (PHP/MySQL)**:
  - Dashboard monitoring presensi secara *real-time*.
  - Manajemen data Mahasiswa (CRUD).
  - Manajemen data Acara / Kegiatan Himpunan.
  - Rekapitulasi dan Export data presensi ke format Excel / Google Sheets.
  - Heartbeat status alat IoT (online/offline).
  - REST API endpoint untuk integrasi hardware.

- **Desktop Application (Python Tkinter)**:
  - Antarmuka GUI interaktif dengan Tkinter.
  - Dashboard statistik kehadiran & status perangkat.
  - Manajemen Mahasiswa & Acara.
  - Export laporan presensi ke Excel (.xlsx).

- **Hardware / IoT (Arduino / ESP8266)**:
  - Pembaca RFID RC522 (13.56 MHz).
  - ESP8266 Wi-Fi Module untuk pengiriman data tap kartu secara langsung ke Web API.
  - Feedback buzzer / LED / LCD I2C indikator tap kartu.

---

## 📁 Struktur Direktori

```text
├── api/                # REST API backend PHP (attendance, students, check_uid, dll.)
├── arduino/            # Source code firmware Arduino & ESP8266 (.ino)
├── assets/             # Asset styling dan frontend scripts (CSS, JS)
├── exports/            # Direktori output file export Excel
├── php/                # Modul halaman & logika PHP
├── python/             # Source code aplikasi desktop GUI Python
├── sql/                # Skrip skema database MySQL
├── config.php          # Konfigurasi database & global setting web
├── index.php           # Entry point aplikasi web
├── presensi_rfid.sql   # Dump skema database presensi
└── README.md
```

---

## 🛠️ Panduan Instalasi & Penggunaan

### 1. Database (MySQL)
1. Buka phpMyAdmin / Laragon / XAMPP.
2. Buat database baru bernama `presensi`.
3. Import file `sql/presensi.sql` atau `presensi_rfid.sql`.
4. Sesuaikan konfigurasi database pada file `config.php` jika diperlukan.

### 2. Web Application
1. Letakkan folder project di web root (misal `htdocs/` atau `www/`).
2. Akses aplikasi melalui browser di `http://localhost/presensi_hima` (atau sesuai konfigurasi web server).

### 3. Desktop Application (Python)
1. Buka terminal pada folder `python/`.
2. Install dependency yang diperlukan:
   ```bash
   pip install mysql-connector-python pandas openpyxl
   ```
3. Jalankan aplikasi:
   ```bash
   python main.py
   ```

### 4. Firmware Hardware (Arduino / ESP8266)
1. Buka file `.ino` pada folder `arduino/` menggunakan Arduino IDE.
2. Install library yang dibutuhkan (`MFRC522`, `ESP8266WiFi`, `ESP8266HTTPClient`, `LiquidCrystal_I2C`, dll.).
3. Konfigurasikan SSID, Password Wi-Fi, dan IP/URL Web Server.
4. Upload ke board microcontroller.
