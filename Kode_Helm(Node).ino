#include <SPI.h>
#include <LoRa.h>
#include <Wire.h>
#include <ArduinoJson.h>
#include <U8g2lib.h>           // Pengganti LiquidCrystal_I2C
#include <Adafruit_ADS1X15.h>
#include <Adafruit_MPU6050.h>
#include <Adafruit_Sensor.h>
#include "DHT.h"
#include "DFRobot_OxygenSensor.h"

//==========================================================
// IDENTITAS HELM
//==========================================================
const String HELMET_ID = "helm-001";

//==========================================================
// PIN ESP32
//==========================================================
// PERHATIAN: Pin DHT22 dipindah ke GPIO 4 agar aman dari strapping
#define PIN_DHT22          15 
#define BUZZER_PIN         4  // HARAP DIGANTI: Pin 4 sudah dipakai DHT22. Silakan ganti pin Buzzer ke GPIO lain (misal: 12 atau 13)
#define CAM_TRIGGER_PIN    25

//==========================================================
// PIN LORA SX1278
//==========================================================
#define LORA_SCK           18
#define LORA_MISO          19
#define LORA_MOSI          23
#define LORA_SS             5
#define LORA_RST           14
#define LORA_DIO0          26

//==========================================================
// ADS1115 & OXYGEN CHANNEL
//==========================================================
#define MQ136_CHANNEL      0
#define MQ135_CHANNEL      1
#define MQ7_CHANNEL        2
#define Oxygen_IICAddress  ADDRESS_3

//==========================================================
// OBJECT INITIALIZATION
//==========================================================
#define DHTTYPE DHT22
DHT dht(PIN_DHT22, DHTTYPE);

Adafruit_ADS1115 ads;
Adafruit_MPU6050 mpu;
DFRobot_OxygenSensor o2Sensor;

// Konstruktor OLED SH1106
U8G2_SH1106_128X64_NONAME_F_HW_I2C u8g2(U8G2_R0, /* reset=*/ U8X8_PIN_NONE);

//==========================================================
// KALIBRASI MQ SENSOR
//==========================================================
const float Vc = 5.0;
const float RL = 1.0;

const float MQ135_R0 = 7.8177;
const float MQ136_R0 = 7.6621;
const float MQ7_R0   = 0.3348;

const float MQ135_A = 5672.05814;
const float MQ135_B = -2.02441;

const float MQ136_A = 37.67086;
const float MQ136_B = -3.62827;

const float MQ7_A = 99.49177;
const float MQ7_B = -3.05640;

//==========================================================
// VARIABEL SENSOR & STATUS
//==========================================================
float suhu = 0, hum = 0;
float ppmCO = 0, ppmCO2 = 0, ppmH2S = 0, concO2 = 0;
float gForce = 0, gyroMagnitude = 0;

bool isFalling = false;
String statusPekerja = "AMAN";

//==========================================================
// TIMER & CONTROL
//==========================================================
unsigned long previousRead = 0;
const unsigned long intervalRead = 2000;
unsigned long previousBuzzer = 0;
bool buzzerState = LOW;

//==========================================================
// FUNCTION PROTOTYPE
//==========================================================
void initSystem();
void readEnvironmentSensors();
void readMPU();
String decisionTreeRule();
void controlAlarm();
void updateOLED(); // Menggantikan updateLCD
void sendLoRa();

//==========================================================
// SETUP & LOOP
//==========================================================
void setup() {
    Serial.begin(115200);
    initSystem();
    
    Serial.println("\n=========================================");
    Serial.println(" SMART SAFETY HELMET - TX NODE");
    Serial.println("=========================================\n");
    Serial.println("System Ready...");
}

void loop() {
    unsigned long now = millis();
    controlAlarm();

    if(now - previousRead >= intervalRead) {
        previousRead = now;
        
        readEnvironmentSensors();
        readMPU();
        statusPekerja = decisionTreeRule();
        
        updateOLED(); // Panggil fungsi visualisasi OLED
        sendLoRa();
    }
}

