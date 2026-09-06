/*
 * ============================================================
 * SISTEM PRESENSI MAHASISWA - WIFI MANAGER & FIREBASE CLOUD
 * Hardware : ESP8266 Lolin (NodeMCU v3)
 * NFC      : PN532 (Mode SPI)
 * Display  : LCD 16x2 I2C
 * Buzzer   : Aktif/Pasif kecil (D8 / GPIO15)
 * Server   : Firebase Realtime Database (smartgen-db-26)
 * Fitur    : WiFiManager Captive Portal (Setup Wi-Fi via HP)
 * ============================================================
 *
 * FITUR UTAMA WIFI MANAGER:
 *   1. Jika alat pertama kali dinyalakan atau pindah tempat (Wi-Fi tidak ada):
 *      - ESP8266 otomatis memancarkan Wi-Fi Hotspot: "HIMATIF-Presensi"
 *      - Password Hotspot: "himatif123"
 *      - Layar LCD menampilkan instruksi: "Setup WiFi di HP / HIMATIF-Presensi"
 *      - Panitia tinggal sambungkan HP ke Wi-Fi tersebut, otomatis muncul
 *        halaman web setup (Captive Portal) untuk memilih Wi-Fi dan password.
 *   2. Data Wi-Fi tersimpan permanen di memori internal ESP8266.
 *   3. Reset Wi-Fi Paksa: Tahan tombol "FLASH" (GPIO0) pada board NodeMCU
 *      selama 3 detik saat alat baru dinyalakan.
 *
 * WIRING AMAN (ANTI GAGAL BOOT):
 *   PN532 VCC  → 3.3V (pin 3V3 di NodeMCU - BUKAN 5V!)
 *   PN532 GND  → GND
 *   PN532 SCK  → D5 (GPIO14)
 *   PN532 MISO → D6 (GPIO12)
 *   PN532 MOSI → D7 (GPIO13)
 *   PN532 SS   → D0 (GPIO16)  <-- Agar ESP bisa booting normal
 *   PN532 RSTO → Kosongkan
 *
 *   LCD VCC    → VIN (5V dari adaptor)
 *   LCD GND    → GND
 *   LCD SDA    → D2 (GPIO4)
 *   LCD SCL    → D1 (GPIO5)
 *
 *   Buzzer +   → D8 (GPIO15)
 *   Buzzer -   → GND
 *
 *   Tombol Reset Wi-Fi Manual: Tombol "FLASH" bawaan di board NodeMCU (GPIO0)
 * ============================================================
 */

#include <Arduino.h>
#include <time.h>
#include <ESP8266WiFi.h>
#include <DNSServer.h>
#include <ESP8266WebServer.h>
#include <WiFiManager.h>          // Library: "WiFiManager" oleh tzapu / tablatronix
#include <SPI.h>
#include <Adafruit_PN532.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <Firebase_ESP_Client.h>

// Helper untuk token dan RTDB dari library Firebase Mobizt
#include "addons/TokenHelper.h"
#include "addons/RTDBHelper.h"

// ============================================================
// KONFIGURASI FIREBASE CLOUD
// ============================================================
#define FIREBASE_API_KEY      "AIzaSyDoxT72TbDtKdkhl58gum62f6tZqo6V_vU"
#define FIREBASE_DATABASE_URL "smartgen-db-26-default-rtdb.asia-southeast1.firebasedatabase.app"

// Konfigurasi Nama Hotspot AP untuk Setup WiFi via HP
#define AP_SSID               "HIMATIF-Presensi"
#define AP_PASS               "himatif123"

// ============================================================
// KONFIGURASI PIN
// ============================================================
#define PN532_SS     16   // D0 (GPIO16)
#define BUZZER_PIN   15   // D8 (GPIO15)
#define TRIGGER_PIN  0    // D3 (GPIO0) - Tombol FLASH di board NodeMCU

// LCD I2C
#define LCD_ADDR  0x27
#define LCD_COLS  16
#define LCD_ROWS   2

// ============================================================
// INISIALISASI OBJEK
// ============================================================
Adafruit_PN532    nfc(PN532_SS); 
LiquidCrystal_I2C lcd(LCD_ADDR, LCD_COLS, LCD_ROWS);

FirebaseData   fbdo;
FirebaseAuth   auth;
FirebaseConfig config;

// ============================================================
// VARIABEL GLOBAL
// ============================================================
unsigned long lastDebounce   = 0;
const long    DEBOUNCE_DELAY = 1500;  
String        lastUID        = "";

