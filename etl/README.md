# PemateriKafka • Komponen ETL

Aset ETL edukatif yang menyertai **Proyek Pelatihan Kafka ETL (PemateriKafka)**.  
Materi ini ditargetkan untuk web developer (pengalaman 1–2 tahun) yang sedang mempelajari cara membangun pipeline Kafka end-to-end yang menghubungkan layanan Laravel, stream CDC, worker ETL Python, dan otomasi low-code opsional.

---

## Konten

```
etl/
├── extract/
│   └── example-maxwell/      # Daemon Maxwell yang mengubah event binlog MySQL menjadi topic Kafka
├── transform/
│   └── example-python/       # Job streaming Python yang memperkaya event Kafka mentah
├── sink/
│   └── example-python/       # Sink Python yang menyimpan event yang diperkaya ke MySQL
└── n8n/                      # Stack otomasi workflow n8n opsional (berbasis MySQL)
```

### Gambaran Komponen

| Layer      | Path                       | Teknologi Utama          | Peran dalam Pipeline                                                            |
| ---------- | -------------------------- | ------------------------ | ------------------------------------------------------------------------------- |
| Extract    | `extract/example-maxwell`  | Maxwell + MySQL          | Membaca binlog MySQL, mempublikasi event CDC ke Kafka (topic `app_producer.*`). |
| Transform  | `transform/example-python` | Python + confluent-kafka | Mengonsumsi `raw_orders`, memperkaya payload, memproduksi `enriched_orders`.    |
| Sink       | `sink/example-python`      | Python + MySQL           | Mengonsumsi `enriched_orders`, melakukan upsert ke `app_consumer.orders`.       |
| Automation | `n8n/docker-compose.yml`   | n8n + MySQL              | Canvas workflow opsional untuk demo/ops tooling pada network Kafka yang sama.   |

Semua layanan bergabung dengan Docker network bersama `pelatihankafka_default`, yang dibuat oleh `docker-compose.yml` di level root.

---

## Arsitektur Referensi

```
MySQL (source)
    │  Binary Log
    ▼
Maxwell CDC  ──▶  Kafka (raw topics)  ──▶  Python ETL  ──▶  Kafka (enriched topics)
                                                           │
                                                           ▼
                                               Python Sink / Laravel Consumers
                                                           │
                                                           ▼
                                                    MySQL (target OLAP)
```

**Tema pembelajaran yang tercakup di sini**

1. Fundamental message broker & semantik delivery (at-least-once, batching, partitions).
2. Operasi Apache Kafka dalam mode KRaft (topics, consumer groups, offsets, lag).
3. Handoff Laravel ↔ Kafka (producers & queue workers) melalui stack `servers/` upstream.
4. Pola desain ETL Python (stateful transforms, dead-lettering, data enrichment).
5. CDC via Maxwell + binlog MySQL untuk replikasi real-time ke skema warehouse-friendly.
6. Higienitas production: layanan Docker, worker Supervisord, hook monitoring, low-code tooling.

---

## Sorotan File per File

- `extract/example-maxwell/docker-compose.yml`  
  Menjalankan Maxwell plus metadata MySQL khusus. `config.properties` merutekan hanya tabel `app_producer.*` ke Kafka (`kafka:29092`) dan memfilter skema sistem.

- `transform/example-python/app.py`  
  Job streaming Python minimal yang mendengarkan `raw_orders`, memberi stempel metadata (`flag`, `sync_at`), dan meneruskan payload yang diperkaya ke `TARGET_TOPIC`. Menggunakan `confluent-kafka==2.4.0` dan konfigurasi berbasis dotenv.

- `sink/example-python/app.py`  
  Mengimplementasikan sink Kafka → MySQL yang resilient: commit manual, connector retry, semantik UPSERT pada `MYSQL_TABLE`, dan handler shutdown graceful untuk menghindari kehilangan offset.  
  File pendamping (`Dockerfile`, `requirements.txt`, `docker-compose.yml`, `QUICKSTART.md`, `README.md` bersarang) mendokumentasikan deep dive, tuning knobs, dan alur demo.

