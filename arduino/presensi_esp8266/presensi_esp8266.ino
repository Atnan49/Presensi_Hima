/*
 * ============================================================
 * SISTEM PRESENSI MAHASISWA
 * Hardware : ESP8266 Lolin (NodeMCU v3)
 * NFC      : PN532 (Mode SPI)
 * Display  : LCD 16x2 I2C
 * Buzzer   : Aktif/Pasif kecil
 * Jaringan : Hotspot HP "Aji" (iPhone)
 * Server   : Laragon localhost via hotspot
 * ============================================================
 *
 * WIRING:
 *   PN532 VCC  → 3.3V (pin 3V3 di NodeMCU - BUKAN 5V!)
 *   PN532 GND  → GND
 *   PN532 SCK  → D5 (GPIO14)
 *   PN532 MISO → D6 (GPIO12)
 *   PN532 MOSI → D7 (GPIO13)
 *   PN532 SS   → D8 (GPIO15)
 *   PN532 RSTO → D0 (GPIO16)
 *   PN532 IRQ  → D3 (GPIO0)  -- opsional, boleh tidak dipasang
 *
 *   LCD VCC  → VIN (5V dari adaptor)
 *   LCD GND  → GND
 *   LCD SDA  → D2 (GPIO4)
 *   LCD SCL  → D1 (GPIO5)
 *
 *   Buzzer + → D8 (GPIO15)
 *   Buzzer - → GND
 *
 *   Adaptor 5V 2A (+) → VIN
 *   Adaptor 5V 2A (-) → GND
 *
 * ============================================================
 * LIBRARY YANG HARUS DI-INSTALL (Library Manager):
 *   1. Adafruit PN532        by Adafruit
 *   2. LiquidCrystal I2C    by Frank de Brabander
 *   3. ArduinoJson           by Benoit Blanchon
 *
 * BOARD:
 *   Package : ESP8266 by ESP8266 Community
 *   URL     : http://arduino.esp8266.com/stable/package_esp8266com_index.json
 *   Board   : NodeMCU 1.0 (ESP-12E Module)
 *   Speed   : 115200
 * ============================================================
 */

// ============================================================
// LIBRARY
// ============================================================
#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClient.h>
#include <SPI.h>
#include <Adafruit_PN532.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <ArduinoJson.h>

// ============================================================
// KONFIGURASI - SUDAH DIISI, LANGSUNG UPLOAD!
// ============================================================

// Hotspot HP
const char* WIFI_SSID     = "Aji";           // Nama hotspot iPhone
const char* WIFI_PASSWORD = "gulamanis";     // Password hotspot

// IP laptop saat terhubung ke hotspot "Aji"
// Jika IP berubah → cek CMD: ipconfig → IPv4 Address
const char* SERVER_IP   = "172.20.10.4";
const int   SERVER_PORT = 80;

// Nama folder project di C:\laragon\www\ (nama foldernya: presensi)
String BASE_URL = String("http://") + SERVER_IP + ":" + SERVER_PORT + "/presensi";

// ============================================================
// KONFIGURASI PIN (Diperbarui agar bootloader lancar)
// ============================================================
#define PN532_SS    16   // D0 (GPIO16) - SS/CS PN532 (Pindah dari D8 agar tidak error boot mode 6,7)
#define BUZZER_PIN  15   // D8 (GPIO15) - Buzzer (pindah dari D4/GPIO2 yg bermasalah)

// LCD I2C
// Alamat default: 0x27
// Jika LCD tidak menyala → coba ganti ke 0x3F
#define LCD_ADDR  0x27
#define LCD_COLS  16
#define LCD_ROWS   2

// ============================================================
// INISIALISASI OBJEK
// ============================================================
Adafruit_PN532  nfc(PN532_SS); // Hardware SPI dengan SS = D0 (GPIO16)
LiquidCrystal_I2C lcd(LCD_ADDR, LCD_COLS, LCD_ROWS);
WiFiClient wifiClient;

