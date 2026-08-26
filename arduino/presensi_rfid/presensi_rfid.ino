// ============================================================
//  SISTEM PRESENSI MAHASISWA RFID
//  Hardware : ESP8266 (NodeMCU) + MFRC522 + Buzzer
//  Optional : LCD I2C 16x2 (aktifkan #define USE_LCD di bawah)
//
//  PIN RFID   : SS=D8, RST=D0
//  PIN LED    : Hijau=D1, Kuning=D2, Merah=D3
//  PIN BUZZER : D4
//  PIN LCD    : SDA=D6 (GPIO12), SCL=D5 (GPIO14) — I2C
//
//  Untuk mengaktifkan LCD: hapus komentar "//" di bawah ini:
//  #define USE_LCD
// ============================================================

// ──────────────────────────────────────────────
//  [OPSIONAL] Aktifkan LCD I2C — hapus "//" untuk pakai LCD
// ──────────────────────────────────────────────
// #define USE_LCD

// ──────────────────────────────────────────────
//  LIBRARY WAJIB
// ──────────────────────────────────────────────
#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <ArduinoJson.h>
#include <SPI.h>
#include <MFRC522.h>

// ──────────────────────────────────────────────
//  LIBRARY LCD (hanya dikompil jika USE_LCD aktif)
// ──────────────────────────────────────────────
#ifdef USE_LCD
  #include <Wire.h>
  #include <LiquidCrystal_I2C.h>
  // Alamat I2C LCD biasanya 0x27 atau 0x3F
  // Cek dengan I2C Scanner jika tidak yakin
  LiquidCrystal_I2C lcd(0x27, 16, 2);
#endif

// ==============================
// WIFI
// ==============================
const char* ssid     = "Aji";   // ganti SSID Anda
const char* password = "gula manis";               // ganti password Anda

// ==============================
// SERVER  (ganti IP sesuai MAMP)
// ==============================
const char* serverTap    = "http://172.20.10.2/PRESENSI/php/tap.php";
const char* serverSaveUID = "http://172.20.10.2/PRESENSI/php/save_uid.php";
const char* serverStatus  = "http://172.20.10.2/PRESENSI/php/status.php";

// ==============================
// PIN RFID
// ==============================
#define SS_PIN  D8
#define RST_PIN D0

// ==============================
// PIN LED & BUZZER
// ==============================
#define LED_HIJAU  D1   // Hadir berhasil dicatat
#define LED_KUNING D2   // Kartu dikenali tapi sudah tap / tidak ada acara
#define LED_MERAH  D3   // Kartu tidak terdaftar
#define BUZZER_PIN D4

// ==============================
// OBJEK
// ==============================
MFRC522 mfrc522(SS_PIN, RST_PIN);
WiFiClient client;

// ==============================
// VARIABEL
// ==============================
String lastUID   = "";
unsigned long lastRead = 0;

// ============================================================
// HELPER LCD — wrapper aman, tidak error bila LCD tidak ada
// ============================================================
void lcdClear() {
#ifdef USE_LCD
  lcd.clear();
#endif
}

void lcdPrint(uint8_t col, uint8_t row, String msg) {
#ifdef USE_LCD
  lcd.setCursor(col, row);
  lcd.print(msg);
#endif
}

// Tampilkan 2 baris sekaligus (baris 1 & 2)
void lcdShow(String baris1, String baris2 = "") {
#ifdef USE_LCD
  lcd.clear();
  lcd.setCursor(0, 0);
  // Potong jika melebihi 16 karakter
  lcd.print(baris1.substring(0, 16));
  lcd.setCursor(0, 1);
  lcd.print(baris2.substring(0, 16));
#endif
}

