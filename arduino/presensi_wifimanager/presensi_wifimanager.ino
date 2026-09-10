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

// OPTIMASI 1 & 4: CACHE & ASYNC LOG
String cachedSessionId = "sesi_1";
String cachedSessionName = "Sesi 1";
String cachedEventName = "";
String cachedEventId = "";
String cachedTargetAudience = "committee_only";
String cachedSessionStatus = "aktif"; // "aktif" atau "nonaktif"
unsigned long lastCacheUpdate = 0;
const long CACHE_INTERVAL = 4000; // 4 detik (sinkronisasi standby cepat)

// HEARTBEAT TELEMETRY
unsigned long lastHeartbeat = 0;
const long HEARTBEAT_INTERVAL = 20000; // 20 detik

bool hasPendingLog = false;
FirebaseJson pendingLogData;

// STATE MACHINE LCD (NON-BLOCKING & ANTI-FLICKER)
unsigned long lcdResetTime = 0;
bool isLcdStandby = true;
int lcdPhase = 0; // 0=Standby, 1=Screen1, 2=Screen2
String pendingLcdLine1 = "";
String pendingLcdLine2 = "";
unsigned long lastStandbyTick = 0;
int standbySubPhase = 0;

// ============================================================
// KARAKTER KUSTOM LCD (CGRAM 5x8)
// ============================================================
byte iconCheck[8] = {
  B00000, B00001, B00011, B10110,
  B11100, B01000, B00000, B00000
};
byte iconCard[8] = {
  B11111, B10001, B11111, B10101,
  B10001, B11111, B00000, B00000
};
byte iconWifi[8] = {
  B00000, B01110, B10001, B00100,
  B01010, B00000, B00100, B00000
};
byte iconClock[8] = {
  B00000, B01110, B10101, B10111,
  B10001, B01110, B00000, B00000
};