// ============================================================
// BUZZER - MANUAL PWM (Anti-Interference)
// ============================================================
void playTone(int freq, int durationMs) {
  if (freq <= 0) { delay(durationMs); return; }
  long halfPeriod    = 500000L / freq;       
  int  cyclesChunk   = max(1, freq / 100);   
  int  totalCycles   = (long)freq * durationMs / 1000;
  int  done          = 0;

  while (done < totalCycles) {
    int batch = min(cyclesChunk, totalCycles - done);
    noInterrupts();                          
    for (int i = 0; i < batch; i++) {
      digitalWrite(BUZZER_PIN, HIGH);
      delayMicroseconds(halfPeriod);
      digitalWrite(BUZZER_PIN, LOW);
      delayMicroseconds(halfPeriod);
    }
    interrupts();                            
    done += batch;
  }
  digitalWrite(BUZZER_PIN, LOW);
}

void buzzSuccess()   { playTone(2700, 150); }
void buzzConnected() { playTone(3200, 100); delay(50); playTone(2500, 100); delay(50); playTone(2700, 80); delay(30); playTone(3500, 300); }
void buzzNotice()    { playTone(2500, 100); delay(60); playTone(3100, 140); }
void buzzUnknown()   { playTone(1800, 120); delay(60); playTone(1800, 120); }
void buzzAlready()   { playTone(2700, 70); delay(60); playTone(2700, 70); delay(60); playTone(2700, 70); }
void buzzError()     { playTone(400, 120); delay(60); playTone(400, 120); delay(60); playTone(250, 500); }

// ============================================================
// LCD HELPER
// ============================================================
void lcdPrint(const char* baris1, const char* baris2 = "") {
  lcd.clear();
  lcd.setCursor(0, 0); lcd.print(baris1);
  if (strlen(baris2) > 0) {
    lcd.setCursor(0, 1); lcd.print(baris2);
  }
}

// Callback dipanggil jika ESP masuk mode Access Point (menunggu panitia setting Wi-Fi)
void configModeCallback(WiFiManager *myWiFiManager) {
  Serial.println("\n[WiFiManager] Gagal konek ke Wi-Fi tersimpan.");
  Serial.println("[WiFiManager] Masuk Mode Access Point (AP)!");
  Serial.print("[WiFiManager] Hubungkan HP ke Wi-Fi: ");
  Serial.println(myWiFiManager->getConfigPortalSSID());
  Serial.print("[WiFiManager] IP Portal: ");
  Serial.println(WiFi.softAPIP());

  lcdPrint("Setup WiFi di HP", AP_SSID);
  buzzNotice();
}

// ============================================================
// KONEKSI WIFI MANAGER (Auto-Connect & Captive Portal)
// ============================================================
void setupWiFi() {
  lcdPrint("Cari Wi-Fi...", "Menghubungkan...");
  Serial.println("\n[WiFiManager] Mencoba menyambung ke Wi-Fi...");

  WiFiManager wm;

  // Callback saat masuk mode AP
  wm.setAPCallback(configModeCallback);

  // Timeout 180 detik (3 menit) jika portal tidak diisi, agar reboot otomatis
  wm.setConfigPortalTimeout(180);

  // Tampilan judul pada Captive Portal HP
  wm.setTitle("HIMATIF UMS - Setup Presensi");

  // Menu portal yang ringkas
  std::vector<const char *> menu = {"wifi", "info", "sep", "restart"};
  wm.setMenu(menu);

  // autoConnect():
  // - Jika pernah konek, langsung konek dalam 2-3 detik.
  // - Jika belum / tidak menemukan sinyal, buka hotspot AP_SSID ("HIMATIF-Presensi")
  bool res = wm.autoConnect(AP_SSID, AP_PASS);

  if (!res) {
    Serial.println("[WiFiManager] Timeout atau Gagal Konek. Restarting...");
    lcdPrint("WiFi Gagal!", "Restarting...");
    buzzError(); 
    delay(3000); 
    ESP.restart(); 
  }

  String ipESP = WiFi.localIP().toString();
  Serial.println("\n[WiFi] Terhubung! IP: " + ipESP);
  lcdPrint("WiFi Terhubung!", ipESP.c_str());

  // Sinkronisasi Waktu NTP (WIB: UTC+7 = 7 * 3600)
  configTime(7 * 3600, 0, "pool.ntp.org", "time.google.com");
  Serial.println("[NTP] Memulai sinkronisasi waktu Internet (WIB)...");

  delay(1500);
}

