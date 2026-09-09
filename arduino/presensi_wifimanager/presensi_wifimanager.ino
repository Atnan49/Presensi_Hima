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
 *   PN532 VCC  -> 3.3V (pin 3V3 di NodeMCU - BUKAN 5V!)
 *   PN532 GND  -> GND
 *   PN532 SCK  -> D5 (GPIO14)
 *   PN532 MISO -> D6 (GPIO12)
 *   PN532 MOSI -> D7 (GPIO13)
 *   PN532 SS   -> D0 (GPIO16)  <-- Agar ESP bisa booting normal
 *   PN532 RSTO -> Kosongkan
 *
 *   LCD VCC    -> VIN (5V dari adaptor)
 *   LCD GND    -> GND
 *   LCD SDA    -> D2 (GPIO4)
 *   LCD SCL    -> D1 (GPIO5)
 *
 *   Buzzer +   -> D8 (GPIO15)
 *   Buzzer -   -> GND
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
const long    DEBOUNCE_DELAY = 800;  // OPTIMASI 5: Debounce lebih cepat (sebelumnya 1500)
String        lastUID        = "";

// OPTIMASI 1 & 4: CACHE & ASYNC LOG
String cachedSessionId = "sesi_1";
String cachedSessionName = "Sesi 1 (Datang)";
String cachedEventName = "";
unsigned long lastCacheUpdate = 0;
const long CACHE_INTERVAL = 30000; // 30 detik

bool hasPendingLog = false;
FirebaseJson pendingLogData;

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
    yield(); // Mencegah reset Software Watchdog Timer ESP8266
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
// LCD HELPER (Proteksi Batas 16 Karakter Aman)
// ============================================================
void lcdPrint(const char* baris1, const char* baris2 = "") {
  lcd.clear();
  lcd.setCursor(0, 0);
  char buf1[17];
  strncpy(buf1, baris1 ? baris1 : "", 16);
  buf1[16] = '\0';
  lcd.print(buf1);

  if (baris2 && strlen(baris2) > 0) {
    lcd.setCursor(0, 1);
    char buf2[17];
    strncpy(buf2, baris2, 16);
    buf2[16] = '\0';
    lcd.print(buf2);
  }
}

// Helper teks rata tengah (Center text) untuk layar 16 kolom
String centerText(String text, int width = 16) {
  if ((int)text.length() >= width) return text.substring(0, width);
  int pad = (width - (int)text.length()) / 2;
  String out = "";
  for (int i = 0; i < pad; i++) out += " ";
  out += text;
  while ((int)out.length() < width) out += " ";
  return out;
}

// Format nama mahasiswa agar rapi di layar LCD 16 kolom (tanpa terpotong jelek)
String formatNamaLCD(String fullName) {
  fullName.trim();
  if (fullName.length() <= 16) return fullName;

  // Singkat awalan nama panjang umum jika diperlukan
  String lower = fullName;
  lower.toLowerCase();
  if (lower.startsWith("muhammad ")) {
    fullName = "M. " + fullName.substring(9);
  } else if (lower.startsWith("muh. ")) {
    fullName = "M. " + fullName.substring(5);
  } else if (lower.startsWith("mochamad ")) {
    fullName = "M. " + fullName.substring(9);
  }

  if (fullName.length() <= 16) return fullName;

  // Potong pada batas spasi kata terakhir yang muat agar kata tidak terpenggal di tengah
  int lastSpace = fullName.substring(0, 17).lastIndexOf(' ');
  if (lastSpace > 6) {
    return fullName.substring(0, lastSpace);
  }

  return fullName.substring(0, 16);
}

// Format nama sesi ramah LCD 16 kolom (tanpa terpotong jelek)
String formatSesiLCD(String id, String rawName) {
  if (id == "sesi_1") return "Sesi 1 (Datang)";
  if (id == "sesi_2") return "Sesi 2 (Ishoma)";
  if (id == "sesi_3") return "Sesi 3 (Pulang)";
  if (rawName.length() > 16) return rawName.substring(0, 16);
  return rawName;
}

