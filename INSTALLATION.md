# INSTALLATION – PemateriKafka

Panduan ini menjelaskan cara menyiapkan seluruh stack **Kafka ETL Training Project (PelatihanKafka)**: broker Kafka, layanan Laravel (producer & consumer), pipeline ETL Python, Maxwell CDC, serta n8n opsional. Ikuti langkah berurutan untuk memastikan setiap komponen dapat terhubung melalui jaringan Docker `pelatihankafka_default`.

---

## 0. Prasyarat

### Perangkat Lunak

- **Docker Desktop 4.26+ / Docker Engine 24+** dengan Compose V2 (`docker compose`).
- **Docker Compose plugin** (bawaan Docker Desktop/Engine).
- **PHP 8.1+ & Composer** (jika ingin menjalankan Artisan/Composer dari host).
- **Python 3.9+ & pip** (opsional untuk menjalankan worker di luar container).
- **Node.js 18+ & npm** (opsional untuk compile asset Laravel).

### Perangkat Keras

- CPU 4 core, RAM ≥ 8 GB, storage kosong ≥ 20 GB (agar kontainer Kafka/MySQL stabil).

---

## 1. Clone Repository & Masuk Direktori

```bash
git clone https://github.com/<org>/PemateriKafka.git
cd PemateriKafka
```

> Semua perintah berikut diasumsikan dijalankan dari direktori root repo kecuali disebutkan lain.

---

## 2. Gambaran Struktur & Jaringan

- Root `docker-compose.yml` otomatis membuat jaringan bridge bernama `pelatihankafka_default`.  
  Seluruh layanan lain (Laravel, Maxwell, ETL) akan join ke jaringan ini sehingga saling terhubung.
- Port penting:
  - Kafka broker PLAINTEXT eksternal: `localhost:9092` (internal `kafka:29092`)
  - Kafka controller: `9093`
  - Kafka UI: `http://localhost:8080`
  - Laravel Producer: `http://localhost:7001`
  - Laravel Consumer (for demo UI/log): `http://localhost:7002`
  - MySQL (apps): `localhost:33069`
  - n8n (opsional): `http://localhost:5678`

---

## 3. Konfigurasi Environment Laravel

1. Salin berkas `.env`:

   ```bash
   cp servers/producer/.env.example servers/producer/.env
   cp servers/consumer/.env.example servers/consumer/.env
   ```

2. Buka masing-masing `.env` dan ubah nilai penting berikut:

| Variabel                   | Nilai yang Disarankan                                     | Keterangan                                                   |
| -------------------------- | --------------------------------------------------------- | ------------------------------------------------------------ |
| `APP_URL`                  | `http://producer` / `http://consumer` atau localhost port | Untuk link internal.                                         |
| `DB_HOST`                  | `mysql_servers`                                           | Host MySQL pada jaringan Docker.                             |
| `DB_PORT`                  | `3306`                                                    | Port internal kontainer MySQL.                               |
| `DB_DATABASE`              | `app_producer` (producer) / `app_consumer` (consumer)     | Sudah dibuat via `init-db.sql`.                              |
| `DB_USERNAME` / `PASSWORD` | `root` / `root`                                           | Kredensial default MySQL demo.                               |
| `QUEUE_CONNECTION`         | `sync` atau `database`                                    | Sesuaikan kebutuhan demo (worker Artisan berjalan terpisah). |
| `KAFKA_BROKERS`            | `kafka:29092` (intra-network)                             | Gunakan `localhost:9092` jika menjalankan langsung di host.  |
| `KAFKA_CONSUMER_GROUP_ID`  | mis. `order-warehouse`                                    | Untuk consumer Laravel.                                      |
| `KAFKA_OFFSET_RESET`       | `earliest`                                                | Memastikan group baru membaca dari awal.                     |

3. Setelah kontainer berjalan (langkah 6), jalankan:

```bash
cd servers
docker compose exec producer php artisan key:generate
docker compose exec consumer php artisan key:generate
```

