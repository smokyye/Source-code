#include <SPI.h>
#include <LoRa.h>
#include <WiFi.h>
#include <PubSubClient.h>
#include <ArduinoJson.h>

// ====================================================
// WIFI CONFIGURATION
// ====================================================
const char* ssid = "Y4";
const char* password = "12345678";

// ====================================================
// MQTT CONFIGURATION
// ====================================================
const char* mqtt_server = "broker.emqx.io";
const int mqtt_port = 1883;

// ====================================================
// WIFI & MQTT CLIENT
// ====================================================
WiFiClient espClient;
PubSubClient client(espClient);
//===================================================
// LORA STATISTICS
//===================================================
unsigned long packetReceived = 0;
unsigned long packetLost = 0;
unsigned long lastPacket = 0;

// ====================================================
// LORA CONFIGURATION
// ====================================================
#define LORA_SCK     18
#define LORA_MISO    19
#define LORA_MOSI    23
#define LORA_SS      5
#define LORA_RST     14
#define LORA_DIO0    26

// ====================================================
// WIFI CONNECTION
// ====================================================
void setup_wifi() {
  Serial.println();
  Serial.print("Menghubungkan ke WiFi");
  WiFi.begin(ssid, password);
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }
  Serial.println("\nWiFi Terhubung!");
  Serial.print("IP Address : ");
  Serial.println(WiFi.localIP());
}

// ====================================================
// MQTT RECONNECT
// ====================================================
void reconnect() {
  while (!client.connected()) {
    Serial.print("Menghubungkan ke MQTT...");
    String clientId = "GatewayMaster-";
    clientId += String(random(0xffff), HEX);

    if (client.connect(clientId.c_str())) {
      Serial.println(" MQTT Connected!");
    } else {
      Serial.print("Failed, rc=");
      Serial.print(client.state());
      Serial.println(" coba lagi 5 detik");
      delay(5000);
    }
  }
}

// ====================================================
// SETUP
// ====================================================
void setup() {
  Serial.begin(115200);

  // WIFI & MQTT SETUP
  setup_wifi();
  client.setServer(mqtt_server, mqtt_port);

  // LORA INIT
  SPI.begin(LORA_SCK, LORA_MISO, LORA_MOSI, LORA_SS);
  LoRa.setPins(LORA_SS, LORA_RST, LORA_DIO0);

  if (!LoRa.begin(433E6)) {
    Serial.println("LoRa Failed!");
    while (1);
  }

  // ====================================================
  // SINKRONISASI LORA DENGAN HELM (TX) Wajib Sama Persis!
  // ====================================================
  LoRa.setSpreadingFactor(11);    
  LoRa.setSignalBandwidth(125E3); 
  LoRa.setCodingRate4(5);         // Ditambahkan agar sinkron dengan Tx
  LoRa.setSyncWord(0xF3);         
  LoRa.enableCrc();               // Ditambahkan agar sinkron dengan Tx

  Serial.println("LoRa Gateway Ready - Menunggu Data...");
}

// ====================================================
// LOOP
// ====================================================
void loop() {
  if (!client.connected()) {
    reconnect();
  }
  client.loop();

  // RECEIVE LORA PACKET
  int packetSize = LoRa.parsePacket();

  if (packetSize) {
    String incomingJson = "";
    while (LoRa.available()) {
      incomingJson += (char)LoRa.read();
    }

    int rssi = LoRa.packetRssi();

    Serial.println("\n========================");
    Serial.println("DATA DITERIMA DARI HELM");
    Serial.println("========================");
    Serial.print("Raw JSON : ");
    Serial.println(incomingJson);
    Serial.print("RSSI     : ");
    Serial.print(rssi);
    Serial.println(" dBm");

    StaticJsonDocument<512> doc;
    DeserializationError error = deserializeJson(doc, incomingJson);

    if (error) {
      Serial.print("JSON Error : ");
      Serial.println(error.c_str());
      return;
    }

    // ====================================================
    //  AMBIL DATA JSON 
    // ====================================================
    String helmet_id = doc["helmet_id"].as<String>();
    unsigned long packet = doc["packet"].as<unsigned long>();
    
    // Kunci JSON diubah dari "temp" menjadi "temperature"
    float suhu       = doc["temperature"].as<float>(); 
    float humidity   = doc["humidity"].as<float>();

    float co         = doc["co"].as<float>();
    float co2        = doc["co2"].as<float>();
    float h2s        = doc["h2s"].as<float>();

    float o2         = doc["o2"].as<float>();
    float g_force    = doc["g_force"].as<float>();
    
    // Kunci JSON diubah dari "is_fall" menjadi "fall"
    bool is_fall     = doc["fall"].as<bool>(); 

    String status    = doc["status"].as<String>();

    // ====================================================
    // TAMPILKAN DATA KE SERIAL MONITOR
    // ====================================================
    Serial.print("ID Helm     : "); Serial.println(helmet_id);
    Serial.print("Temperature : "); Serial.print(suhu, 1); Serial.println(" C");
    Serial.print("Humidity    : "); Serial.print(humidity, 0); Serial.println(" %");
    Serial.print("CO          : "); Serial.print(co, 1); Serial.println(" ppm");
    Serial.print("CO2         : "); Serial.print(co2, 0); Serial.println(" ppm");
    Serial.print("H2S         : "); Serial.print(h2s, 1); Serial.println(" ppm");
    Serial.print("O2          : "); Serial.print(o2, 1); Serial.println(" %");
    Serial.print("G-Force     : "); Serial.print(g_force, 2); Serial.println(" G");
    Serial.print("Kecelakaan  : "); Serial.println(is_fall ? "YA (JATUH)" : "TIDAK");
    Serial.print("Status K3   : "); Serial.println(status);
    packetReceived++;
    if(lastPacket != 0)
      {
          if(packet > lastPacket + 1)
          {
              packetLost += (packet - lastPacket - 1);
          }
      }

      lastPacket = packet;

      float loss = 0;

      if(packetReceived + packetLost > 0)
      {
          loss = (float)packetLost /
                (packetReceived + packetLost) * 100.0;
      }
    Serial.println();

    Serial.println("===== Statistik LoRa =====");

    Serial.print("Packet No    : ");
    Serial.println(packet);

    Serial.print("Received     : ");
    Serial.println(packetReceived);

    Serial.print("Lost         : ");
    Serial.println(packetLost);

    Serial.print("Packet Loss  : ");
    Serial.print(loss,2);
    Serial.println("%");

    Serial.print("RSSI         : ");
    Serial.print(LoRa.packetRssi());
    Serial.println(" dBm");

    Serial.print("SNR          : ");
    Serial.print(LoRa.packetSnr());
    Serial.println(" dB");

    // PUBLISH KE MQTT 
    // ====================================================
    if (incomingJson.length() > 0) {
      // 1. Buat topik dinamis sesuai format yang diminta Web (4 Tingkat)
      String dynamic_topic = "tambang/helm/" + helmet_id + "/data";
      
      // 2. Publish ke topik dinamis tersebut
      bool success = client.publish(dynamic_topic.c_str(), incomingJson.c_str());
      
      if (success) {
        Serial.println(">> Data berhasil dikirim ke MQTT Broker (HiveMQ)");
        Serial.print(">> Topik: ");
        Serial.println(dynamic_topic);
      } else {
        Serial.println(">> Gagal publish ke MQTT");
      }
    } else {
      Serial.println("Data kosong, tidak dikirim");
    }
    Serial.println("========================");
  }
}