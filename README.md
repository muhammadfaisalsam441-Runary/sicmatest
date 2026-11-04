# SICMA — System for Intelligent Class Management & Access

Website manajemen dan reservasi ruang kelas Jurusan Teknik Informatika dan Komputer (JTIK) Universitas Negeri Makassar.
Tiga area: **Portal Publik** (tanpa login), **Portal Keti** (ketua tingkat), dan **Panel Admin**, dengan satu halaman masuk bersama di `/masuk`.

> Website desktop (lebar ≥ 1024 px). Semua waktu dalam WITA (Asia/Makassar). Ruang, kelas, mata kuliah, dosen, dan Jadwal resmi
> berasal dari **Jadwal TA Ganjil 2026/2027 JTIK** (PDF update 21 Agustus 2026). Akun admin/Keti dan reservasi adalah **data simulasi** dengan nama fiktif.

## Teknologi

Laravel 13 (PHP 8.3+), MySQL 8 / MariaDB, Blade + Tailwind CSS v4 (Vite) + Alpine.js, ikon Lucide, Pest,
phpoffice/phpspreadsheet (impor Excel/CSV), worker penyusun jadwal Python + OR-Tools CP-SAT (`scheduler-worker/`).

## Instalasi

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Atur database di `.env` (contoh MariaDB XAMPP):

```dotenv
DB_CONNECTION=mariadb
DB_DATABASE=sicma
DB_USERNAME=root
DB_PASSWORD=
```

Lalu:

```bash
php artisan migrate:fresh --seed
npm run build          # atau `npm run dev` saat pengembangan
php artisan test
```

Dengan Laravel Herd, situs tersedia di `http://sicma-jtik.test`. Tanpa Herd: `php artisan serve`.

### Worker penyusun jadwal (Susun Jadwal otomatis)

```bash
python -m pip install -r scheduler-worker/requirements.txt
```

Isi path Python di `.env` bila `python` tidak ada di PATH:

```dotenv
SICMA_PYTHON="C:/Users/<nama>/AppData/Local/Programs/Python/Python312/python.exe"
SICMA_SCHEDULER_MODE=detached   # detached | queue | sync
```

- `detached` (bawaan): worker dijalankan sebagai proses terpisah dan halaman proses memantau hasilnya — **tidak perlu queue worker**.
- `queue`: job `RunScheduleOptimization` lewat antrean database; jalankan `php artisan queue:work`.
- `sync`: request menunggu sampai worker selesai (hanya untuk pengujian).

### Pekerjaan terjadwal

`sicma:flag-overdue` (tiap 5 menit) menandai reservasi yang tidak dimulai atau melewati batas dan mengirim pemberitahuan.
Jalankan `php artisan schedule:work` saat pengembangan, atau cron `* * * * * php artisan schedule:run` di server.