- `n8n/docker-compose.yml`  
  Deployment n8n production-ready yang terikat dengan penyimpanan MySQL, metrik Prometheus, dan timezone Asia/Jakarta—berguna untuk mendemonstrasikan workflow low-code yang bereaksi terhadap topic Kafka atau memicu replay CDC.

---

## Bagaimana Bagian-Bagian Ini Cocok dengan Proyek Utama

1. **Laravel Producer (`servers/producer`)** mempublikasikan event order mentah ke `raw_orders`.
2. **Python Transform (`etl/transform/example-python`)** memperkaya event tersebut dan mempublikasikan ulang ke `enriched_orders`.
3. **Laravel Consumer (`servers/consumer`)** dan/atau **Python Sink (`etl/sink/example-python`)** menyimpan data di `app_consumer.orders`.
4. **Maxwell (`etl/extract/example-maxwell`)** dapat mengambil perubahan dari skema MySQL apa pun (misalnya, SIAKAD, HR, database riset) dan menyalurkannya ke Kafka untuk ETL downstream.
5. **n8n (`etl/n8n`)** adalah opsional untuk visualisasi, peringatan, atau otomasi back-office yang dibangun di atas dataset yang sama.

Materi pelatihan upstream memandu Anda melalui tahapan ini selama Modul 3–5 dari kurikulum.

---

## Cuplikan Penggunaan Cepat

> **Instalasi lengkap & setup environment didokumentasikan di `INSTALLATION.md`.**  
> Perintah di bawah mengasumsikan Anda berada di root project (`PelatihanKafka/`) dan Docker Desktop 24+ dengan Compose V2 tersedia.

1. **Jalankan Python ETL**

   ```bash
   cd etl/transform/example-python
   cp .env.example .env   # buat dari template (lihat INSTALLATION.md)
   docker-compose up -d
   docker-compose logs -f kafka-transform-python
   ```

2. **Jalankan sink (MySQL writer)**

   ```bash
   cd etl/sink/example-python
   docker-compose up -d
   docker-compose logs -f kafka-sink-mysql
   ```

3. **Jalankan Maxwell CDC (opsional)**

   ```bash
   cd etl/extract/example-maxwell
   docker-compose up -d
   ```

4. **Luncurkan stack otomasi n8n (opsional)**
   ```bash
   cd etl/n8n
   docker-compose up -d
   open http://localhost:5678
   ```

---

## Monitoring & Validasi

- **Kesehatan Kafka**  
  `docker exec kafka /opt/kafka/bin/kafka-topics.sh --bootstrap-server localhost:9092 --list`

- **Consumer lag**  
  `docker exec kafka kafka-consumer-groups.sh --bootstrap-server localhost:9092 --group etl-workers --describe`

- **Pemeriksaan MySQL (CDC atau target sink)**  
  `docker exec mysql_servers mysql -uroot -proot -e "SHOW BINARY LOGS; SELECT COUNT(*) FROM app_consumer.orders;" `

- **Worker Laravel**  
  `docker exec consumer-daemon supervisorctl status`

---

## Cheat-Sheet Troubleshooting

| Gejala                                     | Coba Ini                                                                                                   |
| ------------------------------------------ | ---------------------------------------------------------------------------------------------------------- |
| Consumer menerima 0 pesan                  | Konfirmasi topic ada + memiliki data, reset offset dengan `kafka-consumer-groups.sh --reset`.              |
| Producer timeout / broker tidak terjangkau | Verifikasi log container `kafka`, pastikan network `pelatihankafka_default` berjalan.                      |
| Maxwell tidak mempublikasi CDC             | Cek `docker logs maxwell_cdc`, pastikan binlog diaktifkan (`SHOW BINARY LOGS`).                            |
| Sink duplikat / baris hilang               | Inspeksi constraint MySQL, pastikan `ON DUPLICATE KEY` sesuai natural key, review offset.                  |
| Transform gagal pada bentuk payload        | Reproduksi pesan via `kafka-console-consumer.sh`, sesuaikan logika `enrich()` atau tambahkan schema guard. |

Solusi lebih lanjut ada di `sink/example-python/README.md` dan panduan seluruh project.