// ============================================================
// VARIABEL GLOBAL
// ============================================================
unsigned long lastHeartbeat  = 0;
unsigned long lastDebounce   = 0;
const long    HEARTBEAT_INT  = 20000; // Kirim heartbeat ke server tiap 20 detik
const long    DEBOUNCE_DELAY = 1500;  // Jeda anti-dobel untuk kartu yang SAMA (kartu beda langsung instan)
String        lastUID        = "";

// ============================================================
// BUZZER - MANUAL PWM (Tidak terganggu WiFi stack!)
// tone() di ESP8266 pakai timer yang sama dengan WiFi → suara lemah
// Solusi: generate sinyal langsung via GPIO (selalu nyaring!)
// ============================================================

// Helper: generate tone dalam chunk 10ms + noInterrupts()
// → WiFi interrupt tidak bisa ganggu PWM → selalu nyaring & stabil!
void playTone(int freq, int durationMs) {
  if (freq <= 0) { delay(durationMs); return; }
  long halfPeriod    = 500000L / freq;       // half-period (µs)
  int  cyclesChunk   = max(1, freq / 100);   // siklus per chunk ~10ms
  int  totalCycles   = (long)freq * durationMs / 1000;
  int  done          = 0;

  while (done < totalCycles) {
    int batch = min(cyclesChunk, totalCycles - done);
    noInterrupts();                          // matikan interrupt ≤10ms
    for (int i = 0; i < batch; i++) {
      digitalWrite(BUZZER_PIN, HIGH);
      delayMicroseconds(halfPeriod);
      digitalWrite(BUZZER_PIN, LOW);
      delayMicroseconds(halfPeriod);
    }
    interrupts();                            // hidupkan lagi → WiFi aman
    done += batch;
  }
  digitalWrite(BUZZER_PIN, LOW);
}

// ✅ Absen BERHASIL → 1x BIP sangat nyaring & cepat
void buzzSuccess() {
  Serial.println(">>> BUZZ SUCCESS DIPANGGIL <<<");
  playTone(2700, 150);
}

// 🟢 SISTEM TERHUBUNG → Chime modern (tinggi-rendah-lebih tinggi)
void buzzConnected() {
  playTone(3200, 100); delay(50);  // ding!  (tinggi, pendek)
  playTone(2500, 100); delay(50);  // dong   (turun → bikin penasaran)
  playTone(2700, 80);  delay(30);  // di-    (grace note cepat)
  playTone(3500, 300);             // -iing! (naik tinggi, panjang, puas ✨)
}

// ⚠️ Kartu BELUM TERDAFTAR → 2x nada sedang
void buzzUnknown() {
  playTone(1800, 120); delay(60);
  playTone(1800, 120);
}

// 🔄 SUDAH ABSEN hari ini → 3x BIP pendek
void buzzAlready() {
  playTone(2700, 70); delay(60);
  playTone(2700, 70); delay(60);
  playTone(2700, 70);
}

// ❌ ERROR → Buzz kasar berulang "BZZZ-BZZZ-BZZZZZZ" (vibes error/ditolak)
void buzzError() {
  playTone(400, 120); delay(60);   // buzz rendah 1
  playTone(400, 120); delay(60);   // buzz rendah 2
  playTone(250, 500);              // BZZZZZZZZ panjang (sangat rendah, harsh, error banget)
}

// ============================================================
// LCD HELPER
// ============================================================

// Tampilkan 2 baris teks
void lcdPrint(const char* baris1, const char* baris2 = "") {
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print(baris1);
  if (strlen(baris2) > 0) {
    lcd.setCursor(0, 1);
    lcd.print(baris2);
  }
}