### Produksi

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize
php artisan queue:work      # bila SICMA_SCHEDULER_MODE=queue
```

## Akun demo

Semua kata sandi: `password`. Masuk dengan email **atau** NIM.

| Peran | Email | Keterangan |
|---|---|---|
| Super admin | `superadmin@sicma.test` | Boleh menetapkan jadwal (izin `publish-schedule`) |
| Admin | `admin@sicma.test` | Tanpa izin menetapkan jadwal |
| Keti (disetujui) | `dimas.pratama@sicma.test` (NIM 2502040001) | TEKOM C 2025 |
| Keti (disetujui) | `nadia.putri@sicma.test` | PTIK B 2025 |
| Keti (disetujui) | `rizky.ramadhan@sicma.test` | TEKOM A 2025 |
| Keti (menunggu) | `andi.saputra@sicma.test` | Pengajuan sedang diperiksa |
| Keti (perlu perbaikan) | `sarah.amalia@sicma.test` | Pengajuan perlu diperbaiki |

### Data jadwal

Sumber: `database/data/jadwal-ganjil-2026-2027.csv`, salinan 364 baris PDF jadwal jurusan dengan kolom templat impor
(berkas yang sama bisa diunggah di **Kelola Jadwal › Impor**). Seeder membentuk:

- 21 ruang dari jadwal (12 gedung AE, 9 lab/ruang lain "Gedung belum didata"); lantai, kapasitas, dan fasilitas **Perlu verifikasi**.
- 52 kelas, 103 mata kuliah, 102 dosen; Ketua Jurusan (dari lembar pengesahan) dicatat sebagai pimpinan.
- **Jadwal resmi v1**: 362 sesi (dua kuliah gabungan PTIK ULANG 2024 + PTIK F 2026 dihitung satu sesi).
- **Draf v2** (salinan jadwal resmi) dengan masalah wajib: dua bentrok yang **memang ada di PDF** —
  PTIK ULANG 2025 hari Senin (Strategi Pembelajaran vs Inovasi Teknologi) dan Alifya NFH hari Rabu
  (PTIK B 2026 di Lab ICT 10 vs PTIK ULANG 2023 di AE 102) — serta dua masalah **simulasi**: sesi Ketua Jurusan
  dipindah ke Kamis dan satu sesi "Hubungi Dosen".
- Reservasi berbagai status dan satu penutupan ruang (simulasi) di ruang yang sedang kosong.

## Alur demo presentasi

1. **Publik** — buka `/` dan `/ruangan`: status ruang Tersedia/Digunakan tanpa data pribadi.
2. **Daftar Keti** — `/daftar` → kode verifikasi 6 digit (lihat `storage/logs/laravel.log`, `MAIL_MAILER=log`) → status pengajuan.
3. **Admin menyetujui** — masuk sebagai admin → **Akun Keti** → Periksa → Setujui.
4. **Jadwal** — **Kelola Jadwal** → Impor Excel/CSV (unduh templat) atau buka draf v2 → selesaikan masalah wajib
   (ubah penempatan sesi), atau **Susun Jadwal** → cakupan "Bagian terdampak" → hasil masuk draf baru.
5. **Tetapkan** — sebagai super admin, buka draf → **Tetapkan jadwal**. Penetapan memeriksa ulang bentrok dan reservasi.
6. **Keti memesan** — masuk sebagai Keti → **Cari Ruangan** → Detail ruangan → Pesan ruang → **Mulai gunakan** → **Selesai menggunakan**.
7. **Admin memantau** — **Riwayat Reservasi** (batalkan dengan alasan) dan **Log Pemakaian** (rencana vs aktual, unduh CSV).

## Aturan bisnis utama

- Reservasi tanpa persetujuan admin, siapa cepat dia dapat; disimpan atomik (transaksi + `lockForUpdate`) dengan idempotency key.
- Kelas pemesan diambil dari akun Keti di server, bukan dari request.
- Interval waktu setengah terbuka `[mulai, selesai)`; ruang bisa dipesan hanya bila bebas sepanjang durasi.
- Tabel `room_blocks` adalah satu-satunya sumber pengecekan bentrok; `AvailabilityService` dipakai Publik, Keti, dan dashboard admin.
- Portal hanya membaca jadwal resmi; draf tidak memengaruhi status ruang. Hasil susun otomatis selalu menjadi draf.
- Dosen pimpinan tidak dijadwalkan hari Kamis selama masa jabatan. Data yang belum diketahui disimpan NULL / "Perlu verifikasi".

## Keputusan yang belum ditetapkan jurusan

Nilai sementara ada di `config/sicma.php` dan ditandai `// BELUM DITETAPKAN`, antara lain: jam operasional,
durasi minimum/maksimum reservasi, batas hari pemesanan, jendela check-in, pembatalan otomatis no-show,
keterlihatan nama pemesan, hari aktif perkuliahan untuk susun otomatis, dan apakah kapasitas kurang menghalangi penetapan.
Slot jam diturunkan dari kolom JAM/WITA di PDF (awal slot XI diasumsikan 16.35). Tanggal semester, UTS/UAS, hari libur,
masa jabatan Ketua Jurusan, dan apakah Koordinator Prodi juga termasuk "pimpinan" (aturan Kamis) belum ditetapkan.

## Struktur penting

```
app/Services/      AvailabilityService, ReservationService, ScheduleBlockBuilder, ScheduleValidator,
                   SchedulePublisher, DraftService, ScheduleImportService, SchedulerService,
                   KetiApplicationService, KetiApprovalService, ClosureService, NotificationService, AuditLogger
app/Jobs/          RunScheduleOptimization
scheduler-worker/  solve.py (OR-Tools CP-SAT)
docs/design/       SICMA_JTIK.png, frames-index.json, frames-map.png (PDF sumber disimpan lokal)
tests/Feature/     Pest — termasuk AcceptanceScenariosTest (peta 17 skenario penerimaan)
```
