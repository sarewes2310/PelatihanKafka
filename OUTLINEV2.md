# Outline Slide: Pelatihan Kafka ETL

## Slide 1 – Pengantar ETL

- Definisi ETL (Extract, Transform, Load) dan kenapa penting untuk analitik real-time.
- Tahap Extract: ambil data dari sumber beragam (MySQL, API kampus, file CSV, event stream) menggunakan CDC atau pooling.
- Tahap Transform: bersihkan data, normalisasi satuan, kalkulasi metrik (mis. total order value, status IKU), tambahkan metadata waktu.
- Tahap Load: simpan ke target (data warehouse MySQL, lakehouse, OLAP) dengan menjaga idempoten dan audit trail.
- Alur contoh: Source DB → Maxwell CDC → Kafka topic `raw_orders` → Python ETL → Kafka topic `enriched_orders` → Laravel consumer → MySQL target warehouse.
- Soroti tools utama: Docker Compose, Laravel Kafka, Python `confluent-kafka`, Maxwell.

## Slide 2 – Message Broker

- Tujuan slide: jelaskan konsep broker pesan dan manfaatnya untuk decoupling layanan.
- Analogi kantor pos: produsen mengirim paket ke broker, broker mengurutkan dan mengirim ke konsumen sesuai alamat (topic).
- Pola komunikasi:
  - Pub/Sub: banyak produser, banyak konsumer, cocok untuk event distribusi (notifikasi kampus).
  - Antrean (queue): satu produser → satu konsumer untuk pekerjaan berurutan (pemrosesan laporan).
- Nilai tambah broker: buffering lalu lintas, retry otomatis, scaling horizontal, audit jejak pesan.
- Contoh kasus: layanan order e-commerce, pipeline pelaporan IKU, sinkronisasi data akademik antar sistem.

## Slide 3 – Komponen Inti Broker Pesan

- Producer: aplikasi Laravel API mengirim event (order, aktivitas mahasiswa) ke topic tertentu; jelaskan payload JSON + header korlasi.
- Consumer: worker Laravel/Python membaca pesan, memvalidasi, menyimpan ke DB, dan mengirim event lanjutan.
- Topic/Queue:
  - Topic pub/sub dengan partisi untuk paralelisme.
  - Penjelasan perbedaan queue vs topic, kapan memilih salah satunya.
- Broker:
  - Menyimpan log pesan, menjaga offset, menangani replikasi dan durability.
  - Tanggung jawab cluster: balancing partisi, failover node.
- Aspek pendukung: consumer group (untuk scaling), offset (checkpoint), retention (TTL pesan).

## Slide 4 – Apache Kafka

- Deskripsi singkat: platform streaming terdistribusi ber-throughput tinggi, menyimpan log append-only.
- Fitur kunci:
  - Partisi dan replikasi untuk ketersediaan tinggi.
  - Penyimpanan persisten sehingga pesan bisa diputar ulang (time-travel).
  - Dukungan KRaft mode (tanpa ZooKeeper) untuk setup modern.
- Alasan memilih Kafka untuk training:
  - Ekosistem luas (Connect, Streams, integrasi Laravel/Python).
  - Cocok untuk ETL event-driven dan CDC kampus.
  - Mendukung idempotent producer serta exactly-once semantics.
- Highlight tooling yang digunakan di repo: `mateusjunges/laravel-kafka`, ekstensi `rdkafka`, Python `confluent-kafka`, Maxwell daemon.

## Slide 5 – Arsitektur Kafka di Proyek

- Tampilkan diagram alur lengkap: Laravel Procedur → Kafka broker (topic) → Kafka topic enriched → Laravel consumer.
- Jelaskan bagaimana topic dibagi:
  - `raw_orders` untuk event mentah.
  - `enriched_orders` untuk data hasil transformasi.
  - Topic CDC `producer.<db>.<table>` untuk replika OLAP.
- Komponen infrastruktur:
  - Docker Compose menjalankan Kafka (KRaft), Zookeeper-free.
  - Network internal `pelatihankafka_default` menghubungkan semua kontainer.

## Slide 6 – Hands-on LAB

- Link Github