// Tampilkan "Selamat Datang!" + nama (dengan scroll cepat jika nama panjang)
void lcdWelcome(String nama) {
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("Selamat Datang!");

  if ((int)nama.length() <= LCD_COLS) {
    // Nama pendek → tampil langsung (750ms cepat & gesit)
    lcd.setCursor(0, 1);
    lcd.print(nama);
    delay(750);
  } else {
    // Nama panjang → scroll cepat & mulus (hanya 65ms per geser)
    lcd.setCursor(0, 1);
    lcd.print(nama.substring(0, LCD_COLS));
    delay(250);
    for (int i = 1; i <= (int)nama.length() - LCD_COLS; i++) {
      lcd.setCursor(0, 1);
      lcd.print(nama.substring(i, i + LCD_COLS));
      delay(65); // 65ms: gesit tapi tetap terbaca saat bergeser
    }
    delay(250);
  }
}

// ============================================================
// KONEKSI WIFI - HOTSPOT HP
// ============================================================
void connectWiFi() {
  Serial.println("\n[WiFi] Menghubungkan ke hotspot: " + String(WIFI_SSID));

  // Tampilkan di LCD
  lcd.clear();
  lcd.setCursor(0, 0); lcd.print("Hotspot HP...");
  lcd.setCursor(0, 1); lcd.print(String(WIFI_SSID).substring(0, 16));

  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  int coba = 0;
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
    coba++;

    // Animasi titik di LCD
    if (coba % 6 == 0) {
      lcd.setCursor(0, 0);
      String loading = "Connecting";
      for (int d = 0; d < (coba / 6) % 4; d++) loading += ".";
      lcd.print(loading + "      ");
    }

    // Timeout 20 detik
    if (coba > 40) {
      Serial.println("\n[WiFi] Timeout! Pastikan hotspot HP aktif.");
      lcdPrint("Hotspot Timeout!", "Cek HP Anda...");
      buzzError();
      delay(3000);
      ESP.restart(); // Restart otomatis, coba lagi
    }
  }

  // Berhasil konek
  String ipESP = WiFi.localIP().toString();
  Serial.println("\n[WiFi] Terhubung ke: " + String(WIFI_SSID));
  Serial.println("[WiFi] IP ESP8266  : " + ipESP);
  Serial.println("[WiFi] IP Server   : " + String(SERVER_IP));
  Serial.println("[WiFi] BASE_URL    : " + BASE_URL);

  lcd.clear();
  lcd.setCursor(0, 0); lcd.print("WiFi Terhubung!");
  lcd.setCursor(0, 1); lcd.print(ipESP);
  delay(2500);
}

// ============================================================
// BACA UID KARTU NFC/RFID
// ============================================================
String bacaKartu() {
  uint8_t uid[7];
  uint8_t panjangUID;

  // readPassiveTargetID dengan timeout 150ms (non-blocking)
  if (!nfc.readPassiveTargetID(PN532_MIFARE_ISO14443A, uid, &panjangUID, 150)) {
    return ""; // Tidak ada kartu terdeteksi
  }

  // Konversi UID ke string HEX uppercase
  String uidStr = "";
  for (uint8_t i = 0; i < panjangUID; i++) {
    if (uid[i] < 0x10) uidStr += "0";
    uidStr += String(uid[i], HEX);
  }
  uidStr.toUpperCase();
  return uidStr;
}

