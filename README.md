# PemateriKafka – Kafka ETL Training Project

Repositori ini menyatukan contoh arsitektur **event-driven ETL** berbasis **Apache Kafka**, dua aplikasi **Laravel 10** (producer & consumer), worker **Python** untuk transformasi, serta integrasi **CDC** lewat Maxwell. Materi ditujukan untuk web developer (1–2 tahun pengalaman) yang ingin memahami pola data engineering di ranah Higher Education OLAP (THE & IKU metrics).

## Ringkasan Proyek

- **Stack utama**: Kafka (KRaft mode), Laravel + mateusjunges/laravel-kafka, Python + confluent-kafka, MySQL 8 CDC-ready, Docker Compose.
- **Fokus pembelajaran**: message broker fundamentals, integrasi Laravel ↔ Kafka, ETL streaming Python, CDC Maxwell, dan hardening produksi (Supervisor, monitoring, tuning).
- **Output**: peserta dapat membangun pipeline order-processing/OLAP end-to-end, mulai dari sumber MySQL → Kafka → Python ETL → Laravel/MySQL target.

## Tujuan Pembelajaran

1. Menjelaskan konsep message broker, pub/sub vs queue, dan delivery semantics.
2. Memahami arsitektur Kafka (topics, partitions, offsets, consumer group, KRaft).
3. Menghubungkan aplikasi Laravel ke Kafka sebagai producer maupun consumer.
4. Membangun worker ETL Python untuk melakukan enrichment & routing event.
5. Mengoperasikan CDC via Maxwell (MySQL binlog) sebagai sumber data real-time.
6. Men-deploy stack berbasis Docker dengan praktik produksi (monitoring, tuning, security checklist).

## Arsitektur & Alur Data

```
┌─────────────┐      ┌──────────────┐      ┌─────────────┐      ┌──────────────┐
│   MySQL     │──────▶│   Maxwell    │──────▶│    Kafka    │──────▶│  Python ETL  │
│  (Source)   │ CDC   │  (CDC Tool)  │ CDC   │  (Broker)   │      │  (Transform) │
└─────────────┘       └──────────────┘ Topic └─────────────┘      └──────────────┘
                                                    │                      │
                                                    │                      │
                                                    ▼                      ▼
                                             ┌─────────────┐      ┌──────────────┐
                                             │  Producer   │      │  Consumer    │
                                             │  (Laravel)  │      │  (Laravel)   │
                                             └─────────────┘      └──────────────┘
                                                    │                      │
                                                    └──────────────────────┘
                                                            │
                                                            ▼
                                                    ┌──────────────┐
                                                    │  MySQL       │
                                                    │  (Target)    │
                                                    └──────────────┘
```

**Alur singkat**

1. MySQL sumber menulis perubahan ke binary log.
2. Maxwell membaca binlog dan menyalurkan event CDC ke topic bertema domain (`app_producer.*`).
3. Python ETL (manualpy / example-python) memperkaya payload (`raw_orders` → `enriched_orders`).
4. Laravel Producer menambah event bisnis yang berasal dari HTTP API.
5. Laravel Consumer / Python Sink menyimpan data ke MySQL target (`app_consumer`).
6. Opsional: n8n menjalankan workflow otomatis, Kafka UI (8080) mengecek topik, Supervisor menjaga worker.

## Struktur Repositori

```
PemateriKafka/
├── docker-compose.yml         # Single-node Kafka + Kafka UI (jaringan pelatihankafka_default)
├── servers/                   # Layer aplikasi Laravel + MySQL + Supervisor
│   ├── docker-compose.yml     # Producer, consumer, consumer-daemon, mysql_servers
│   ├── producer/              # Laravel HTTP API (publish raw_orders)
│   └── consumer/              # Laravel worker/ingestor (consume enriched_orders)
├── etl/                       # Komponen ETL (extract, transform, sink, n8n)
│   ├── extract/example-maxwell/   # Maxwell CDC + MySQL metadata
│   ├── transform/example-python/  # Worker Python enrichment
│   ├── sink/example-python/       # Worker Python sink → MySQL
│   └── n8n/                       # Low-code automation stack
├── GEMINI.md, KAFKA_ETL_GUIDE.md, kafka-olap-guide.md
└── OUTLINES.md, PROMPTS.md, OUTLINEV2.md, AGENTS.md
```

## Matriks Komponen

| Layer / Layanan          | Path                              | Teknologi Kunci                     | Peran Utama                                                         |
| ------------------------ | --------------------------------- | ----------------------------------- | ------------------------------------------------------------------- |
| Kafka Core               | `docker-compose.yml`              | Kafka 7.6.1 (KRaft), Kafka UI       | Broker pusat, topic management, monitoring UI (8080).              |
| Laravel Producer         | `servers/producer`                | Laravel 10 + mateusjunges/laravel-kafka | API HTTP (port 7001) untuk publish event ke `raw_orders`.          |
| Laravel Consumer         | `servers/consumer` & `consumer-daemon` | Laravel 10, Supervisor, rdkafka    | Worker (port 7002) untuk konsumsi/persist event `enriched_orders`. |
| MySQL (apps)             | `servers/mysql_servers`           | MySQL 8 CDC ready                   | Database sumber/target (app_producer & app_consumer).              |
| Maxwell CDC              | `etl/extract/example-maxwell`     | Maxwell daemon + MySQL metadata     | CDC MySQL → Kafka topic bertema domain.                            |
| Python ETL Transform     | `etl/transform/example-python`    | Python 3.9, confluent-kafka         | Enrichment payload, republikasikan topic lanjutan.                 |
| Python ETL Sink          | `etl/sink/example-python`         | Python 3.9, MySQL connector         | Commit manual offset, UPSERT data ke `app_consumer.orders`.        |
| n8n Automation (opsional)| `etl/n8n`                         | n8n, MySQL                          | Workflow otomatis (alerting, ops tooling) di jaringan yang sama.   |

