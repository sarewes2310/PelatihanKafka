# Outline Slide: Pelatihan Kafka ETL

## Slide 1 – Message Broker

- Tujuan slide: jelaskan konsep broker pesan dan manfaatnya untuk decoupling layanan.
- Analogi kantor pos: produsen mengirim paket ke broker, broker mengurutkan dan mengirim ke konsumen sesuai alamat (topic).

## Slide 2 – Arsitektur Message Broker

- Producer: Entitas yang mengirim pesan
- Broker: Platform / aplikasi yang menyimpan pesan atau topik dan mengirimkannya ke consumer.
- Consumer: Penerima pesan (subscriber) dari producer
- Topic/Queue:
  - Topic pub/sub dengan partisi untuk paralelisme.
  - Penjelasan perbedaan queue vs topic, kapan memilih salah satunya.

## Slide 3 – Implementasi Message Broker

- Pola komunikasi:
  - Pub/Sub: banyak produser, banyak konsumer, cocok untuk event distribusi (notifikasi kampus).
  - Antrean (queue): satu produser → satu konsumer untuk pekerjaan berurutan (pemrosesan laporan).
- Nilai tambah broker: buffering lalu lintas, retry otomatis, scaling horizontal, audit jejak pesan.
- Contoh kasus: pipeline pelaporan IKU, sinkronisasi data akademik antar sistem.

## Slide 4 – Message Broker For ETL

- Definisi ETL (Extract, Transform, Load) dan kenapa penting untuk analitik real-time.
- Tahap Extract: ambil data dari sumber beragam (MySQL, API kampus, file CSV, event stream) menggunakan CDC atau pooling.
- Tahap Transform: bersihkan data, normalisasi satuan, kalkulasi metrik (mis. total order value, status IKU), tambahkan metadata waktu.
- Tahap Load: simpan ke target (data warehouse MySQL, lakehouse, OLAP) dengan menjaga idempoten dan audit trail.

## Slide 5 – Apache Kafka

- Deskripsi singkat: platform streaming terdistribusi ber-throughput tinggi, menyimpan log append-only.
- Fitur kunci:
  - Partisi dan replikasi untuk ketersediaan tinggi.
  - Penyimpanan persisten

## Slide 6 – Implementasi Kafka On Laravel

- Memperkenalkan tools yang digunakan untuk menghubungkan laravel dengan kafka
- Syarat harus memiliki `rdkafka` extension

## Slide 7 – Hands-on LAB

- Link Github
