# PemateriKafka – Services Layer

Repositori `servers/` berisi semua layanan aplikasi yang mendukung pelatihan **Kafka ETL Training Project (PemateriKafka)**. Di dalamnya terdapat dua aplikasi Laravel (HTTP Producer & Worker Consumer), satu layanan Supervisor daemon untuk menjalankan consumer secara kontinu, serta instance MySQL yang menyiapkan database sumber/tujuan untuk jalur ETL.

- **Fokus**: Integrasi Laravel ↔ Kafka, orkestrasi Docker, dan persistence MySQL untuk skenario Higher Education OLAP (THE & IKU metrics).

> Panduan instalasi lengkap berada di `INSTALLATION.md`.

---

## Komponen Layanan

| Layanan           | Deskripsi                                                                                                  |
| ----------------- | ---------------------------------------------------------------------------------------------------------- |
| `producer`        | Aplikasi Laravel 10 (HTTP API) yang mem-publish pesan Kafka menggunakan `mateusjunges/laravel-kafka`.      |
| `consumer`        | Aplikasi Laravel 10 yang berperan sebagai worker/ingestor data Kafka ke MySQL target.                      |
| `consumer-daemon` | Kontainer berbasis `infraunnes/php:8.2-apache-rdkafka` yang menjalankan Supervisor untuk consumer Artisan. |
| `mysql_servers`   | MySQL 8.0 terkonfigurasi CDC-ready (binlog ROW) dengan seed `init-db.sql`.                                 |

Seluruh kontainer berbagi jaringan eksternal `pelatihankafka_default` agar dapat terhubung dengan klaster Kafka pusat (didefinisikan di root repo).

---

## Struktur Direktori

```
servers/
├── Dockerfile                # Basis image PHP 8.2 + Apache + rdkafka
├── docker-compose.yml        # Orkestrasi layanan producer, consumer, daemon, dan MySQL
├── init-db.sql               # Inisialisasi database app_producer & app_consumer
├── producer/                 # Aplikasi Laravel HTTP Producer
│   ├── app/, routes/, config/, ... (kode Laravel)
│   └── apache-default.conf   # Virtual host Apache
└── consumer/                 # Aplikasi Laravel Kafka Consumer
    ├── app/, routes/, config/, supervisord-*.conf
    └── worker configs        # Supervisor program untuk Artisan consumer
```

---

## Fitur Pembelajaran Utama

- **Message Broker Fundamentals**: konsep pub/sub, delivery semantics, dan decoupling.
- **Kafka Deep Dive**: topik, partisi, offset, consumer group, serta mode KRaft.
- **Laravel-Kafka Integration**: publishing dan konsumsi event menggunakan `mateusjunges/laravel-kafka`.
- **ETL Streaming**: bagaimana Python worker memperkaya payload sebelum diserap oleh Laravel consumer.
- **CDC dengan Maxwell**: replikasi realtime dari MySQL sumber ke Kafka topic bertema domain.
- **Operasionalisasi**: penggunaan Docker Compose, Supervisor, dan healthcheck MySQL sebagai simulasi produksi.

Referensi teori dan materi presentasi tambahan tersedia pada berkas berikut di level repo yang sama:

- `KAFKA_ETL_GUIDE.md` – tutorial langkah demi langkah pembangunan ETL.
- `kafka-olap-guide.md` – best-practice Kafka untuk kebutuhan OLAP (Bahasa Indonesia).
- `OUTLINES.md`, `PROMPTS.md`, `GEMINI.md` – materi pembelajaran & prompt engineering.

---

## Cara Menggunakan

1. Ikuti panduan instalasi pada `INSTALLATION.md` untuk membangun image, menjalankan kontainer, dan menyiapkan `.env`.
2. Gunakan endpoint Laravel Producer untuk membuat event (mis. order e-commerce).
3. Jalankan worker Consumer melalui `consumer-daemon` atau Artisan CLI untuk memproses event.
4. Monitoring: gunakan `docker-compose logs`, `kafka-consumer-groups.sh`, dan log Laravel (`storage/logs/laravel.log`).

### Contoh Endpoint & Artisan Command

- **Publish Order (Producer HTTP API)**
  ```bash
  curl -X POST http://localhost:7001/api/orders \
    -H "Content-Type: application/json" \
    -d '{
      "order_id": "ORD-1001",
      "sku": "SKU-ABC",
      "quantity": 2,
      "unit_price": 150000
    }'
  ```
- **Publish Demo Payload via Artisan (Producer)**
  ```bash
  docker compose exec producer php artisan orders:publish-demo
  ```
- **Konsumsi Topic Secara Manual (Consumer)**
  ```bash
  docker compose exec consumer php artisan kafka:consume enriched_orders
  ```

---

## Lisensi & Kontribusi

Project ini bersifat edukasional. Komponen mengikuti lisensi masing-masing (Apache 2.0 untuk Kafka & Maxwell, MIT untuk Laravel, dsb.). Saran perbaikan dipersilakan—lihat bagian _Contributing_ di `GEMINI.md` untuk ide kontribusi lanjutan.