// ============================================================
// BUZZER - MANUAL PWM (Anti-Interference)
// ============================================================
void playTone(int freq, int durationMs) {
  if (freq <= 0) { delay(durationMs); return; }
  long halfPeriod    = 500000L / freq;       
  int  cyclesChunk   = max(1, (int)(2000L / max(1L, 2 * halfPeriod)));   
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

// Buzzer TELAT: sirine nada ganda peringatan keras (5 siklus ~2.5 detik)
void buzzLate() {
  for (int i = 0; i < 5; i++) {
    playTone(2200, 180);
    delay(60);
    playTone(3200, 180);
    delay(60);
    yield();
  }
}

// ============================================================
// LCD HELPER (ANTI-FLICKER & BUFFER 16 KARAKTER)
// ============================================================
char lastLcdBuf1[17] = "";
char lastLcdBuf2[17] = "";

void lcdForceClear() {
  lcd.clear();
  lastLcdBuf1[0] = '\0';
  lastLcdBuf2[0] = '\0';
}

void lcdPrint(const char* baris1, const char* baris2 = "") {
  char buf1[17], buf2[17];
  int len1 = baris1 ? strlen(baris1) : 0;
  for (int i = 0; i < 16; i++) buf1[i] = (i < len1) ? baris1[i] : ' ';
  buf1[16] = '\0';
  int len2 = baris2 ? strlen(baris2) : 0;
  for (int i = 0; i < 16; i++) buf2[i] = (i < len2) ? baris2[i] : ' ';
  buf2[16] = '\0';

  if (strcmp(lastLcdBuf1, buf1) != 0) {
    lcd.setCursor(0, 0); lcd.print(buf1);
    strcpy(lastLcdBuf1, buf1);
  }
  if (strcmp(lastLcdBuf2, buf2) != 0) {
    lcd.setCursor(0, 1); lcd.print(buf2);
    strcpy(lastLcdBuf2, buf2);
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

// Format nama mahasiswa agar rapi di layar LCD 14 kolom (sisakan ruang icon)
String formatNamaLCD(String fullName) {
  fullName.trim();
  if (fullName.length() <= 14) return fullName;

  String lower = fullName;
  lower.toLowerCase();
  if (lower.startsWith("muhammad ")) {
    fullName = "M. " + fullName.substring(9);
  } else if (lower.startsWith("muh. ")) {
    fullName = "M. " + fullName.substring(5);
  } else if (lower.startsWith("mochamad ")) {
    fullName = "M. " + fullName.substring(9);
  } else if (lower.startsWith("ahmad ")) {
    fullName = "Ah. " + fullName.substring(6);
  }

  if (fullName.length() <= 14) return fullName;

  int lastSpace = fullName.substring(0, 15).lastIndexOf(' ');
  if (lastSpace > 4) return fullName.substring(0, lastSpace);
  return fullName.substring(0, 14);
}

// Format nama sesi ramah LCD 16 kolom
String formatSesiLCD(String id, String rawName) {
  if (id == "sesi_1") return "Sesi 1";
  if (id == "sesi_2") return "Sesi 2";
  if (id == "sesi_3") return "Sesi 3";
  if (rawName.length() > 16) return rawName.substring(0, 16);
  return rawName;
}

// Update layar standby dinamis (Jam NTP live + info sesi bergantian)
void updateStandbyScreen() {
  if (!isLcdStandby) return;

  time_t now = time(nullptr);
  struct tm* ptm = localtime(&now);
  bool ntpReady = (ptm && ptm->tm_year > 100);

  char line1[17];
  if (ntpReady) {
    snprintf(line1, sizeof(line1), "HIMATIF    %02d:%02d", ptm->tm_hour, ptm->tm_min);
  } else {
    snprintf(line1, sizeof(line1), "PRESENSI HIMATIF");
  }

  char line2[17];
  if (standbySubPhase == 0) {
    if (cachedSessionStatus == "nonaktif") {
      snprintf(line2, sizeof(line2), "! MODE TELAT !  ");
    } else {
      snprintf(line2, sizeof(line2), "\x02 TAP PANITIA >>");
    }
  } else {
    String s = formatSesiLCD(cachedSessionId, cachedSessionName);
    if (cachedSessionStatus == "nonaktif") {
      s += " (TLT)";
    }
    if (s.length() > 14) s = s.substring(0, 14);
    snprintf(line2, sizeof(line2), "\x02 %s", s.c_str());
  }
  lcdPrint(line1, line2);
}

// Tampilan layar siap / standby
void tampilkanStandby() {
  isLcdStandby = true;
  lcdPhase = 0;
  standbySubPhase = 0;
  updateStandbyScreen();
}

// Callback dipanggil jika ESP masuk mode Access Point (menunggu panitia setting Wi-Fi)
void configModeCallback(WiFiManager *myWiFiManager) {
  Serial.println("\n[WiFiManager] Gagal konek ke Wi-Fi tersimpan.");
  Serial.println("[WiFiManager] Masuk Mode Access Point (AP)!");
  Serial.print("[WiFiManager] Hubungkan HP ke Wi-Fi: ");
  Serial.println(myWiFiManager->getConfigPortalSSID());
  Serial.print("[WiFiManager] IP Portal: ");
  Serial.println(WiFi.softAPIP());

  lcdPrint("Setup WiFi di HP", centerText(AP_SSID).c_str());
  buzzNotice();
}

// ============================================================
// KONEKSI WIFI MANAGER (Auto-Connect & Captive Portal)
// ============================================================
void setupWiFi() {
  lcdPrint("\x03 Cari Wi-Fi...", "Menghubungkan...");
  Serial.println("\n[WiFiManager] Mencoba menyambung ke Wi-Fi...");

  // PENTING: Set mode STA dan disconnect dulu agar WiFiManager
  // bisa membuka AP dengan benar jika koneksi gagal
  WiFi.mode(WIFI_STA);
  WiFi.disconnect();
  delay(100);

  WiFiManager wm;

  // Callback saat masuk mode AP
  wm.setAPCallback(configModeCallback);

  // Timeout 180 detik (3 menit) jika portal tidak diisi, agar reboot otomatis
  wm.setConfigPortalTimeout(180);

  // Timeout koneksi WiFi 10 detik agar tidak stuck terlalu lama
  wm.setConnectTimeout(10);

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
    lcdPrint("\x03 WiFi Gagal!  ", "Restarting...   ");
    buzzError(); 
    delay(2000); 
    ESP.restart(); 
  }

  String ipESP = WiFi.localIP().toString();
  Serial.println("\n[WiFi] Terhubung! IP: " + ipESP);
  lcdPrint("\x03 WiFi Terhubung", centerText(ipESP).c_str());

  // Sinkronisasi Waktu NTP (WIB: UTC+7 = 7 * 3600)
  configTime(7 * 3600, 0, "pool.ntp.org", "time.google.com");
  Serial.println("[NTP] Memulai sinkronisasi waktu Internet (WIB)...");

  delay(600);
}

// ============================================================
// REFRESH CACHE (OPTIMASI 1)
// ============================================================
void refreshCache() {
  if (WiFi.status() == WL_CONNECTED && Firebase.ready()) {
    // Ambil data sesi lengkap sekaligus (id, name, status) dalam 1 request JSON cepat
    if (Firebase.RTDB.getJSON(&fbdo, "/active_session")) {
      if (fbdo.dataType() == "json") {
        FirebaseJson& json = fbdo.jsonObject();
        FirebaseJsonData d;
        if (json.get(d, "id") && d.stringValue.length() > 0 && d.stringValue != "null") {
          cachedSessionId = d.stringValue;
        }
        if (json.get(d, "name") && d.stringValue.length() > 0 && d.stringValue != "null") {
          cachedSessionName = d.stringValue;
        }
        if (json.get(d, "status") && d.stringValue.length() > 0 && d.stringValue != "null") {
          cachedSessionStatus = d.stringValue;
        }
      }
    }
    
    // Ambil data event aktif jika ada (id, name, target_audience)
    if (Firebase.RTDB.getJSON(&fbdo, "/active_event")) {
      if (fbdo.dataType() == "json") {
        FirebaseJson& jsonEv = fbdo.jsonObject();
        FirebaseJsonData d;
        if (jsonEv.get(d, "id") && d.stringValue.length() > 0 && d.stringValue != "null") {
          cachedEventId = d.stringValue;
        }
        if (jsonEv.get(d, "name") && d.stringValue.length() > 0 && d.stringValue != "null") {
          cachedEventName = d.stringValue;
        }
        if (jsonEv.get(d, "target_audience") && d.stringValue.length() > 0 && d.stringValue != "null") {
          cachedTargetAudience = d.stringValue;
        }
      }
    } else {
      if (Firebase.RTDB.getString(&fbdo, "/active_event/name")) {
        String s = fbdo.stringData();
        cachedEventName = (s.length() > 0 && s != "null") ? s : "";
      }
      if (Firebase.RTDB.getString(&fbdo, "/active_event/id")) {
        String idStr = fbdo.stringData();
        cachedEventId = (idStr.length() > 0 && idStr != "null") ? idStr : "";
      } else if (Firebase.RTDB.getInt(&fbdo, "/active_event/id")) {
        cachedEventId = String(fbdo.intData());
      }
    }
    lastCacheUpdate = millis();
    Serial.println("[CACHE] Diperbarui: " + cachedSessionName + " | Status: " + cachedSessionStatus + " | Event: " + (cachedEventName.length() > 0 ? cachedEventName : "(Tidak ada)"));
  }
}

// ============================================================
// TELEMETRY HEARTBEAT KE FIREBASE CLOUD
// ============================================================
void sendHeartbeat() {
  if (WiFi.status() == WL_CONNECTED && Firebase.ready()) {
    FirebaseJson devJson;
    time_t now = time(nullptr);
    struct tm* ptm = localtime(&now);
    bool ntpReady = (ptm && ptm->tm_year > 100);
    if (ntpReady) {
      devJson.set("last_seen", (double)now * 1000);
    } else {
      devJson.set("last_seen/.sv", "timestamp");
    }
    devJson.set("ip", WiFi.localIP().toString());
    devJson.set("rssi", WiFi.RSSI());
    devJson.set("status", "online");
    devJson.set("active_session", cachedSessionId);
    devJson.set("session_status", cachedSessionStatus);
    if (cachedEventName.length() > 0) {
      devJson.set("active_event", cachedEventName);
    }
    if (cachedEventId.length() > 0) {
      devJson.set("active_event_id", cachedEventId);
    }
    devJson.set("uptime_sec", millis() / 1000);
    Firebase.RTDB.updateNode(&fbdo, "/devices/esp8266", &devJson);
    lastHeartbeat = millis();
    Serial.println(F("[HEARTBEAT] Telemetry ESP8266 dikirim ke Cloud"));
  }
}

// ============================================================
// BACA UID KARTU PN532
// ============================================================
String bacaKartu() {
  uint8_t uid[12];
  uint8_t panjangUID = 0;

  if (!nfc.readPassiveTargetID(PN532_MIFARE_ISO14443A, uid, &panjangUID, 150)) {
    return ""; 
  }

  if (panjangUID == 0 || panjangUID > 10) {
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
  lcdPrint("BELUM TERDAFTAR!", centerText("UID: " + uid).c_str());

  String path = "/unknown_cards/" + uid;
  int currentTap = 0;
  if (Firebase.RTDB.getInt(&fbdo, path + "/tap_count")) {
    if (fbdo.dataType() == "int" || fbdo.dataType() == "float") {
      currentTap = fbdo.intData();
    }
  }

  time_t now = time(nullptr);
  struct tm* ptm = localtime(&now);
  bool ntpReady = (ptm && ptm->tm_year > 100);

  FirebaseJson json;
  json.set("uid", uid);
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

  // NON-BLOCKING LCD STATE
  pendingLcdLine1 = "KARTU BARU/ASING";
  pendingLcdLine2 = "Daftar ke Admin ";
  
  isLcdStandby = false;
  lcdPhase = 2; 
  lcdResetTime = millis() + 1800;
}

// ============================================================
// PROSES TAP KARTU LANGSUNG KE FIREBASE
// ============================================================
void prosesTapKartu(String uid) {
  if (WiFi.status() != WL_CONNECTED) {
    lcdPrint("\x03 WIFI PUTUS!  ", "Menghubungkan...");
    setupWiFi();
    tampilkanStandby();
    return;
  }

  Serial.println("[Firebase] Mengecek UID di Cloud: " + uid);
  lcdPrint("\x02 MEMBACA KARTU", centerText("UID: " + uid).c_str());

  if (!Firebase.ready()) {
    lcdPrint("  SERVER CLOUD  ", " Belum Siap...  ");
    buzzError();
    delay(800);
    tampilkanStandby();
    return;
  }

  // Cek data user di Cloud (Aman dari null-pointer crash)
  String pathUser = "/users/" + uid;
  bool userFound = false;
  String nama = "";
  String nim = "";
  String position = "";
  String role = "";
  String division = "";

  if (Firebase.RTDB.getJSON(&fbdo, pathUser)) {
    if (fbdo.dataType() == "json") {
      FirebaseJson& jsonUser = fbdo.jsonObject();
      FirebaseJsonData jsonData;
      
      if (jsonUser.get(jsonData, "name")) {
        nama = jsonData.stringValue;
      }
      if (jsonUser.get(jsonData, "nim")) {
        nim = jsonData.stringValue;
        if (nim == "null") nim = "";
      }
      if (jsonUser.get(jsonData, "position")) {
        position = jsonData.stringValue;
        if (position == "null") position = "";
      }
      if (jsonUser.get(jsonData, "role")) {
        role = jsonData.stringValue;
        if (role == "null") role = "";
      }
      if (jsonUser.get(jsonData, "division")) {
        division = jsonData.stringValue;
        if (division == "null") division = "";
      }
      if (nama.length() > 0 && nama != "null") {
        userFound = true;
      }
    }
  }

  // Jika kartu belum terdaftar, tangani sebagai unknown card dan keluar segera
  if (!userFound) {
    handleUnknownCard(uid);
    return;
  }
  
  // Ambil waktu dari NTP (Format 24 Jam konsisten HH:mm)
  time_t now = time(nullptr);
  struct tm* ptm = localtime(&now);

  char waktuBuf[16] = "00:00";
  char dateBuf[16] = "today";
  bool ntpReady = (ptm && ptm->tm_year > 100);

  if (ntpReady) {
    snprintf(waktuBuf, sizeof(waktuBuf), "%02d:%02d", ptm->tm_hour, ptm->tm_min);
    snprintf(dateBuf, sizeof(dateBuf), "%04d-%02d-%02d", ptm->tm_year + 1900, ptm->tm_mon + 1, ptm->tm_mday);
  }

  // Format nama mahasiswa untuk tampilan LCD
  String namaDisplay = formatNamaLCD(nama);

  // SINKRONISASI REALTIME 100%: Ambil sesi & status langsung dari Firebase saat tap kartu
  if (Firebase.RTDB.getJSON(&fbdo, "/active_session")) {
    if (fbdo.dataType() == "json") {
      FirebaseJson& jsonSess = fbdo.jsonObject();
      FirebaseJsonData d;
      if (jsonSess.get(d, "id") && d.stringValue.length() > 0 && d.stringValue != "null") cachedSessionId = d.stringValue;
      if (jsonSess.get(d, "name") && d.stringValue.length() > 0 && d.stringValue != "null") cachedSessionName = d.stringValue;
      if (jsonSess.get(d, "status") && d.stringValue.length() > 0 && d.stringValue != "null") cachedSessionStatus = d.stringValue;
    }
  }

  // Sinkronisasi event aktif jika cache masih kosong
  if (cachedEventId.length() == 0) {
    if (Firebase.RTDB.getString(&fbdo, "/active_event/id")) {
      cachedEventId = fbdo.stringData();
      if (cachedEventId == "null") cachedEventId = "";
    } else if (Firebase.RTDB.getInt(&fbdo, "/active_event/id")) {
      cachedEventId = String(fbdo.intData());
    }
  }
  if (cachedEventName.length() == 0) {
    if (Firebase.RTDB.getString(&fbdo, "/active_event/name")) {
      cachedEventName = fbdo.stringData();
      if (cachedEventName == "null") cachedEventName = "";
    }
  }

  // ============================================================
  // VALIDASI KEPANITIAAN: Batasi hanya mahasiswa panitia yang bisa absen
  // ============================================================
  bool isPanitia = false;
  String panitiaRole = "";

  // 1. Cek kepanitiaan pada event aktif di /event_committees/{eventId}/{uid}
  if (cachedEventId.length() > 0 && cachedEventId != "null") {
    String commPath = "/event_committees/" + cachedEventId + "/" + uid;
    if (Firebase.RTDB.getJSON(&fbdo, commPath)) {
      if (fbdo.dataType() == "json") {
        isPanitia = true;
        FirebaseJson& jsonComm = fbdo.jsonObject();
        FirebaseJsonData dRole;
        if (jsonComm.get(dRole, "role") && dRole.stringValue.length() > 0 && dRole.stringValue != "null") {
          panitiaRole = dRole.stringValue;
        } else {
          panitiaRole = "Panitia";
        }
      }
    } else if (Firebase.RTDB.getString(&fbdo, commPath + "/role")) {
      if (fbdo.dataType() == "string" && fbdo.stringData().length() > 0 && fbdo.stringData() != "null") {
        isPanitia = true;
        panitiaRole = fbdo.stringData();
      }
    }
  }

  // 2. Cek apakah ada data di node umum /committees/{uid}
  if (!isPanitia) {
    if (Firebase.RTDB.getJSON(&fbdo, "/committees/" + uid)) {
      if (fbdo.dataType() == "json") {
        isPanitia = true;
        FirebaseJson& jsonComm = fbdo.jsonObject();
        FirebaseJsonData dRole;
        if (jsonComm.get(dRole, "role") && dRole.stringValue.length() > 0 && dRole.stringValue != "null") {
          panitiaRole = dRole.stringValue;
        } else {
          panitiaRole = "Panitia";
        }
      }
    }
  }

  // 3. Cek posisi atau jabatan di profil user (/users/{uid})
  if (!isPanitia) {
    String posCheck = position;
    posCheck.toLowerCase();
    String roleCheck = role;
    roleCheck.toLowerCase();

    if (posCheck.indexOf("panitia") >= 0 || posCheck.indexOf("ketua") >= 0 || 
        posCheck.indexOf("sie") >= 0 || posCheck.indexOf("koor") >= 0 ||
        posCheck.indexOf("sekretaris") >= 0 || posCheck.indexOf("bendahara") >= 0 ||
        posCheck.indexOf("pengurus") >= 0 || posCheck.indexOf("divisi") >= 0 ||
        posCheck.indexOf("bidang") >= 0 ||
        roleCheck.indexOf("panitia") >= 0 || roleCheck.indexOf("ketua") >= 0 || 
        roleCheck.indexOf("sie") >= 0 || roleCheck.indexOf("koor") >= 0 ||
        roleCheck.indexOf("sekretaris") >= 0 || roleCheck.indexOf("bendahara") >= 0) {
      isPanitia = true;
      panitiaRole = (position.length() > 0) ? position : ((role.length() > 0) ? role : "Panitia");
    }
  }

  // JIKA BUKAN PANITIA: Tolak absensi!
  if (!isPanitia) {
    Serial.println("[DITOLAK] Bukan Panitia: " + nama + " (" + uid + ")");
    buzzError();
    lcdPrint(centerText("BUKAN PANITIA!").c_str(), centerText("AKSES DITOLAK!").c_str());

    // State machine untuk layar penjelasan (Non-blocking)
    pendingLcdLine1 = centerText(namaDisplay);
    pendingLcdLine2 = centerText("Khusus Panitia!");
    isLcdStandby = false;
    lcdPhase = 2;
    lcdResetTime = millis() + 1800;
    return;
  }

  String sessionId = cachedSessionId;
  String sessionName = cachedSessionName;
  String eventName = cachedEventName;

  // Cek apakah mahasiswa sudah tap pada SESI aktif hari ini
  String pathToday = "/attendance_today/" + String(dateBuf) + "_" + sessionId + "/" + uid;
  if (Firebase.RTDB.getBool(&fbdo, pathToday) && fbdo.dataType() == "boolean" && fbdo.boolData() == true) {
    Serial.println("[INFO] Kartu sudah absen di sesi " + sessionName + ": " + nama);
    buzzAlready();
    
    // Layar 1: Nama + Status Peringatan (dengan icon)
    lcdPrint(centerText(namaDisplay).c_str(), " ! SUDAH ABSEN !");
    
    // Setting State Machine untuk layar 2 (NON-BLOCKING)
    String sesiFormat = formatSesiLCD(sessionId, sessionName);
    pendingLcdLine1 = centerText(sesiFormat);
    pendingLcdLine2 = centerText("\x01 Data Tercatat");
    
    isLcdStandby = false;
    lcdPhase = 2; 
    lcdResetTime = millis() + 1200;
    return;
  }

  // ============================================================
  // CEK STATUS SESI: Jika "nonaktif" → mahasiswa TELAT
  // ============================================================
  bool isTelat = (cachedSessionStatus == "nonaktif");

  if (isTelat) {
    Serial.println("[TELAT] Sesi nonaktif! Mahasiswa telat: " + nama);
    lcdPrint(centerText(namaDisplay).c_str(), "!! ANDA TELAT !!");
    buzzLate(); // Sirine alarm buzzer alat ESP8266
  }

  // Tandai sudah tap pada sesi ini di cloud
  Firebase.RTDB.setBool(&fbdo, pathToday, true);

  if (!isTelat) {
    Serial.println("[OK] Absen Berhasil (" + sessionName + "): " + nama);
    buzzSuccess();
  }

  // Layar 1: Icon Centang + Nama + Status Hadir & Waktu
  String line1Hadir = "\x01 " + namaDisplay;
  if (line1Hadir.length() > 16) line1Hadir = line1Hadir.substring(0, 16);
  String statusHadir;
  if (isTelat) {
    statusHadir = ntpReady ? "TELAT \x04 " + String(waktuBuf) + " WIB" : "TELAT TERCATAT! ";
  } else {
    statusHadir = ntpReady ? "HADIR \x04 " + String(waktuBuf) + " WIB" : "ABSEN BERHASIL!";
  }
  if (statusHadir.length() > 16) {
    statusHadir = isTelat ? "TELAT \x04 " + String(waktuBuf) : "HADIR \x04 " + String(waktuBuf);
  }
  lcdPrint(centerText(line1Hadir).c_str(), centerText(statusHadir).c_str());

  // Setting State Machine untuk layar 2 (NON-BLOCKING)
  String sesiFormat = formatSesiLCD(sessionId, sessionName);
  String barisDua   = (panitiaRole.length() > 0) ? panitiaRole : ((nim.length() > 0) ? "NIM: " + nim : " TERIMA KASIH! ");
  if (barisDua.length() > 16) barisDua = barisDua.substring(0, 16);
  pendingLcdLine1 = centerText(sesiFormat);
  pendingLcdLine2 = centerText(barisDua);

  isLcdStandby = false;
  lcdPhase = 2; 
  lcdResetTime = millis() + 1300;

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

  // Tandai sebagai panitia terverifikasi di log cloud
  pendingLogData.set("is_committee", true);
  if (panitiaRole.length() > 0) {
    pendingLogData.set("committee_role", panitiaRole);
  }

  if (ntpReady) {
    pendingLogData.set("waktu", waktuBuf);
    pendingLogData.set("date", dateBuf);
    pendingLogData.set("timestamp", (double)now * 1000);
  } else {
    pendingLogData.set("timestamp/.sv", "timestamp");
  }

  // Tandai status telat di log jika sesi nonaktif
  if (isTelat) {
    pendingLogData.set("telat", true);
  }
  
  hasPendingLog = true;
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

  // Daftarkan karakter kustom ke CGRAM LCD
  lcd.createChar(1, iconCheck);
  lcd.createChar(2, iconCard);
  lcd.createChar(3, iconWifi);
  lcd.createChar(4, iconClock);

  lcdForceClear();
  lcdPrint("PRESENSI HIMATIF", "Memulai Sistem..");

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

  // Kirim heartbeat perdana ke cloud
  sendHeartbeat();

  tampilkanStandby();
  buzzConnected(); 
}

// ============================================================
// LOOP
// ============================================================
void loop() {
  digitalWrite(BUZZER_PIN, LOW); 

  unsigned long sekarang = millis();

  // ============================================================
  // 1. SINKRONISASI STATUS SESI BERKALA SAAT STANDBY (4 Detik)
  // ============================================================
  if (isLcdStandby && (sekarang - lastCacheUpdate > CACHE_INTERVAL)) {
    refreshCache();
  }

  // Kirim heartbeat telemetry ke Firebase (setiap 20 detik)
  if (sekarang - lastHeartbeat > HEARTBEAT_INTERVAL) {
    sendHeartbeat();
  }

  // Update live clock & info sesi di LCD saat standby (setiap 1 detik)
  if (isLcdStandby && (sekarang - lastStandbyTick >= 1000)) {
    lastStandbyTick = sekarang;
    standbySubPhase = (sekarang % 6000 < 3000) ? 0 : 1;
    updateStandbyScreen();
  }

  // Proses pending log secara asinkron (OPTIMASI 4)
  if (hasPendingLog) {
    if (WiFi.status() == WL_CONNECTED && Firebase.ready()) {
      Firebase.RTDB.pushJSON(&fbdo, "/log_presensi", &pendingLogData);
      hasPendingLog = false;
      Serial.println("[LOG] Log presensi berhasil dikirim (Async)");
    }
  }

  // Proses State Machine LCD (NON-BLOCKING)
  if (!isLcdStandby && sekarang > lcdResetTime) {
    if (lcdPhase == 2) {
      lcdPrint(pendingLcdLine1.c_str(), pendingLcdLine2.c_str());
      lcdPhase = 1;
      lcdResetTime = sekarang + 1100;
    } else if (lcdPhase == 1) {
      tampilkanStandby();
    }
  }

  // Pastikan koneksi Wi-Fi tetap aktif
  if (WiFi.status() != WL_CONNECTED) {
    lcdPrint("\x03 WIFI PUTUS!  ", "Menghubungkan...");
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
}