/*
 * ============================================================
 * SISTEM PRESENSI MAHASISWA - FIREBASE CLOUD VERSION
 * Hardware : ESP8266 Lolin (NodeMCU v3)
 * NFC      : PN532 (Mode SPI)
 * Display  : LCD 16x2 I2C
 * Buzzer   : Aktif/Pasif kecil (D8 / GPIO15)
 * Server   : Firebase Realtime Database (smartgen-db-26)
 * ============================================================
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
 *   LCD VCC  → VIN (5V dari adaptor)
 *   LCD GND  → GND
 *   LCD SDA  → D2 (GPIO4)
 *   LCD SCL  → D1 (GPIO5)
 *
 *   Buzzer + → D8 (GPIO15)    <-- Aman di D8
 *   Buzzer - → GND
 * ============================================================
 */

#include <Arduino.h>
#include <ESP8266WiFi.h>
#include <SPI.h>
#include <Adafruit_PN532.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <Firebase_ESP_Client.h>

// Helper untuk token dan RTDB dari library Firebase Mobizt
#include "addons/TokenHelper.h"
#include "addons/RTDBHelper.h"

// ============================================================
// KONFIGURASI JARINGAN & FIREBASE
// ============================================================
#define WIFI_SSID     "COFFEE"
#define WIFI_PASSWORD "kopi@123"

#define FIREBASE_API_KEY      "AIzaSyDoxT72TbDtKdkhl58gum62f6tZqo6V_vU"
#define FIREBASE_DATABASE_URL "smartgen-db-26-default-rtdb.asia-southeast1.firebasedatabase.app"

// ============================================================
// KONFIGURASI PIN
// ============================================================
#define PN532_SS    16   // D0 (GPIO16)
#define BUZZER_PIN  15   // D8 (GPIO15)

// LCD I2C
#define LCD_ADDR  0x27
#define LCD_COLS  16
#define LCD_ROWS   2

// ============================================================
// INISIALISASI OBJEK
// ============================================================
Adafruit_PN532  nfc(PN532_SS); 
LiquidCrystal_I2C lcd(LCD_ADDR, LCD_COLS, LCD_ROWS);

FirebaseData fbdo;
FirebaseAuth auth;
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

void lcdWelcome(String nama) {
  lcd.clear();
  lcd.setCursor(0, 0); lcd.print("Selamat Datang!");

  if ((int)nama.length() <= LCD_COLS) {
    lcd.setCursor(0, 1); lcd.print(nama);
    delay(750);
  } else {
    lcd.setCursor(0, 1); lcd.print(nama.substring(0, LCD_COLS));
    delay(250);
    for (int i = 1; i <= (int)nama.length() - LCD_COLS; i++) {
      lcd.setCursor(0, 1); lcd.print(nama.substring(i, i + LCD_COLS));
      delay(65); 
    }
    delay(250);
  }
}

// ============================================================
// KONEKSI WIFI
// ============================================================
void setupWiFi() {
  Serial.println("\n[WiFi] Menghubungkan ke hotspot: " + String(WIFI_SSID));
  lcdPrint("Hotspot HP...", String(WIFI_SSID).substring(0, 16).c_str());

  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  int coba = 0;
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
    coba++;
    if (coba > 40) {
      lcdPrint("Hotspot Timeout!", "Cek HP Anda...");
      buzzError(); delay(3000); ESP.restart(); 
    }
  }

  String ipESP = WiFi.localIP().toString();
  Serial.println("\n[WiFi] Terhubung! IP: " + ipESP);
  lcdPrint("WiFi Terhubung!", ipESP.c_str());
  delay(2000);
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

  // Cek langsung field nama di /users/{uid}/name (Sangat cepat & akurat tanpa beban JSON parser)
  String pathName = "/users/" + uid + "/name";
  if (Firebase.RTDB.getString(&fbdo, pathName) && fbdo.dataType() == "string" && fbdo.stringData().length() > 0 && fbdo.stringData() != "null") {
    String nama = fbdo.stringData();
    
    Serial.println("[OK] Absen Berhasil: " + nama);
    buzzSuccess();
    lcdWelcome(nama);

    // Kirim log absen ke Firebase (/log_presensi)
    FirebaseJson logData;
    logData.set("uid", uid);
    logData.set("name", nama);
    logData.set("timestamp", (int)millis());

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

  Wire.begin(4, 5); // SDA = D2, SCL = D1
  lcd.init();
  lcd.backlight();
  lcdPrint("Presensi Cloud", "Starting...");

  SPI.begin();
  nfc.begin();

  uint32_t firmwareVersion = nfc.getFirmwareVersion();
  if (!firmwareVersion) {
    lcdPrint("PN532 ERROR!", "Cek Kabel SPI!");
    buzzError(); delay(5000); ESP.restart(); 
  }

  nfc.SAMConfig();
  
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

  if (uid == lastUID && (sekarang - lastDebounce) < DEBOUNCE_DELAY) {
    return;
  }

  lastUID      = uid;
  lastDebounce = sekarang;

  Serial.println("[KARTU] UID Terdeteksi: " + uid);
  prosesTapKartu(uid);
  
  lastDebounce = millis();
}