> Bila perlu jalankan `composer install` atau `npm install && npm run build` di masing-masing folder sebelum build container.

---

## 4. Konfigurasi Environment Python ETL

Setiap worker memiliki templat `.env.example`. Salin lalu sesuaikan:

```bash
cp etl/transform/example-python/.env.example etl/transform/example-python/.env
cp etl/sink/example-python/.env.example etl/sink/example-python/.env
```

Ubah nilai berikut jika topik/DB menyesuaikan:

| Worker                                     | Variabel        | Default                                                 | Catatan                     |
| ------------------------------------------ | --------------- | ------------------------------------------------------- | --------------------------- |
| Transform (`etl/transform/example-python`) | `KAFKA_BROKERS` | `kafka:29092`                                           | Gunakan alamat internal.    |
|                                            | `SOURCE_TOPIC`  | `pelatihan_kafka.producer.mahasiswa`                    | Topic CDC/producer awal.    |
|                                            | `TARGET_TOPIC`  | `pelatihan_kafka.producer.mahasiswa.transform`          | Topic hasil enrichment.     |
|                                            | `ETL_GROUP_ID`  | `etl-transform-mahasiswa-py`                            | Consumer group ID.          |
| Sink (`etl/sink/example-python`)           | `SOURCE_TOPIC`  | `enriched_orders`                                       | Topic yang dibaca worker.   |
|                                            | `MYSQL_*`       | Host `mysql_servers`, db `app_consumer`, table `orders` | Sesuaikan jika target lain. |

---

## 5. Jalankan Broker Kafka (Root)

1. Dari root repo:

   ```bash
   docker compose up -d
   ```

   Ini menjalankan kontainer:

   - `kafka` (Confluent 7.6.1, mode KRaft)
   - `kafka-ui` (port 8080)

2. Verifikasi:

   ```bash
   docker compose ps
   docker exec kafka kafka-topics.sh --bootstrap-server localhost:9092 --list
   # buka http://localhost:8080 di browser untuk Kafka UI
   ```

   Pastikan status `healthy` pada `kafka` sebelum melanjutkan (Compose healthcheck sudah diaktifkan).

---

## 6. Jalankan Layanan Aplikasi (Laravel + MySQL)

1. Masuk ke direktori `servers`:

   ```bash
   cd servers
   docker compose up -d --build
   ```

2. Kontainer yang berjalan: `producer`, `consumer`, `consumer-daemon` (Supervisor), dan `mysql_servers`.

3. Verifikasi MySQL siap:

   ```bash
   docker compose exec mysql_servers mysql -uroot -proot -e "SHOW DATABASES;"
   ```

   Database `app_producer` dan `app_consumer` otomatis dibuat oleh `init-db.sql`.

4. Generate APP_KEY & jalankan migrasi jika dibutuhkan:

   ```bash
   docker compose exec producer php artisan key:generate
   docker compose exec consumer php artisan key:generate

   docker compose exec producer php artisan migrate --seed   # opsional
   docker compose exec consumer php artisan migrate          # opsional
   ```

5. (Opsional) Jalankan scheduler/queue tambahan melalui `consumer-daemon` atau Artisan manual:

   ```bash
   docker compose exec consumer php artisan kafka:consume enriched_orders
   ```

---

## 7. Jalankan Worker ETL Python

### Transform Job

```bash
cd etl/transform/example-python
docker compose up -d     # membangun image dan menjalankan kafka-transform-python
docker compose logs -f kafka-transform-python
```

### Sink Job

```bash
cd etl/sink/example-python
docker compose up -d
docker compose logs -f kafka-sink-mysql
```

Keduanya otomatis bergabung dengan jaringan `pelatihankafka_default`. Pastikan variabel `.env` sudah menunjuk ke topic & DB yang benar sebelum start.

---

## 8. Jalankan Maxwell CDC (Opsional, Extract Layer)

