# Panduan Instalasi Servers

Dokumen ini menjelaskan langkah demi langkah untuk menyiapkan layanan `producer`, `consumer`, `consumer-daemon`, dan `mysql_servers` yang menjadi bagian aplikasi dari **Kafka ETL Training Project (PemateriKafka)**. Ikuti urutan di bawah agar setiap komponen saling terhubung dengan klaster Kafka utama.

---

## 1. Prasyarat

- **Docker** 24+ & **Docker Compose V2**
- **Network eksternal** `pelatihankafka_default` (dibuat oleh stack Kafka utama pada root repo). Jika belum ada, jalankan `docker network create pelatihankafka_default`.
- **PHP 8.1+ & Composer**, **Node.js 18+ & npm** (opsional bila ingin jalan Artisan/Front-end secara lokal di host).
- **Klaster Kafka** aktif (lihat panduan root repo pada `docker-compose.yml` utama).

---

## 2. Persiapan Repository

```bash
# Dari direktori root repo
cd servers

# Pastikan dependency Laravel sudah tersedia
composer install --working-dir=producer
composer install --working-dir=consumer
npm install --prefix producer
npm install --prefix consumer
```

> Jika direktori `vendor/` & `node_modules/` sudah ada, Anda bisa melewati tahap pemasangan dependency.

---

## 3. Konfigurasi Lingkungan

1. Duplikasi file `.env.example` di masing-masing aplikasi.
   ```bash
   cp producer/.env.example producer/.env
   cp consumer/.env.example consumer/.env
   ```
2. Sesuaikan variabel berikut (contoh nilai):
   ```env
   APP_URL=http://localhost:7001        # producer
   KAFKA_BROKERS=kafka:9092
   KAFKA_CONSUMER_GROUP_ID=order-warehouse
   DB_HOST=mysql_servers
   DB_PORT=3306
   DB_DATABASE=app_producer|app_consumer
   DB_USERNAME=root
   DB_PASSWORD=root
   ```
3. Bila menggunakan kredensial SASL/TLS Kafka, tambahkan setting `KAFKA_SASL_*` sesuai dokumentasi `mateusjunges/laravel-kafka`.

---

## 4. Build & Jalankan Layanan

```bash
# Pastikan jaringan eksternal tersedia
docker network ls | grep pelatihankafka_default || \
  docker network create pelatihankafka_default

# Build image PHP Apache dengan ekstensi rdkafka
docker compose build

# Jalankan semua layanan
docker compose up -d

# Cek status
docker compose ps
```

- Producer tersedia pada `http://localhost:7001`
- Consumer (untuk debugging HTTP) pada `http://localhost:7002`
- MySQL diekspos di `localhost:33069`

---

## 5. Migrasi Database & Seeder

Setelah kontainer naik, jalankan perintah Artisan di dalam kontainer agar skema database terbentuk.

```bash
# Migrasi + seeder untuk producer
docker compose exec producer php artisan migrate --seed

# Migrasi + seeder untuk consumer
docker compose exec consumer php artisan migrate --seed

# (Opsional) jalankan test
docker compose exec producer php artisan test
docker compose exec consumer php artisan test
```

---

## 6. Menjalankan Worker Kafka

### a. Supervisor (recommended)

`consumer-daemon` otomatis menjalankan Supervisor menggunakan konfigurasi `consumer/supervisord-conf/*.conf`.

```bash
docker compose logs -f consumer-daemon
# Atau cek status
docker compose exec consumer-daemon supervisorctl status
```

### b. Artisan Manual (opsional)

```bash
docker compose exec consumer php artisan kafka:consume enriched_orders
```

---

## 7. Pengujian End-to-End

1. **Buat topik Kafka** (jika belum tersedia).
   ```bash
   docker exec kafka /opt/kafka/bin/kafka-topics.sh \
     --create --topic raw_orders \
     --partitions 3 --replication-factor 1 \
     --bootstrap-server kafka:9092
   ```
2. **Publish pesan contoh** melalui endpoint Producer atau Artisan.
   ```bash
   docker compose exec producer php artisan orders:publish-demo
   ```
3. **Verifikasi konsumsi**.
   ```bash
   docker compose exec consumer php artisan tinker
   # Periksa record baru pada tabel target
   ```
4. **Monitor consumer group** dari sisi Kafka.
   ```bash
   docker exec kafka /opt/kafka/bin/kafka-consumer-groups.sh \
     --bootstrap-server kafka:9092 \
     --group order-warehouse --describe
   ```

---

## 8. Troubleshooting Singkat

- **Producer/Consumer tidak terhubung ke Kafka**: pastikan name service `kafka` bisa di-resolve di jaringan `pelatihankafka_default`.
- **MySQL gagal start**: cek log dengan `docker compose logs mysql_servers`, pastikan port 33069 tidak terpakai.
- **Supervisor tidak menjalankan worker**: validasi file `consumer/supervisord-conf/*.conf` sudah menunjuk ke perintah Artisan yang benar dan jalankan `supervisorctl reread && supervisorctl update`.
- **Consumer lag tinggi**: tambah replika consumer, naikkan konfigurasi `max.poll.records`, atau optimalkan fungsi handler.

---

## 9. Pembersihan

```bash
docker compose down
docker volume rm servers_mysql-data   # opsional, hapus data MySQL
```

Project kini siap digunakan untuk sesi pelatihan Kafka ETL. Lanjutkan ke README utama repositori untuk alur modul pelatihan lengkap.