// ============================================================
// SETUP
// ============================================================
void setup() {
  Serial.begin(115200);

  pinMode(LED_HIJAU,  OUTPUT);
  pinMode(LED_KUNING, OUTPUT);
  pinMode(LED_MERAH,  OUTPUT);
  pinMode(BUZZER_PIN, OUTPUT);

  ledsOff();

  // ── Init LCD ──────────────────────────────────────────────
#ifdef USE_LCD
  Wire.begin(12, 14);   // SDA=D6(GPIO12), SCL=D5(GPIO14)
  lcd.init();
  lcd.backlight();
  lcdShow("Sistem Presensi", "Inisialisasi...");
  Serial.println("[LCD] Aktif");
#endif

  SPI.begin();
  mfrc522.PCD_Init();

  Serial.println();
  Serial.println("============================================");
  Serial.println("   SISTEM PRESENSI MAHASISWA RFID          ");
  Serial.println("============================================");

  // ── Koneksi WiFi ──────────────────────────────────────────
  Serial.print("Connecting WiFi");
  lcdShow("Connecting WiFi", ssid);
  WiFi.begin(ssid, password);

  int retry = 0;
  while (WiFi.status() != WL_CONNECTED && retry < 40) {
    Serial.print(".");
    delay(500);
    retry++;
  }
  Serial.println();

  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[ERROR] Gagal konek WiFi!");
    lcdShow("WiFi GAGAL!", "Cek SSID/Pass");
    blinkLed(LED_MERAH, 6, 200);
    return;
  }

  Serial.println("[OK] WiFi Terhubung!");
  Serial.print("IP: ");
  Serial.println(WiFi.localIP());
  Serial.println("Tempelkan kartu mahasiswa...");

  String ipStr = WiFi.localIP().toString();
  lcdShow("WiFi OK!", ipStr);
  delay(1500);
  lcdShow("Tempelkan Kartu", "pada RFID reader");

  // Indikasi siap
  beep(1, 80);
  blinkLed(LED_HIJAU, 3, 150);
  kirimStatus("Standby. Menunggu Kartu...");
}

// ============================================================
// LOOP
// ============================================================
void loop() {
  String uid = bacaUID();

  if (uid == "") return;

  // Cegah baca berulang dalam 5 detik
  if (uid == lastUID && millis() - lastRead < 5000) return;

  lastUID  = uid;
  lastRead = millis();

  Serial.println();
  Serial.println("====================================");
  Serial.print("[RFID] UID: ");
  Serial.println(uid);

  lcdShow("Memproses...", uid);

  kirimServer(uid);

  delay(200);
}

// ============================================================
// BACA UID KARTU
// ============================================================
String bacaUID() {
  if (!mfrc522.PICC_IsNewCardPresent()) return "";
  if (!mfrc522.PICC_ReadCardSerial())   return "";

  String uid = "";
  for (byte i = 0; i < mfrc522.uid.size; i++) {
    if (mfrc522.uid.uidByte[i] < 0x10) uid += "0";
    uid += String(mfrc522.uid.uidByte[i], HEX);
  }
  uid.toUpperCase();
  mfrc522.PICC_HaltA();
  return uid;
}

