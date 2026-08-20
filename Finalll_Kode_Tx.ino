#include <SPI.h>
#include <LoRa.h>
#include <Wire.h>
#include <ArduinoJson.h>
#include <U8g2lib.h>           
#include <Adafruit_ADS1X15.h>
#include "DHT.h"
#include "DFRobot_OxygenSensor.h"
#include <HardwareSerial.h>
#include <MPU6500_WE.h>
HardwareSerial CamSerial(2);

//==========================================================
// IDENTITAS HELM
//==========================================================
const String HELMET_ID = "helm-001";

//==========================================================
// PIN ESP32
//==========================================================
#define PIN_DHT22          15
#define BUZZER_PIN         4 

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
#define MQ135_CHANNEL      0
#define MQ136_CHANNEL      1
#define MQ7_CHANNEL        2
#define Oxygen_IICAddress  ADDRESS_3

//==========================================================
// OBJECT INITIALIZATION
//==========================================================
#define DHTTYPE DHT22
DHT dht(PIN_DHT22, DHTTYPE);

Adafruit_ADS1115 ads;
DFRobot_OxygenSensor o2Sensor;
#define MPU6500_ADDR 0x68

MPU6500_WE mpu = MPU6500_WE(MPU6500_ADDR);

U8G2_SH1106_128X64_NONAME_F_HW_I2C u8g2(U8G2_R0, /* reset=*/ U8X8_PIN_NONE);

//==========================================================
// KALIBRASI MQ SENSOR (DIPERBARUI)
//==========================================================
const float Vc = 5.10; // Menggunakan tegangan riil step-down
const float RL = 1.0;

// Nilai R0 hasil kalibrasi di udara bersih
const float MQ135_R0 = 9.3288; // CO2
const float MQ136_R0 = 6.3093; // H2S
const float MQ7_R0   = 0.3051; // CO

// Konstanta Regresi (Datasheet)
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
// PACKET COUNTER
//==========================================================
unsigned long packetNumber = 0;

//==========================================================
// TIMER & CONTROL
//==========================================================
unsigned long previousRead = 0;
const unsigned long intervalRead = 2000;

unsigned long previousBuzzer = 0;
bool buzzerState = LOW;

//==========================================================
// MQ SENSOR WARM-UP
//==========================================================
bool sensorReady = false;
unsigned long warmupStart = 0;
const unsigned long warmupTime = 60000;   // 60 detik


// Untuk pola buzzer
byte warningCount = 0;

// Cooldown Trigger Kamera
unsigned long lastTriggerTime = 0;
const unsigned long triggerCooldown = 10000; // Jeda 10 detik antar jepretan

//==========================================================
// FUNCTION PROTOTYPE
//==========================================================
void initSystem();
void readEnvironmentSensors();
void readMPU();
String decisionTreeRule();
void controlAlarm();
void updateOLED(); 
void sendLoRa();

void calibrateMQ()
{
    Serial.println("\n================================");
    Serial.println("   KALIBRASI SENSOR MQ");
    Serial.println("================================");

    float rs135 = 0;
    float rs136 = 0;
    float rs7 = 0;

    const int samples = 100;

    for(int i=0;i<samples;i++)
    {
        float v135 = ads.computeVolts(ads.readADC_SingleEnded(MQ135_CHANNEL));
        float v136 = ads.computeVolts(ads.readADC_SingleEnded(MQ136_CHANNEL));
        float v7   = ads.computeVolts(ads.readADC_SingleEnded(MQ7_CHANNEL));

        if(v135 < 0.01) v135 = 0.01;
        if(v136 < 0.01) v136 = 0.01;
        if(v7   < 0.01) v7   = 0.01;

        rs135 += ((Vc / v135) - 1.0) * RL;
        rs136 += ((Vc / v136) - 1.0) * RL;
        rs7   += ((Vc / v7) - 1.0) * RL;

        delay(100);
    }

    rs135 /= samples;
    rs136 /= samples;
    rs7   /= samples;

    Serial.println("\n===== HASIL =====");

    Serial.print("MQ135 Rs = ");
    Serial.println(rs135,4);

    Serial.print("MQ136 Rs = ");
    Serial.println(rs136,4);

    Serial.print("MQ7 Rs = ");
    Serial.println(rs7,4);

    Serial.println("\nGunakan nilai Rs tersebut untuk menghitung R0.");
}

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
    warmupStart = millis();
}

