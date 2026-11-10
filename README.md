<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

> Website desktop (lebar ≥ 1024 px). Semua waktu dalam WITA (Asia/Makassar). Ruang, kelas, mata kuliah, dosen, dan Jadwal resmi
> berasal dari **Jadwal TA Ganjil 2026/2027 JTIK** (PDF update 21 Agustus 2026). Akun admin/Keti dan reservasi adalah **data simulasi** dengan nama fiktif.

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

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
- 52 kelas, 103 mata kuliah, 107 dosen; Ketua Jurusan (dari lembar pengesahan) dicatat sebagai pimpinan.
- Data kepegawaian 51 dosen homebase JTIK (NIP, pangkat, golongan, jabatan fungsional, TMT) dari `database/data/dosen-jtik.csv`
  (salinan Daftar Dosen JTIK; nomor 27–30 tidak ada di dokumen sumber). Dosen jadwal di luar daftar: asal belum diverifikasi.
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