// Tampilan layar siap / standby
void tampilkanStandby() {
  lcdPrint("PRESENSI HIMATIF", ">> SILAKAN TAP<<");
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
// REFRESH CACHE (OPTIMASI 1)
// ============================================================
void refreshCache() {
  if (WiFi.status() == WL_CONNECTED && Firebase.ready()) {
    if (Firebase.RTDB.getString(&fbdo, "/active_session/id")) {
      String s = fbdo.stringData();
      if (s.length() > 0 && s != "null") cachedSessionId = s;
    }
    if (Firebase.RTDB.getString(&fbdo, "/active_session/name")) {
      String s = fbdo.stringData();
      if (s.length() > 0 && s != "null") cachedSessionName = s;
    }
    if (Firebase.RTDB.getString(&fbdo, "/active_event/name")) {
      String s = fbdo.stringData();
      if (s.length() > 0 && s != "null") cachedEventName = s;
    }
    lastCacheUpdate = millis();
    Serial.println("[CACHE] Diperbarui: " + cachedSessionName + " | Event: " + cachedEventName);
  }
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
  buzzUnknown();
  lcdPrint("  KARTU BARU!   ", centerText("UID: " + uid).c_str());

  String path = "/unknown_cards/" + uid;
  int currentTap = 0;
  if (Firebase.RTDB.getInt(&fbdo, path + "/tap_count")) {
    currentTap = fbdo.intData();
  }

  time_t now = time(nullptr);
  struct tm* ptm = localtime(&now);
  bool ntpReady = (ptm && ptm->tm_year > 100);

  FirebaseJson json;
  json.set("tap_count", currentTap + 1);

  if (ntpReady) {
    char timeStr[32];
    snprintf(timeStr, sizeof(timeStr), "%04d-%02d-%02dT%02d:%02d:%02d",
             ptm->tm_year + 1900, ptm->tm_mon + 1, ptm->tm_mday,
             ptm->tm_hour, ptm->tm_min, ptm->tm_sec);
    if (currentTap == 0) {
      json.set("first_seen", timeStr);
    }
    json.set("last_seen", timeStr);
    json.set("timestamp", (double)now * 1000);
  } else {
    json.set("last_seen/.sv", "timestamp");
    if (currentTap == 0) {
      json.set("first_seen/.sv", "timestamp");
    }
  }

  // Gunakan updateNode agar data first_seen tidak tertimpa saat tap berulang
  Firebase.RTDB.updateNode(&fbdo, path, &json);

  delay(600); // OPTIMASI 3: Delay LCD dikurangi
  lcdPrint("BELUM TERDAFTAR ", "Daftar ke Admin ");
  delay(600); // OPTIMASI 3: Delay LCD dikurangi
  tampilkanStandby();
}

// ============================================================
// PROSES TAP KARTU LANGSUNG KE FIREBASE
// ============================================================
void prosesTapKartu(String uid) {
  if (WiFi.status() != WL_CONNECTED) {
    lcdPrint("  WIFI LEPAS!   ", "Reconnecting...");
    setupWiFi();
    tampilkanStandby();
    return;
  }

  Serial.println("[Firebase] Mengecek UID di Cloud: " + uid);
  lcdPrint("  MEMBACA DATA  ", centerText("UID: " + uid).c_str());

  if (!Firebase.ready()) {
    lcdPrint("  SERVER CLOUD  ", " Belum Siap...  ");
    buzzError();
    delay(1800);
    tampilkanStandby();
    return;
  }

  // Cek JSON data user sekaligus (OPTIMASI 2)
  String pathUser = "/users/" + uid;
  if (Firebase.RTDB.getJSON(&fbdo, pathUser)) {
    FirebaseJson& jsonUser = fbdo.jsonObject();
    FirebaseJsonData jsonData;
    
    jsonUser.get(jsonData, "name");
    String nama = jsonData.stringValue;
    if (nama.length() == 0 || nama == "null") {
       handleUnknownCard(uid);
       return;
    }
    
    String nim = "";
    if (jsonUser.get(jsonData, "nim")) {
      nim = jsonData.stringValue;
      if (nim == "null") nim = "";
    }
    
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

    // Format nama mahasiswa untuk tampilan LCD
    String namaDisplay = formatNamaLCD(nama);

    // Gunakan cache untuk sesi dan acara (OPTIMASI 1)
    String sessionId = cachedSessionId;
    String sessionName = cachedSessionName;
    String eventName = cachedEventName;

    // Cek apakah mahasiswa sudah tap pada SESI aktif hari ini
    String pathToday = "/attendance_today/" + String(dateBuf) + "_" + sessionId + "/" + uid;
    if (Firebase.RTDB.getBool(&fbdo, pathToday) && fbdo.dataType() == "boolean" && fbdo.boolData() == true) {
      Serial.println("[INFO] Kartu sudah absen di sesi " + sessionName + ": " + nama);
      buzzAlready();
      
      // Layar 1: Nama Mahasiswa di Baris 1 + Status Peringatan di Baris 2
      lcdPrint(centerText(namaDisplay).c_str(), " ! SUDAH ABSEN !");
      delay(700); // OPTIMASI 3: Delay LCD dikurangi

      // Layar 2: Info Sesi
      String sesiFormat = formatSesiLCD(sessionId, sessionName);
      lcdPrint(centerText(sesiFormat).c_str(), "Data Tersimpan :)");
      delay(500); // OPTIMASI 3: Delay LCD dikurangi

      tampilkanStandby();
      return;
    }

    // Tandai sudah tap pada sesi ini di cloud
    Firebase.RTDB.setBool(&fbdo, pathToday, true);

    Serial.println("[OK] Absen Berhasil (" + sessionName + "): " + nama);
    buzzSuccess();

    // Layar 1: Nama Mahasiswa di Baris 1 + Status Hadir & Waktu di Baris 2
    String statusHadir = ntpReady ? "HADIR - " + String(waktuBuf) : "ABSEN BERHASIL!";
    lcdPrint(centerText(namaDisplay).c_str(), centerText(statusHadir).c_str());
    delay(800); // OPTIMASI 3: Delay LCD dikurangi

    // Layar 2: Info Sesi & NIM / Salam
    String sesiFormat = formatSesiLCD(sessionId, sessionName);
    String barisDua   = (nim.length() > 0) ? "NIM: " + nim : " TERIMA KASIH!  ";
    lcdPrint(centerText(sesiFormat).c_str(), centerText(barisDua).c_str());
    delay(600); // OPTIMASI 3: Delay LCD dikurangi

    // Siapkan log absen untuk dikirim asinkron (OPTIMASI 4)
    pendingLogData.clear();
    pendingLogData.set("uid", uid);
    pendingLogData.set("name", nama);
    if (nim.length() > 0) {
      pendingLogData.set("nim", nim);
    }
    pendingLogData.set("session_id", sessionId);
    pendingLogData.set("session_name", sessionName);

    if (eventName.length() > 0 && eventName != "null") {
      pendingLogData.set("event_name", eventName);
    }

    if (ntpReady) {
      pendingLogData.set("waktu", waktuBuf);
      pendingLogData.set("date", dateBuf);
      pendingLogData.set("timestamp", (double)now * 1000);
    } else {
      pendingLogData.set("timestamp/.sv", "timestamp");
    }
    
    hasPendingLog = true;

  } else {
    handleUnknownCard(uid);
  }

  tampilkanStandby();
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

  // OPTIMASI 1: Ambil data cache pertama kali
  refreshCache();

  tampilkanStandby();
  buzzConnected(); 
}

// ============================================================
// LOOP
// ============================================================
void loop() {
  digitalWrite(BUZZER_PIN, LOW); 

  unsigned long sekarang = millis();

  // Refresh cache berkala tiap CACHE_INTERVAL (OPTIMASI 1)
  if (sekarang - lastCacheUpdate > CACHE_INTERVAL) {
    refreshCache();
  }

  // Proses pending log secara asinkron (OPTIMASI 4)
  if (hasPendingLog) {
    if (WiFi.status() == WL_CONNECTED && Firebase.ready()) {
      Firebase.RTDB.pushJSON(&fbdo, "/log_presensi", &pendingLogData);
      hasPendingLog = false;
      Serial.println("[LOG] Log presensi berhasil dikirim (Async)");
    }
  }

  // Pastikan koneksi Wi-Fi tetap aktif
  if (WiFi.status() != WL_CONNECTED) {
    lcdPrint("  WIFI LEPAS!   ", "Reconnecting...");
    setupWiFi();
    tampilkanStandby();
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