void loop()
{
    unsigned long now = millis();

    //=============================
    // WARM UP SENSOR MQ
    //=============================
    if(!sensorReady)
    {
        if(now - warmupStart >= warmupTime)
        {
            sensorReady = true;
            Serial.println("MQ Sensor Ready");
        }
        else
        {
            u8g2.clearBuffer();
            u8g2.setCursor(10,20);
            u8g2.print("Sensor Warm Up");

            u8g2.setCursor(10,40);
            u8g2.print((warmupTime-(now-warmupStart))/1000);
            u8g2.print(" s");

            u8g2.sendBuffer();

            digitalWrite(BUZZER_PIN,LOW);

            return;
        }
    }

    controlAlarm();

    if(now - previousRead >= intervalRead)
    {
        previousRead = now;

        readEnvironmentSensors();

        readMPU();

        statusPekerja = decisionTreeRule();

        updateOLED();

        sendLoRa();
    }
}
//==========================================================
// INITIALIZATION SYSTEM
//==========================================================
void initSystem() {
    pinMode(BUZZER_PIN, OUTPUT);
    digitalWrite(BUZZER_PIN, LOW); // Diasumsikan Buzzer Anda tipe Active-HIGH

    Wire.begin(21,22);
    CamSerial.begin(
      115200,
      SERIAL_8N1,
      -1,
      25
   );

    u8g2.begin();
    u8g2.clearBuffer();
    u8g2.setFont(u8g2_font_6x10_tf);
    u8g2.setCursor(10, 20);
    u8g2.print("SMART HELMET K3");
    u8g2.setCursor(10, 35);
    u8g2.print("Booting System...");
    u8g2.sendBuffer();
    delay(1500);

    dht.begin();
    
    if(!ads.begin()) {
        Serial.println("ERROR : ADS1115");
        while(1);
    }
    
    while(!o2Sensor.begin(Oxygen_IICAddress)) {
        Serial.println("ERROR : Oxygen Sensor");
        delay(1000);
        calibrateMQ();
        while(1);
    }

    // ========================================================
    //====================================================
// MPU6500
//====================================================

      if(!mpu.init())
      {
          Serial.println("ERROR : MPU6500");

          while(1);
      }

      Serial.println("MPU6500 OK");

      mpu.autoOffsets();

      mpu.enableGyrDLPF();

      mpu.setGyrDLPF(MPU6500_DLPF_6);

      mpu.setSampleRateDivider(5);

      mpu.setAccRange(MPU6500_ACC_RANGE_8G);

      mpu.setAccDLPF(MPU6500_DLPF_6);
          
    // ========================================================
    // INISIALISASI LORA
    // ========================================================
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
// READ ENVIRONMENT SENSORS 
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
    //==========================================================
    // MOVING AVERAGE MQ
    //==========================================================
    static float coBuffer[5] = {0};
    static float co2Buffer[5] = {0};
    static float h2sBuffer[5] = {0};

    static byte mqIndex = 0;

    coBuffer[mqIndex] = ppmCO;
    co2Buffer[mqIndex] = ppmCO2;
    h2sBuffer[mqIndex] = ppmH2S;

    mqIndex++;

    if(mqIndex >= 5)
        mqIndex = 0;

    float sumCO = 0;
    float sumCO2 = 0;
    float sumH2S = 0;

    for(int i=0;i<5;i++)
    {
        sumCO += coBuffer[i];
        sumCO2 += co2Buffer[i];
        sumH2S += h2sBuffer[i];
    }

ppmCO = sumCO / 5.0;
ppmCO2 = sumCO2 / 5.0;
ppmH2S = sumH2S / 5.0;

    if(ppmCO < 0) ppmCO = 0;
    if(ppmCO2 < 0) ppmCO2 = 0;
    if(ppmH2S < 0) ppmH2S = 0;

    if(ppmCO > 1000) ppmCO = 1000;
    if(ppmCO2 > 10000) ppmCO2 = 10000;
    if(ppmH2S > 200) ppmH2S = 200;

    concO2 = o2Sensor.getOxygenData(10);
    if(concO2 < 0) concO2 = 0;
    if(concO2 > 25) concO2 = 25;

    // Filter Moving Average sederhana
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
    //==========================================================
    // DEBUG SENSOR
    //==========================================================
    Serial.println("\n========== MQ DEBUG ==========");

    Serial.print("VRL MQ7  : ");
    Serial.println(vRL7);
    Serial.print("Rs MQ7   : ");
    Serial.println(Rs7);
    Serial.print("CO       : ");
    Serial.println(ppmCO);

    Serial.println();

    Serial.print("VRL135 : ");
    Serial.println(vRL135,3);

    Serial.print("Rs135 : ");
    Serial.println(Rs135,3);

    Serial.print("Ratio : ");
    Serial.println(Rs135/MQ135_R0,3);

    Serial.print("CO2 : ");
    Serial.println(ppmCO2);
    Serial.println();

    Serial.print("VRL MQ136: ");
    Serial.println(vRL136);
    Serial.print("Rs MQ136 : ");
    Serial.println(Rs136);
    Serial.print("H2S      : ");
    Serial.println(ppmH2S);

    Serial.println("==============================");
}