// ============================================================
// PROSES TAP KARTU → KIRIM KE SERVER
// ============================================================
void prosesTapKartu(String uid) {

  // Cek koneksi WiFi dulu
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[WiFi] Terputus dari hotspot. Reconnect...");
    lcdPrint("Hotspot Lepas!", "Reconnecting...");
    connectWiFi();
    lcdPrint("Tap Kartu...", "");
    return;
  }

  // Buat URL request ke server
  String url = BASE_URL + "/api/check_uid.php?uid=" + uid;
  Serial.println("[HTTP] GET → " + url);

  // Tampilkan "Memproses..." di LCD
  lcd.clear();
  lcd.setCursor(0, 0); lcd.print("Memproses...");
  lcd.setCursor(0, 1); lcd.print(uid);

  // Kirim HTTP GET
  HTTPClient http;
  http.begin(wifiClient, url);
  http.setTimeout(8000); // Timeout 8 detik

  int httpCode = http.GET();
  Serial.println("[HTTP] Response code: " + String(httpCode));

  // Cek apakah request berhasil
  if (httpCode != HTTP_CODE_OK) {
    Serial.println("[HTTP] Gagal! Error: " + http.errorToString(httpCode));
    lcdPrint("Server Error!", "Cek Laragon/IP");
    http.end();
    buzzError();
    delay(2500);
    lcdPrint("Tap Kartu...", "");
    return;
  }

  // Ambil response body (JSON)
  String payload = http.getString();
  http.end();
  Serial.println("[HTTP] Data: " + payload);

  // Parse JSON
  DynamicJsonDocument doc(512);
  DeserializationError jsonErr = deserializeJson(doc, payload);
  if (jsonErr) {
    Serial.println("[JSON] Parse error: " + String(jsonErr.c_str()));
    lcdPrint("Data Error!", "JSON parse fail");
    buzzError();
    delay(2000);
    lcdPrint("Tap Kartu...", "");
    return;
  }

  // Ambil nilai dari JSON
  String status = doc["status"].as<String>();
  String nama   = doc["name"].as<String>();
  String nim    = doc["nim"].as<String>();

  Serial.println("[STATUS] " + status + " | Nama: " + nama);

  // ─────────────────────────────────────────────
  // CASE 1: ABSEN BERHASIL (kartu terdaftar)
  // ─────────────────────────────────────────────
  if (status == "registered") {
    Serial.println("[OK] Absen berhasil: " + nama + " (" + nim + ")");
    buzzSuccess();
    lcdWelcome(nama); // Tampil "Selamat Datang! [Nama]"
  }

  // ─────────────────────────────────────────────
  // CASE 2: SUDAH ABSEN HARI INI
  // ─────────────────────────────────────────────
  else if (status == "already_attended") {
    Serial.println("[INFO] Sudah absen: " + nama);
    lcd.clear();
    lcd.setCursor(0, 0); lcd.print("Sudah Absen!");
    lcd.setCursor(0, 1); lcd.print(nama.substring(0, LCD_COLS));
    buzzAlready();
    delay(1200);
  }

  // ─────────────────────────────────────────────
  // CASE 3: KARTU BELUM TERDAFTAR
  // ─────────────────────────────────────────────
  else if (status == "unknown") {
    Serial.println("[NEW] Kartu baru ditemukan. UID: " + uid);
    lcd.clear();
    lcd.setCursor(0, 0); lcd.print("Kartu Baru!");
    lcd.setCursor(0, 1); lcd.print("Cek Website");
    buzzUnknown();
    delay(1200);
  }

  // Kembali ke layar standby
  lcdPrint("Tap Kartu...", "");
}

// ============================================================
// HEARTBEAT → Beritahu server bahwa ESP masih online
// ============================================================
void kirimHeartbeat() {
  if (WiFi.status() != WL_CONNECTED) return;

  HTTPClient http;
  http.begin(wifiClient, BASE_URL + "/api/esp_status.php");
  http.addHeader("Content-Type", "application/json");
  int code = http.POST("{}");
  http.end();

  if (code > 0) {
    Serial.println("[Heartbeat] OK (HTTP " + String(code) + ")");
  } else {
    Serial.println("[Heartbeat] Gagal: " + http.errorToString(code));
  }
}

