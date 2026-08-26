# DokterReminder — PHP Native

MVP aplikasi reminder jadwal praktik dokter via WhatsApp Direct Message.

## Menjalankan

```bash
php -S 127.0.0.1:8000 -t .
```

Buka `http://127.0.0.1:8000`.

Database menggunakan MariaDB/MySQL lokal melalui PDO. Pengaturan default: host `127.0.0.1`, port `3306`, database `dokter_reminder`, user `root`, password kosong. Ubah nilainya di `config.php` bila perlu.

Buat database terlebih dahulu dengan mengimpor [database/schema.sql](database/schema.sql) melalui phpMyAdmin atau command line. Setelah itu data contoh akan tersedia dan dashboard bisa langsung dicoba.

Pastikan ekstensi PHP `pdo_mysql` aktif.

## Scheduler

Tambahkan cron setiap 5 menit:

```bash
*/5 * * * * php /path/ke/dokter-reminder/cron/reminder.php
```

MVP memakai `wa.me`; server tidak mengirim WhatsApp otomatis. Petugas membuka chat, lalu menekan “Tandai terkirim”.
