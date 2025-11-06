# Panduan Instalasi · ETL

Dokumen ini menjelaskan cara menyiapkan setiap komponen di bawah direktori `etl/` sehingga Anda dapat menjalankan pipeline Kafka ↔ Python ↔ MySQL yang menjadi dasar sesi pelatihan PemateriKafka.

---

## 1. Prasyarat

| Kebutuhan                                   | Catatan                                                                        |
| ------------------------------------------- | ------------------------------------------------------------------------------ |
| Docker Desktop 4.26+ atau Docker Engine 24+ | Syntax Compose V2 digunakan di semua stack.                                    |
| docker-compose CLI                          | Sudah termasuk di Docker Desktop, atau install plugin `docker compose`.        |
| Python 3.9+ (opsional)                      | Hanya diperlukan jika Anda ingin menjalankan script ETL di luar Docker.        |
| Node.js 18+ (opsional)                      | Diperlukan saat bekerja dengan asset Laravel atau membangun frontend upstream. |
| `pelatihankafka_default` Docker network     | Dibuat otomatis oleh `docker-compose.yml` di root project.                     |

Semua perintah di bawah mengasumsikan struktur direktori berikut:

```
PemateriKafka/
├── docker-compose.yml         # Kafka broker (KRaft)
├── servers/                   # Laravel producer/consumer + MySQL
└── etl/                       # (Anda di sini)
```

---

## 2. Menyiapkan Layanan Inti

1. **Jalankan Kafka (KRaft)**

   ```bash
   cd PemateriKafka
   docker-compose up -d
   docker compose ps
   ```

2. **Jalankan stack Laravel + MySQL**

   ```bash
   cd servers
   docker-compose up -d
   docker-compose ps
   ```

3. **Verifikasi shared network**
   ```bash
   docker network inspect pelatihankafka_default >/dev/null
   ```
   Jika tidak ada, jalankan ulang `docker-compose up -d` di root—ini akan membuat network secara otomatis.

---

## 3. Layer Extract · Maxwell CDC

1. **Konfigurasi (opsional)**  
   `etl/extract/example-maxwell/config.properties` sudah menargetkan:

   - Source MySQL: `mysql_servers:3306`
   - Kafka broker: `kafka:29092`
   - Penamaan topic: `app_producer.%{database}.%{table}`
     Perbarui kredensial atau filter jika skema Anda berbeda.

2. **Jalankan Maxwell + metadata MySQL**

   ```bash
   cd etl/extract/example-maxwell
   docker-compose up -d
   docker-compose logs -f maxwell_cdc
   ```

3. **Pemeriksaan kesehatan**
   ```bash
   curl -f http://localhost:8083/healthcheck
   docker exec maxwell_cdc tail -n50 /var/log/maxwell/maxwell.log
   ```

---

## 4. Layer Transform · Python ETL

1. **Buat file environment**

   ```bash
   cd etl/transform/example-python
   cat > .env <<'EOF'
   KAFKA_BROKERS=kafka:9092
   SOURCE_TOPIC=raw_orders
   TARGET_TOPIC=enriched_orders
   ETL_GROUP_ID=etl-workers
   EOF
   ```

2. **Build & jalankan**

   ```bash
   docker-compose up -d
   docker-compose logs -f kafka-transform-python
   ```

   Container me-mount `app.py` untuk hot reload, jadi edit kode langsung berdampak.

3. **Standalone (opsional)**
   ```bash
   pip install -r requirements.txt
   python app.py
   ```

---

## 5. Layer Sink · Python → MySQL

1. **Sesuaikan environment (jika diperlukan)**  
   Default di dalam `docker-compose.yml` mengarah ke `kafka:29092` dan `mysql_servers`. Override dengan mengedit bagian `environment` atau export variabel sebelum menjalankan secara lokal.

2. **Build & jalankan**

   ```bash
   cd etl/sink/example-python
   docker-compose up -d
   docker-compose logs -f kafka-sink-mysql
   ```

3. **Eksekusi lokal (opsional)**
   ```bash
   pip install -r requirements.txt
   export KAFKA_BROKERS=localhost:9092
   export MYSQL_HOST=localhost
   python app.py
   ```

---

## 6. Opsional · n8n Automation Stack

1. **Jalankan n8n + backing MySQL**

   ```bash
   cd etl/n8n
   docker-compose up -d
   ```

2. **Akses UI**  
   Buka `http://localhost:5678` dan ikuti wizard onboarding.  
   Kredensial DB default adalah `root/root` untuk container `mysql_n8n` yang disediakan—ubah untuk production.

---

## 7. Verifikasi Alur End-to-End

1. **Kirim raw order (Laravel producer API)**

   ```bash
   curl -X POST http://localhost:7001/api/orders \
     -H "Content-Type: application/json" \
     -d '{"order_id":"ORD-001","quantity":3,"unit_price":250000}'
   ```

2. **Konfirmasi output ETL**

   ```bash
   docker exec kafka kafka-console-consumer.sh \
     --bootstrap-server localhost:9092 \
     --topic enriched_orders \
     --from-beginning \
     --max-messages 1
   ```

3. **Inspeksi tabel sink MySQL**

   ```bash
   docker exec mysql_servers mysql -uroot -proot app_consumer \
     -e "SELECT id, order_id, total_value, updated_at FROM orders ORDER BY updated_at DESC LIMIT 5;"
   ```

4. **Cek consumer lag**
   ```bash
   docker exec kafka kafka-consumer-groups.sh \
     --bootstrap-server localhost:9092 \
     --group etl-workers \
     --describe
   docker exec kafka kafka-consumer-groups.sh \
     --bootstrap-server localhost:9092 \
     --group sink-mysql-workers \
     --describe
   ```

---

## 8. Troubleshooting Essentials

| Masalah                           | Solusi Cepat                                                                                                             |
| --------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| Container Maxwell keluar langsung | Pastikan source MySQL mengekspos binlog, kredensial valid, dan Kafka broker dapat dijangkau (`kafka:29092`).             |
| ETL tidak consuming pesan         | Reset offset: `kafka-consumer-groups.sh --group etl-workers --reset-offsets --to-earliest --execute --topic raw_orders`. |
| Sink tidak bisa connect ke MySQL  | Konfirmasi container `mysql_servers` ada, cek firewall, pastikan `MYSQL_HOST` sesuai dengan Docker DNS.                  |
| Baris duplikat / hilang           | Verifikasi primary key + klausa `ON DUPLICATE KEY` sesuai dengan payload; periksa log `consumer.commit` untuk error.     |
| n8n gagal healthcheck             | Tingkatkan resource Docker (2 CPU / 2 GB RAM), pastikan port `5678` bebas.                                               |

Untuk penjelasan lebih dalam lihat `sink/example-python/README.md`, `QUICKSTART.md`, dan panduan global di root project.

---

Anda sekarang siap menjalankan lab ETL PemateriKafka secara end-to-end. Selamat streaming! 🎯