// ============================================================
// SETUP - Dijalankan sekali saat ESP menyala
// ============================================================
void setup() {
  Serial.begin(115200);
  delay(300);

  Serial.println();
  Serial.println("============================================");
  Serial.println("  SISTEM PRESENSI MAHASISWA - ESP8266");
  Serial.println("  Hotspot: " + String(WIFI_SSID));
  Serial.println("  Server : " + String(SERVER_IP));
  Serial.println("============================================");

  // ── Init Buzzer ──
  pinMode(BUZZER_PIN, OUTPUT);
  digitalWrite(BUZZER_PIN, LOW);

  // ── Init LCD via I2C ──
  // SDA = D2 (GPIO4), SCL = D1 (GPIO5)
  Wire.begin(4, 5);
  lcd.init();
  lcd.backlight();
  lcdPrint("Presensi HIMA", "Starting...");
  Serial.println("[LCD] Inisialisasi OK");
  delay(500);

  // ── Init PN532 via SPI ──
  SPI.begin();
  nfc.begin();

  uint32_t firmwareVersion = nfc.getFirmwareVersion();
  if (!firmwareVersion) {
    // PN532 tidak terdeteksi → kemungkinan masalah wiring SPI
    Serial.println("[PN532] ERROR! Tidak terdeteksi.");
    Serial.println("[PN532] Cek kabel: SCK(D5), MISO(D6), MOSI(D7), SS(D8), RSTO(D0)");
    lcdPrint("PN532 ERROR!", "Cek Kabel SPI!");
    buzzError();
    delay(5000);
    ESP.restart(); // Restart dan coba lagi
  }

  // PN532 ditemukan, tampilkan info firmware
  Serial.print("[PN532] Chip: PN5");
  Serial.println((firmwareVersion >> 24) & 0xFF, HEX);
  Serial.print("[PN532] Firmware: v");
  Serial.print((firmwareVersion >> 16) & 0xFF, DEC);
  Serial.print(".");
  Serial.println((firmwareVersion >> 8) & 0xFF, DEC);

  // Konfigurasi PN532 untuk membaca kartu ISO14443A (Mifare, NTAG, dll)
  nfc.SAMConfig();
  Serial.println("[PN532] Siap membaca kartu.");

  // ── Konek ke Hotspot HP ──
  connectWiFi();

  // ── Heartbeat pertama ──
  kirimHeartbeat();

  // ── Tampilan siap ──
  lcdPrint("Siap Presensi!", "Tap Kartu...");
  buzzConnected(); // 2 nada berbeda → tanda sistem siap

  Serial.println("[SIAP] Sistem berjalan. Menunggu tap kartu...");
  Serial.println("--------------------------------------------");
}

// ============================================================
// LOOP - Berjalan terus menerus
// ============================================================
void loop() {
  unsigned long sekarang = millis();

  // ── Kirim heartbeat setiap 20 detik ──
  if (sekarang - lastHeartbeat >= HEARTBEAT_INT) {
    lastHeartbeat = sekarang;
    kirimHeartbeat();
  }

  // ── Cek koneksi WiFi, auto-reconnect jika lepas ──
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[WiFi] Koneksi hotspot terputus!");
    lcdPrint("Hotspot Lepas!", "Reconnecting...");
    connectWiFi();
    lcdPrint("Tap Kartu...", "");
    return;
  }

  // ── Baca kartu NFC/RFID ──
  String uid = bacaKartu();

  // Tidak ada kartu → kembali ke awal loop
  if (uid.length() == 0) {
    yield(); // Beri waktu untuk proses background ESP8266
    return;
  }

  // ── Debounce: hindari baca kartu sama dalam 5 detik ──
  if (uid == lastUID && (sekarang - lastDebounce) < DEBOUNCE_DELAY) {
    return;
  }

  // Update state debounce
  lastUID      = uid;
  lastDebounce = sekarang;

  // ── Log ke Serial Monitor ──
  Serial.println("==========================================");
  Serial.println("[KARTU] UID Terdeteksi: " + uid);
  Serial.println("[DEBUG] millis=" + String(millis()) + " lastDebounce=" + String(lastDebounce));
  Serial.println("==========================================");

  // ── Proses tap kartu ke server ──
  prosesTapKartu(uid);

  // Reset debounce SETELAH proses selesai (agar kartu tidak terbaca ulang)
  lastDebounce = millis();
}