// ============================================================
// KIRIM KE SERVER
// ============================================================
void kirimServer(String uid) {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[WiFi] Terputus, skip");
    lcdShow("WiFi Terputus!", "Cek koneksi...");
    blinkLed(LED_MERAH, 3, 100);
    return;
  }

  // ── 1. Simpan UID terakhir ─────────────────────────────
  {
    HTTPClient save;
    save.begin(client, serverSaveUID);
    save.addHeader("Content-Type", "application/x-www-form-urlencoded");
    save.POST("uid=" + uid);
    save.end();
  }

  // ── 2. Kirim ke tap.php ────────────────────────────────
  HTTPClient http;
  http.begin(client, serverTap);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");

  int httpCode = http.POST("uid=" + uid);

  if (httpCode <= 0) {
    Serial.print("[HTTP] Error: ");
    Serial.println(httpCode);
    lcdShow("Server Error!", "Kode: " + String(httpCode));
    blinkLed(LED_MERAH, 2, 200);
    http.end();
    return;
  }

  String payload = http.getString();
  http.end();

  Serial.println("[Server] Response:");
  Serial.println(payload);

  // ── 3. Parse JSON ──────────────────────────────────────
  DynamicJsonDocument doc(512);
  DeserializationError err = deserializeJson(doc, payload);

  if (err) {
    Serial.println("[JSON] Parse gagal");
    lcdShow("Parse Error!", "Resp. tidak valid");
    blinkLed(LED_MERAH, 2, 150);
    return;
  }

  String status = doc["status"].as<String>();

  // ── 4. Handle status ───────────────────────────────────

  if (status == "berhasil") {
    String nama       = doc["nama"].as<String>();
    String nim        = doc["nim"].as<String>();
    String namaAcara  = doc["nama_acara"].as<String>();
    String stHadir    = doc["status_hadir"].as<String>();

    Serial.println("===== PRESENSI BERHASIL =====");
    Serial.print("Nama    : "); Serial.println(nama);
    Serial.print("NIM     : "); Serial.println(nim);
    Serial.print("Acara   : "); Serial.println(namaAcara);
    Serial.print("Status  : "); Serial.println(stHadir);

    if (stHadir == "hadir") {
      // Hijau panjang = hadir tepat waktu
      lcdShow("HADIR! :)", nama.substring(0, 16));
      beep(1, 100);
      ledOn(LED_HIJAU);
      delay(2000);
      ledOff(LED_HIJAU);
    } else {
      // Kuning = terlambat
      lcdShow("TERLAMBAT!", nama.substring(0, 16));
      beep(2, 100);
      ledOn(LED_KUNING);
      delay(2000);
      ledOff(LED_KUNING);
    }

    lcdShow("Tempelkan Kartu", "pada RFID reader");
    kirimStatus("Hadir: " + nama);

  } else if (status == "sudah_presensi") {
    String nama = doc["nama"].as<String>();
    Serial.println("===== SUDAH PRESENSI =====");
    Serial.print("Nama : "); Serial.println(nama);

    lcdShow("Sudah Presensi!", nama.substring(0, 16));
    beep(2, 80);
    blinkLed(LED_KUNING, 4, 200);
    lcdShow("Tempelkan Kartu", "pada RFID reader");
    kirimStatus("Sudah Presensi: " + nama);

  } else if (status == "tidak_ada_acara") {
    String nama = doc["nama"].as<String>();
    Serial.println("===== TIDAK ADA ACARA AKTIF =====");
    Serial.print("Nama : "); Serial.println(nama);

    lcdShow("Tdk Ada Acara!", nama.substring(0, 16));
    beep(2, 150);
    blinkLed(LED_KUNING, 3, 300);
    lcdShow("Tempelkan Kartu", "pada RFID reader");
    kirimStatus("Tidak Ada Acara Aktif!");

  } else if (status == "tidak_terdaftar") {
    Serial.println("===== KARTU TIDAK TERDAFTAR =====");

    lcdShow("Tdk Terdaftar!", "Hubungi Admin");
    beep(3, 100);
    blinkLed(LED_MERAH, 5, 150);
    lcdShow("Tempelkan Kartu", "pada RFID reader");
    kirimStatus("Kartu Tidak Terdaftar!");

  } else {
    Serial.print("[Status] Tidak dikenal: ");
    Serial.println(status);
    lcdShow("Status unknown:", status.substring(0, 16));
    blinkLed(LED_MERAH, 2, 100);
  }

  // Re-init RFID agar bisa baca kartu lagi
  mfrc522.PCD_Init();
  Serial.println("[RFID] Siap membaca kartu berikutnya...");
}

// ============================================================
// HELPER FUNCTIONS
// ============================================================

void ledsOff() {
  digitalWrite(LED_HIJAU,  LOW);
  digitalWrite(LED_KUNING, LOW);
  digitalWrite(LED_MERAH,  LOW);
}

void ledOn(int pin) {
  ledsOff();
  digitalWrite(pin, HIGH);
}

void ledOff(int pin) {
  digitalWrite(pin, LOW);
}

void blinkLed(int pin, int times, int ms) {
  ledsOff();
  for (int i = 0; i < times; i++) {
    digitalWrite(pin, HIGH);
    delay(ms);
    digitalWrite(pin, LOW);
    delay(ms);
  }
}

void beep(int times, int ms) {
  for (int i = 0; i < times; i++) {
    digitalWrite(BUZZER_PIN, HIGH);
    delay(ms);
    digitalWrite(BUZZER_PIN, LOW);
    delay(ms);
  }
}

void kirimStatus(String msg) {
  if (WiFi.status() != WL_CONNECTED) return;
  msg.replace(" ", "%20");
  HTTPClient http;
  http.begin(client, String(serverStatus) + "?msg=" + msg);
  http.GET();
  http.end();
}