// ============================================================
// BACA UID KARTU PN532
// ============================================================
String bacaKartu() {
  uint8_t uid[7];
  uint8_t panjangUID;

  if (!nfc.readPassiveTargetID(PN532_MIFARE_ISO14443A, uid, &panjangUID, 150)) {
    return ""; 
  }

  String uidStr = "";
  for (uint8_t i = 0; i < panjangUID; i++) {
    if (uid[i] < 0x10) uidStr += "0";
    uidStr += String(uid[i], HEX);
  }
  uidStr.toUpperCase();
  return uidStr;
}

// ============================================================
// HANDLE KARTU BELUM TERDAFTAR
// ============================================================
void handleUnknownCard(String uid) {
  Serial.println("[NEW] Kartu baru terdeteksi. UID: " + uid);
  lcdPrint("Kartu Baru!", "Menyimpan UID...");
  buzzUnknown();

  // Otomatis kirim UID tidak dikenal ke Firebase
  Firebase.RTDB.setString(&fbdo, "/unknown_cards/" + uid, "Belum Terdaftar");

  delay(1500);
  lcdPrint("Tap Kartu...", "");
}

// ============================================================
// PROSES TAP KARTU LANGSUNG KE FIREBASE
// ============================================================
void prosesTapKartu(String uid) {
  if (WiFi.status() != WL_CONNECTED) {
    lcdPrint("WiFi Lepas!", "Reconnecting...");
    setupWiFi();
    lcdPrint("Tap Kartu...", "");
    return;
  }

  Serial.println("[Firebase] Mengecek UID di Cloud: " + uid);
  lcdPrint("Memproses...", uid.c_str());

  if (!Firebase.ready()) {
    lcdPrint("Firebase Belum", "Siap / Ready");
    buzzError();
    delay(2000);
    lcdPrint("Tap Kartu...", "");
    return;
  }

  // Cek langsung field nama di /users/{uid}/name
  String pathName = "/users/" + uid + "/name";
  if (Firebase.RTDB.getString(&fbdo, pathName) && fbdo.dataType() == "string" && fbdo.stringData().length() > 0 && fbdo.stringData() != "null") {
    String nama = fbdo.stringData();
    
    // Ambil waktu dari NTP
    time_t now = time(nullptr);
    struct tm* ptm = localtime(&now);

    char waktuBuf[16] = "00.00";
    char dateBuf[16] = "today";
    bool ntpReady = (ptm && ptm->tm_year > 100);

    if (ntpReady) {
      snprintf(waktuBuf, sizeof(waktuBuf), "%02d.%02d", ptm->tm_hour, ptm->tm_min);
      snprintf(dateBuf, sizeof(dateBuf), "%04d-%02d-%02d", ptm->tm_year + 1900, ptm->tm_mon + 1, ptm->tm_mday);
    }

    // Ambil info sesi aktif jika ada di /active_session (default: sesi_1)
    String sessionId = "sesi_1";
    String sessionName = "Sesi 1 (Datang)";
    if (Firebase.RTDB.getString(&fbdo, "/active_session/id") && fbdo.dataType() == "string" && fbdo.stringData().length() > 0 && fbdo.stringData() != "null") {
      sessionId = fbdo.stringData();
    }
    if (Firebase.RTDB.getString(&fbdo, "/active_session/name") && fbdo.dataType() == "string" && fbdo.stringData().length() > 0 && fbdo.stringData() != "null") {
      sessionName = fbdo.stringData();
    }

    // Cek apakah mahasiswa sudah tap pada SESI aktif hari ini
    String pathToday = "/attendance_today/" + String(dateBuf) + "_" + sessionId + "/" + uid;
    if (Firebase.RTDB.getBool(&fbdo, pathToday) && fbdo.dataType() == "boolean" && fbdo.boolData() == true) {
      Serial.println("[INFO] Kartu sudah absen di sesi " + sessionName + ": " + nama);
      buzzAlready();
      lcdPrint("Sudah Absen!", sessionName.substring(0, 16).c_str());
      delay(1500);
      lcdPrint("Tap Kartu...", "");
      return;
    }

    // Tandai sudah tap pada sesi ini di cloud
    Firebase.RTDB.setBool(&fbdo, pathToday, true);

    // Ambil info nama acara aktif jika tersedia di /active_event/name
    String eventName = "";
    if (Firebase.RTDB.getString(&fbdo, "/active_event/name") && fbdo.dataType() == "string") {
      eventName = fbdo.stringData();
    }

    Serial.println("[OK] Absen Berhasil (" + sessionName + "): " + nama);
    buzzSuccess();
    lcdPrint(sessionName.substring(0, 16).c_str(), nama.substring(0, 16).c_str());
    delay(1200);

    // Kirim log absen ke Firebase (/log_presensi)
    FirebaseJson logData;
    logData.set("uid", uid);
    logData.set("name", nama);
    logData.set("session_id", sessionId);
    logData.set("session_name", sessionName);

    if (eventName.length() > 0 && eventName != "null") {
      logData.set("event_name", eventName);
    }

    if (ntpReady) {
      logData.set("waktu", waktuBuf);
      logData.set("date", dateBuf);
      logData.set("timestamp", (double)now * 1000);
    } else {
      logData.set("timestamp/.sv", "timestamp");
    }

    Firebase.RTDB.pushJSON(&fbdo, "/log_presensi", &logData);

  } else {
    handleUnknownCard(uid);
  }

  lcdPrint("Tap Kartu...", "");
}