## Modul Pelatihan (12 Jam Total)

| Modul | Durasi | Fokus Utama                                                                 |
| ----- | ------ | ---------------------------------------------------------------------------- |
| 1. Message Broker Fundamentals | 1 jam  | Konsep broker, pub/sub vs queue, delivery semantics. |
| 2. Apache Kafka Basics         | 2 jam  | Arsitektur, topics, partitions, offsets, KRaft setup. |
| 3. Laravel Integration         | 3 jam  | Instalasi rdkafka, HTTP producer, worker consumer, error handling. |
| 4. ETL Pipeline Development    | 2 jam  | Python Kafka clients, enrichment, dead-letter, lag monitoring. |
| 5. CDC with Maxwell            | 2 jam  | Binlog replication, filtering, schema change handling. |
| 6. Production Deployment       | 2 jam  | Supervisor, Docker orchestration, monitoring, tuning, security. |

Setiap modul memiliki slide/catatan di `OUTLINES.md`, `kafka-olap-guide.md`, dan tutorial lengkap pada `KAFKA_ETL_GUIDE.md`.

## Skenario Praktikum Utama

- **E-Commerce Order Processing**  
  - Producer API menerima order dan mem-publish ke `raw_orders` dengan header `correlation_id`.  
  - Python ETL menghitung `total_value`, menambah timestamp `processed_at`, dan menaruh ke `enriched_orders`.  
  - Laravel Consumer atau Python Sink menyimpan ke `app_consumer.orders` sambil mencatat log ke `storage/logs/laravel.log`.

- **Higher-Education OLAP Metrics (THE & IKU)**  
  - Maxwell menyaring skema domain (`siakad.*`, `sdm.*`, dsb.) lalu menulis ke topic `siakad.mahasiswa`.  
  - Transform job meng-agregasi metrik harian dan mengirim ke topic analitik atau MySQL warehouse.  
  - n8n atau dashboard eksternal membaca data untuk reporting real-time.

## Dokumentasi & Referensi

- `KAFKA_ETL_GUIDE.md` – tutorial langkah demi langkah membuat pipeline.  
- `kafka-olap-guide.md` – strategi Kafka untuk OLAP (Bahasa Indonesia).  
- `GEMINI.md` – dokumentasi lengkap proyek + flow pelatihan.  
- `OUTLINES.md`, `OUTLINEV2.md`, `PROMPTS.md` – bahan presentasi & contoh prompt AI.  
- `servers/README.md` & `etl/README.md` – detail masing-masing workspace.  
- `INSTALLATION.md` – panduan instalasi menyeluruh (lihat bagian berikutnya).

## Cara Memulai Singkat

1. **Penuhi prasyarat**: Docker Desktop 4.26+/Engine 24+, Compose V2, PHP 8.1+, Python 3.9+, Node.js 18+ (opsional).  
2. **Clone repo & siapkan environment** (`cp servers/producer/.env.example servers/producer/.env`, generate `APP_KEY`).  
3. **Jalankan broker Kafka** dari root: `docker compose up -d` lalu cek `http://localhost:8080`.  
4. **Boot layanan aplikasi**: `cd servers && docker compose up -d --build`, pastikan `mysql_servers` sehat.  
5. **Aktifkan ETL**: jalankan container `etl/transform/example-python` dan `etl/sink/example-python`.  
6. **Opsional**: aktifkan Maxwell (`etl/extract/example-maxwell`) dan n8n (`etl/n8n`).  
7. **Uji alur**: kirim order via producer API (`http://localhost:7001/api/orders`) atau Artisan command, lalu pantau consumer log.

Detail lengkap setiap langkah, variabel lingkungan, serta verifikasi tersedia di `INSTALLATION.md`.

## Monitoring & Troubleshooting Cepat

- Daftar topic: `docker exec kafka kafka-topics.sh --bootstrap-server localhost:9092 --list`.  
- Cek lag consumer: `docker exec kafka kafka-consumer-groups.sh --bootstrap-server localhost:9092 --group etl-workers --describe`.  
- Health MySQL: `docker exec mysql_servers mysql -uroot -proot -e "SHOW BINARY LOGS; SELECT COUNT(*) FROM app_consumer.orders;"`.  
- Supervisor consumer: `docker exec consumer-daemon supervisorctl status`.  
- Jika producer timeout, cek `docker logs kafka` dan konektivitas jaringan `pelatihankafka_default`.

## Lisensi & Kontribusi

Proyek bersifat edukasional; setiap komponen mengikuti lisensi masing-masing (Apache 2.0 untuk Kafka & Maxwell, MIT untuk Laravel, dsb.). Kontribusi berupa penambahan use case, integrasi bahasa lain, atau tooling monitoring dipersilakan via issue/PR. Lihat daftar ide pada bagian _Contributing_ di `GEMINI.md`.