//==========================================================
// INITIALIZATION SYSTEM
//==========================================================
void initSystem() {
    // PIN MODE
    // PENTING: Ubah BUZZER_PIN di bagian atas karena bentrok dengan DHT22 di GPIO 4
    // pinMode(BUZZER_PIN, OUTPUT);
    pinMode(CAM_TRIGGER_PIN, OUTPUT);
    // digitalWrite(BUZZER_PIN, LOW);
    digitalWrite(CAM_TRIGGER_PIN, LOW);

    // I2C
    Wire.begin(21,22);

    // INISIALISASI OLED
    u8g2.begin();
    u8g2.clearBuffer();
    u8g2.setFont(u8g2_font_6x10_tf);
    
    u8g2.setCursor(10, 20);
    u8g2.print("SMART HELMET K3");
    u8g2.setCursor(10, 35);
    u8g2.print("Booting System...");
    u8g2.sendBuffer();
    delay(1500);

    // INISIALISASI SENSOR
    dht.begin();
    
    if(!ads.begin()) {
        Serial.println("ERROR : ADS1115");
        while(1);
    }
    
    while(!o2Sensor.begin(Oxygen_IICAddress)) {
        Serial.println("ERROR : Oxygen Sensor");
        delay(1000);
    }

    if(!mpu.begin()) {
        Serial.println("ERROR : MPU6500");
        while(1);
    }
    mpu.setAccelerometerRange(MPU6050_RANGE_8_G);
    mpu.setGyroRange(MPU6050_RANGE_500_DEG);
    mpu.setFilterBandwidth(MPU6050_BAND_21_HZ);

    // INISIALISASI LORA
    SPI.begin(LORA_SCK, LORA_MISO, LORA_MOSI, LORA_SS);
    LoRa.setPins(LORA_SS, LORA_RST, LORA_DIO0);

    if(!LoRa.begin(433E6)) {
        Serial.println("ERROR : LoRa");
        while(1);
    }
    LoRa.setSpreadingFactor(11);
    LoRa.setSignalBandwidth(125E3);
    LoRa.setCodingRate4(5);
    LoRa.setTxPower(20);
    LoRa.setSyncWord(0xF3);
    LoRa.enableCrc();

    // SYSTEM READY SPLASH SCREEN
    u8g2.clearBuffer();
    u8g2.setCursor(0, 15);
    u8g2.print("SYSTEM READY");
    u8g2.setCursor(0, 30);
    u8g2.print("ID : "); u8g2.print(HELMET_ID);
    u8g2.setCursor(0, 45);
    u8g2.print("LoRa Connected");
    u8g2.sendBuffer();
    delay(2000);
}

//==========================================================
// READ ENVIRONMENT SENSORS (Moving Average Dipertahankan)
//==========================================================
void readEnvironmentSensors() {
    suhu = dht.readTemperature();
    hum  = dht.readHumidity();
    if (isnan(suhu)) suhu = 0;
    if (isnan(hum)) hum = 0;

    float vRL135 = ads.computeVolts(ads.readADC_SingleEnded(MQ135_CHANNEL));
    float vRL136 = ads.computeVolts(ads.readADC_SingleEnded(MQ136_CHANNEL));
    float vRL7   = ads.computeVolts(ads.readADC_SingleEnded(MQ7_CHANNEL));

    if(vRL135 <= 0.01) vRL135 = 0.01;
    if(vRL136 <= 0.01) vRL136 = 0.01;
    if(vRL7 <= 0.01)   vRL7 = 0.01;

    float Rs135 = ((Vc / vRL135) - 1.0) * RL;
    float Rs136 = ((Vc / vRL136) - 1.0) * RL;
    float Rs7   = ((Vc / vRL7) - 1.0) * RL;

    ppmCO2 = MQ135_A * pow((Rs135 / MQ135_R0), MQ135_B);
    ppmH2S = MQ136_A * pow((Rs136 / MQ136_R0), MQ136_B);
    ppmCO  = MQ7_A * pow((Rs7 / MQ7_R0), MQ7_B);

    if(ppmCO < 0) ppmCO = 0;
    if(ppmCO2 < 0) ppmCO2 = 0;
    if(ppmH2S < 0) ppmH2S = 0;

    if(ppmCO > 1000) ppmCO = 1000;
    if(ppmCO2 > 10000) ppmCO2 = 10000;
    if(ppmH2S > 200) ppmH2S = 200;

    concO2 = o2Sensor.getOxygenData(10);
    if(concO2 < 0) concO2 = 0;
    if(concO2 > 25) concO2 = 25;

    // Moving Average Filter
    static float tempBuffer[5] = {0};
    static float humBuffer[5]  = {0};
    static float o2Buffer[5]   = {0};
    static int index = 0;

    tempBuffer[index] = suhu;
    humBuffer[index]  = hum;
    o2Buffer[index]   = concO2;
    index = (index + 1) % 5;

    float sumTemp = 0, sumHum = 0, sumO2 = 0;
    for(int i=0; i<5; i++) {
        sumTemp += tempBuffer[i];
        sumHum  += humBuffer[i];
        sumO2   += o2Buffer[i];
    }
    suhu   = sumTemp / 5.0;
    hum    = sumHum  / 5.0;
    concO2 = sumO2   / 5.0;
}