//==========================================================
// READ MPU6500 SECARA MANUAL (Revisi Satuan)
//==========================================================
void readMPU()
{
    xyzFloat acc = mpu.getGValues();

    xyzFloat gyro = mpu.getGyrValues();

    gForce = sqrt(
                acc.x * acc.x +
                acc.y * acc.y +
                acc.z * acc.z
             );

    gyroMagnitude = sqrt(
                        gyro.x * gyro.x +
                        gyro.y * gyro.y +
                        gyro.z * gyro.z
                    );

    Serial.print("G : ");
    Serial.print(gForce,2);

    Serial.print("   Gyro : ");

    Serial.println(gyroMagnitude,2);
}
//==========================================================
// RULE BASED DECISION TREE
//==========================================================
String decisionTreeRule() {
    statusPekerja = "AMAN";
    isFalling = false;

    // Jika terjadi guncangan keras / Jatuh
    if(gForce >= 1.5) { 
        statusPekerja = "BAHAYA";
        isFalling = true;
    }

    // Pengecekan Gas CO & CO2
    if(ppmCO >= 50) statusPekerja = "BAHAYA";
    else if(ppmCO >= 35 && statusPekerja == "AMAN") statusPekerja = "WASPADA";

    if(ppmCO2 >= 1000 && statusPekerja == "AMAN") statusPekerja = "WASPADA";

    // Pengecekan Gas H2S
    if(ppmH2S >= 5) statusPekerja = "BAHAYA";
    else if(ppmH2S >= 3 && statusPekerja == "AMAN") statusPekerja = "WASPADA";

    // Pengecekan Oksigen
    if(concO2 < 19.5) statusPekerja = "BAHAYA";

    return statusPekerja;
}

//==========================================================
// CONTROL ALARM & TRIGGER KAMERA
//==========================================================
void controlAlarm()
{
    unsigned long now = millis();

    //--------------------------------------------------
    // AMAN
    //--------------------------------------------------
    if(statusPekerja == "AMAN")
    {
        digitalWrite(BUZZER_PIN, LOW);

        buzzerState = LOW;
        warningCount = 0;

        return;
    }

    //--------------------------------------------------
    // WASPADA
    //--------------------------------------------------
    if(statusPekerja == "WASPADA")
    {
        unsigned long interval;

        // 3 bip kemudian jeda
        if(warningCount < 6)
            interval = 150;     // ON/OFF cepat
        else
            interval = 1000;    // jeda panjang

        if(now - previousBuzzer >= interval)
        {
            previousBuzzer = now;

            if(warningCount < 6)
            {
                buzzerState = !buzzerState;
                digitalWrite(BUZZER_PIN, buzzerState);
                warningCount++;
            }
            else
            {
                warningCount = 0;
                buzzerState = LOW;
                digitalWrite(BUZZER_PIN, LOW);
            }
        }

        return;
    }

    //--------------------------------------------------
    // BAHAYA
    //--------------------------------------------------
    if(statusPekerja == "BAHAYA")
    {
        if(now - previousBuzzer >= 100)
        {
            previousBuzzer = now;

            buzzerState = !buzzerState;

            digitalWrite(BUZZER_PIN, buzzerState);
        }

        if(now - lastTriggerTime >= triggerCooldown)
        {
            Serial.println(">>> UART : CAPTURE");

            CamSerial.println("CAPTURE");

            lastTriggerTime = now;
        }

        return;
    }
}

//==========================================================
// UPDATE OLED DISPLAY (U8G2)
//==========================================================
void updateOLED() {
    u8g2.clearBuffer();          
    u8g2.setFont(u8g2_font_6x10_tf); 

    u8g2.setCursor(0, 10);
    u8g2.print(HELMET_ID);
    u8g2.print(" | ");
    u8g2.print(statusPekerja);

    u8g2.drawHLine(0, 14, 128);

    u8g2.setCursor(0, 26);
    u8g2.print("T: "); u8g2.print(suhu, 1); u8g2.print("C   ");
    u8g2.print("H: "); u8g2.print(hum, 0); u8g2.print("%");

    u8g2.setCursor(0, 38);
    u8g2.print("O2: "); u8g2.print(concO2, 1); u8g2.print("%  ");
    u8g2.print("CO: "); u8g2.print(ppmCO, 0);

    u8g2.setCursor(0, 50);
    u8g2.print("H2S : "); u8g2.print(ppmH2S, 1); u8g2.print(" ppm");

    u8g2.setCursor(0, 62);
    u8g2.print("CO2 : "); u8g2.print(ppmCO2, 0); u8g2.print(" ppm");

    u8g2.sendBuffer(); 
}

//==========================================================
// SEND DATA LORA JSON
//==========================================================
void sendLoRa() {
    StaticJsonDocument<512> doc;
    packetNumber++;

    doc["helmet_id"]   = HELMET_ID;
    doc["packet"]      = packetNumber;
    
    doc["temperature"] = suhu;
    doc["humidity"]    = hum;
    doc["co"]          = ppmCO;
    doc["co2"]         = ppmCO2;
    doc["h2s"]         = ppmH2S;
    doc["o2"]          = concO2;
    doc["g_force"]     = gForce;
    doc["fall"]        = isFalling;
    doc["status"]      = statusPekerja;
    doc["time"]        = millis();

    String jsonData;
    serializeJson(doc, jsonData);

    LoRa.beginPacket();
    LoRa.print(jsonData);
    LoRa.endPacket();
    
    Serial.println("\n[LoRa TX] Paket terkirim:");
    Serial.println(jsonData);
}