```bash
cd etl/extract/example-maxwell
docker compose up -d
docker compose logs -f maxwell_cdc
```

- `config.properties` sudah menyiapkan filter `app_producer.*` dan koneksi ke `mysql_servers`.
- Pastikan MySQL sumber mengaktifkan binlog (sudah dilakukan melalui flag pada `mysql_servers`).
- Endpoint health Maxwell tersedia di `http://localhost:8083/healthcheck`.

---

## 9. Jalankan n8n (Opsional)

```bash
cd etl/n8n
docker compose up -d
```

- UI n8n berada di `http://localhost:5678` (login pertama akan diminta membuat akun).
- Database n8n tersimpan di kontainer `mysql_n8n` dalam jaringan yang sama sehingga workflow dapat mengakses Kafka/MySQL secara langsung.

---

## 10. Pengujian Manual Alur Orders

1. **Publish order via Producer API**:

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

2. **Pantau worker**:

   ```bash
   docker compose -f servers/docker-compose.yml logs -f consumer
   docker compose -f etl/transform/example-python/docker-compose.yml logs -f
   ```

3. **Validasi data di MySQL**:

   ```bash
   docker compose -f servers/docker-compose.yml exec mysql_servers \
     mysql -uroot -proot -e "SELECT * FROM app_consumer.orders ORDER BY id DESC LIMIT 5\G"
   ```

4. **Cek offset/lag**:

   ```bash
   docker exec kafka kafka-consumer-groups.sh \
     --bootstrap-server localhost:9092 \
     --group etl-workers \
     --describe
   ```

---

## 11. Monitoring & Troubleshooting

| Use Case              | Perintah                                                                                                                                                               |
| --------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Daftar topic          | `docker exec kafka kafka-topics.sh --bootstrap-server localhost:9092 --list`                                                                                           |
| Reset offset consumer | `docker exec kafka kafka-consumer-groups.sh --bootstrap-server localhost:9092 --group order-warehouse --reset-offsets --to-earliest --execute --topic enriched_orders` |
| Status Supervisor     | `docker compose -f servers/docker-compose.yml exec consumer-daemon supervisorctl status`                                                                               |
| Cek log Kafka         | `docker logs kafka`                                                                                                                                                    |
| Cek binlog MySQL      | `docker compose -f servers/docker-compose.yml exec mysql_servers mysql -uroot -proot -e "SHOW BINARY LOGS;"`                                                           |
| Health Maxwell        | `curl http://localhost:8083/healthcheck`                                                                                                                               |
| Kafka UI              | Buka `http://localhost:8080`                                                                                                                                           |

**Masalah umum & solusi singkat**

- **Consumer tidak menerima pesan**: pastikan `KAFKA_OFFSET_RESET=earliest`, topic berisi data (`kafka-console-consumer.sh --from-beginning`), dan group belum memakan offset lama.
- **Producer timeout**: cek koneksi ke `kafka:29092`, lihat `docker logs kafka`, serta pastikan `pelatihankafka_default` ada (`docker network ls`).
- **Maxwell tidak publish**: verifikasi binlog aktif, kredensial `replication_user`, dan filter `config.properties`.
- **Lag tinggi**: tambahkan instance consumer/ETL, tingkatkan `max.poll.records`, atau pecah topic menjadi partisi lebih banyak.

---

## 12. Membersihkan Lingkungan

```bash
# Hentikan stack ETL opsional
docker compose -f etl/transform/example-python/docker-compose.yml down
docker compose -f etl/sink/example-python/docker-compose.yml down
docker compose -f etl/extract/example-maxwell/docker-compose.yml down
docker compose -f etl/n8n/docker-compose.yml down

# Hentikan layanan aplikasi
docker compose -f servers/docker-compose.yml down -v

# Hentikan broker utama
docker compose down -v
```

Jika ingin mengulang dari awal, hapus volume (`docker volume rm`) sesuai kebutuhan (`mysql-data`, `kafka-data`, dll.).