//==========================================================
// READ MPU6500
//==========================================================
void readMPU() {
    sensors_event_t a, g, temp;
    mpu.getEvent(&a, &g, &temp);

    gForce = sqrt(pow(a.acceleration.x,2) + pow(a.acceleration.y,2) + pow(a.acceleration.z,2));
    gyroMagnitude = sqrt(pow(g.gyro.x,2) + pow(g.gyro.y,2) + pow(g.gyro.z,2));
}

//==========================================================
// RULE BASED DECISION TREE
//==========================================================
String decisionTreeRule() {
    statusPekerja = "AMAN";
    isFalling = false;

    // Deteksi Jatuh
    if(gForce >= 15.0) {
        if(gyroMagnitude >= 3.0) {
            statusPekerja = "BAHAYA";
            isFalling = true;
        } else {
            statusPekerja = "WASPADA";
        }
    }

    // CO
    if(ppmCO >= 50) statusPekerja = "BAHAYA";
    else if(ppmCO >= 35 && statusPekerja == "AMAN") statusPekerja = "WASPADA";

    // H2S
    if(ppmH2S >= 5) statusPekerja = "BAHAYA";
    else if(ppmH2S >= 1 && statusPekerja == "AMAN") statusPekerja = "WASPADA";

    // CO2
    if(ppmCO2 >= 1000 && statusPekerja == "AMAN") statusPekerja = "WASPADA";

    // Oxygen
    if(concO2 < 19.5) statusPekerja = "BAHAYA";

    return statusPekerja;
}

//==========================================================
// CONTROL ALARM
//==========================================================
void controlAlarm() {
    unsigned long now = millis();
    // Komentar sementara untuk menghindari bentrok pin Buzzer
    
    if(statusPekerja == "BAHAYA") {
        digitalWrite(BUZZER_PIN, HIGH);
        digitalWrite(CAM_TRIGGER_PIN, HIGH);
        return;
    }
    if(statusPekerja == "WASPADA") {
        digitalWrite(CAM_TRIGGER_PIN, LOW);
        if(now - previousBuzzer >= 150) {
            previousBuzzer = now;
            buzzerState = !buzzerState;
            digitalWrite(BUZZER_PIN, buzzerState);
        }
        return;
    }
    digitalWrite(BUZZER_PIN, LOW);
    digitalWrite(CAM_TRIGGER_PIN, LOW);
}

//==========================================================
// UPDATE OLED DISPLAY (Baru)
//==========================================================
void updateOLED() {
    u8g2.clearBuffer();          
    u8g2.setFont(u8g2_font_6x10_tf); 

    // Baris 1: ID Helmet dan Status Keputusan
    u8g2.setCursor(0, 10);
    u8g2.print(HELMET_ID);
    u8g2.print(" | ");
    u8g2.print(statusPekerja);

    // Garis Pemisah Desain
    u8g2.drawHLine(0, 14, 128);

    // Baris 2: Iklim Lingkungan
    u8g2.setCursor(0, 26);
    u8g2.print("T: "); u8g2.print(suhu, 1); u8g2.print("C   ");
    u8g2.print("H: "); u8g2.print(hum, 0); u8g2.print("%");

    // Baris 3: Oksigen dan CO
    u8g2.setCursor(0, 38);
    u8g2.print("O2: "); u8g2.print(concO2, 1); u8g2.print("%  ");
    u8g2.print("CO: "); u8g2.print(ppmCO, 0);

    // Baris 4: H2S
    u8g2.setCursor(0, 50);
    u8g2.print("H2S : "); u8g2.print(ppmH2S, 1); u8g2.print(" ppm");

    // Baris 5: CO2
    u8g2.setCursor(0, 62);
    u8g2.print("CO2 : "); u8g2.print(ppmCO2, 0); u8g2.print(" ppm");

    u8g2.sendBuffer(); // Eksekusi ke kaca layar
}

//==========================================================
// SEND DATA LORA JSON
//==========================================================
void sendLoRa() {
    StaticJsonDocument<512> doc;

    doc["helmet_id"] = HELMET_ID;
    doc["temperature"] = suhu;
    doc["humidity"]    = hum;
    doc["co"]  = ppmCO;
    doc["co2"] = ppmCO2;
    doc["h2s"] = ppmH2S;
    doc["o2"] = concO2;
    doc["g_force"] = gForce;
    doc["fall"] = isFalling;
    doc["status"] = statusPekerja;
    doc["time"] = millis();

    String jsonData;
    serializeJson(doc, jsonData);

    LoRa.beginPacket();
    LoRa.print(jsonData);
    LoRa.endPacket();
}