// ============================================================
// SETUP
// ============================================================
void setup() {
  Serial.begin(115200);

  // Matikan buzzer secara paksa saat boot
  pinMode(BUZZER_PIN, OUTPUT);
  digitalWrite(BUZZER_PIN, LOW); 

  // Inisialisasi LCD
  Wire.begin(4, 5); // SDA = D2, SCL = D1
  lcd.init();
  lcd.backlight();
  lcdPrint("Presensi HIMA", "Starting...");

  // Inisialisasi Pin Tombol FLASH NodeMCU (GPIO0)
  pinMode(TRIGGER_PIN, INPUT_PULLUP);

  // Deteksi jika tombol FLASH ditekan saat alat baru menyala -> Paksa Reset Wi-Fi
  if (digitalRead(TRIGGER_PIN) == LOW) {
    lcdPrint("Reset Wi-Fi?", "Tahan 3 Detik...");
    delay(3000);
    if (digitalRead(TRIGGER_PIN) == LOW) {
      lcdPrint("Wi-Fi Direset!", "Masuk Setup HP..");
      buzzNotice();
      WiFiManager wm;
      wm.resetSettings(); // Menghapus credential lama dari flash
      delay(1500);
    }
  }

  // Inisialisasi RFID PN532
  SPI.begin();
  nfc.begin();

  uint32_t firmwareVersion = nfc.getFirmwareVersion();
  if (!firmwareVersion) {
    lcdPrint("PN532 ERROR!", "Cek Kabel SPI!");
    buzzError(); 
    delay(5000); 
    ESP.restart(); 
  }

  nfc.SAMConfig();
  
  // Koneksi Wi-Fi via WiFiManager (Auto-connect atau Captive Portal)
  setupWiFi();

  // Inisialisasi Firebase
  config.api_key = FIREBASE_API_KEY;
  config.database_url = FIREBASE_DATABASE_URL;

  if (Firebase.signUp(&config, &auth, "", "")) {
    Serial.println("[Firebase] Koneksi Sukses");
  } else {
    Serial.print("[Firebase] Error: ");
    Serial.println(config.signer.signupError.message.c_str());
  }

  config.token_status_callback = tokenStatusCallback;
  Firebase.begin(&config, &auth);
  Firebase.reconnectWiFi(true);

  lcdPrint("Siap Presensi!", "Tap Kartu...");
  buzzConnected(); 
}

// ============================================================
// LOOP
// ============================================================
void loop() {
  digitalWrite(BUZZER_PIN, LOW); 

  unsigned long sekarang = millis();

  // Pastikan koneksi Wi-Fi tetap aktif
  if (WiFi.status() != WL_CONNECTED) {
    lcdPrint("WiFi Lepas!", "Reconnecting...");
    setupWiFi();
    lcdPrint("Tap Kartu...", "");
    return;
  }

  String uid = bacaKartu();
  if (uid.length() == 0) {
    yield(); 
    return;
  }

  // Debounce kartu yang sama
  if (uid == lastUID && (sekarang - lastDebounce) < DEBOUNCE_DELAY) {
    return;
  }

  lastUID      = uid;
  lastDebounce = sekarang;

  Serial.println("[KARTU] UID Terdeteksi: " + uid);
  prosesTapKartu(uid);
  
  lastDebounce = millis();
}
