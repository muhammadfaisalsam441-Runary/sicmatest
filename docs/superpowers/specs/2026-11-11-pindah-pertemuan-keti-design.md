# Pindahkan Pertemuan Kuliah (Portal Keti) — Rancangan

Tanggal: 2026-11-11
Status: disetujui dalam diskusi, menunggu tinjauan dokumen

## 1. Tujuan

Keti dapat memindahkan **satu pertemuan** kuliah kelasnya (satu tanggal) ke slot lain yang kosong.
Ruang di jam asli langsung terbuka untuk kelas lain, dan jadwal resmi buatan admin tidak berubah.

Masalah yang diselesaikan: saat ini Keti hanya bisa memesan ruang dengan kegiatan "Kuliah Pengganti".
Ruang di jam asli tetap terblokir (satu kelas memegang dua ruang), dosen tidak dicek, dan sistem tidak
tahu pertemuan aslinya batal.

### Keputusan dari pemilik produk

| Topik | Keputusan |
|---|---|
| Persetujuan | Tidak perlu. Keti memindahkan sendiri, hanya per pertemuan, bukan jadwal mingguan admin. |
| Dosen | Dicek. Keti memilih dosen yang masuk dari tim pengajar sesi itu; dosen bergiliran tercentang otomatis. |
| Aturan dosen | Tolak bila dosen terpilih bentrok jam mengajar atau pimpinan mengajar hari Kamis (B11). Ketersediaan dosen (`lecturer_availabilities`) tidak dicek. |
| Batas waktu | Tidak ada batas hari ke depan (batas 7 hari booking tidak berlaku), selama masih di semester berjalan. |
| Kelas gabungan | Keti dari kelas peserta mana pun boleh memindahkan untuk semua kelas; koordinasi di luar sistem. |
| Pendekatan | Slot baru disimpan sebagai reservasi "Kuliah Pengganti" + tabel catatan pemindahan. |
| Booking biasa | Kegiatan "Kuliah Pengganti" dihapus dari booking biasa; hanya lewat My Class → Pindahkan. |

### Asumsi (disampaikan dan tidak dikoreksi)

- Durasi pengganti sama dengan pertemuan asli; ruang boleh berbeda.
- Hanya pertemuan luring yang punya ruang yang bisa dipindah; kuliah online tidak.
- Pertemuan yang sudah dimulai atau lewat tidak bisa dipindah.
- Pemindahan tidak dihitung ke batas reservasi aktif per kelas (`max_active_per_class`).
- Admin dan Keti kelas peserta lain mendapat notifikasi.

## 2. Perbedaan dengan booking ruang

| | Booking ruang | Pindahkan pertemuan |
|---|---|---|
| Tujuan | Kegiatan tambahan | Mengganti waktu kuliah terjadwal |
| Titik masuk | Cari Ruangan / Booking Ruangan | My Class → kartu pertemuan → Pindahkan |
| Jadwal asli | Tidak tersentuh | Blok ruang asli dinonaktifkan |
| Kegiatan | Dipilih Keti | Otomatis "Kuliah Pengganti" |
| Durasi | Dipilih (2/3/4 SKS) | Terkunci, sama dengan aslinya |
| Batas 7 hari & batas reservasi aktif | Berlaku | Tidak berlaku |
| Dosen | Tidak dicek | Dosen terpilih dicek |
| Kelas yang dicek | Kelas pemesan | Semua kelas peserta |
| Dibatalkan | Slot dilepas | Kembali ke jam asli bila masih memungkinkan |

Persamaannya: slot baru tetap reservasi, sehingga punya Mulai gunakan / Selesai / Perpanjang dan tampil
di Aktivitas, riwayat admin, dan monitoring.

## 3. Data

### Tabel baru `session_reschedules`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint | |
| `schedule_session_id` | FK `schedule_sessions`, nullOnDelete | sesi asal (tautan & tampilan) |
| `course_offering_id` | FK `course_offerings`, nullable, nullOnDelete | kunci pencocokan utama lintas versi jadwal |
| `session_title` | string | salinan judul sesi untuk pencocokan cadangan |
| `participant_key` | string | ID kelas peserta terurut, dipisah koma, mis. `3,7` |
| `original_start_at`, `original_end_at` | datetime | pertemuan asli |
| `original_room_id` | FK `rooms` | ruang asli |
| `reservation_id` | FK `reservations` | reservasi pengganti |
| `status` | enum `active`, `cancelled`, `replaced` | |
| `created_by` | FK `users` | Keti pemindah |
| `cancelled_at`, `cancelled_by` | nullable | |
| timestamps | | |

Indeks: `(original_start_at, status)`, `(course_offering_id, original_start_at)`.
Satu pertemuan hanya boleh punya satu pemindahan `active` (ditegakkan di service, di dalam transaksi dengan lock).

### Tabel baru `session_reschedule_lecturers`

`session_reschedule_id` (FK, cascadeOnDelete), `lecturer_id` (FK), `role` enum `lead`/`partner`.
Primary key gabungan. Menyimpan dosen yang dipilih Keti.

### Kolom baru `reservations.session_reschedule_id`

Nullable FK ke `session_reschedules`, nullOnDelete. Terisi hanya untuk reservasi pengganti.

### Pencocokan pertemuan asli

Sebuah kemunculan sesi pada tanggal D dianggap sudah dipindah bila ada `session_reschedules` berstatus
`active` dengan `original_start_at` = D + `start_minute`, dan:

- `course_offering_id` sama, bila keduanya terisi; atau
- bila salah satu kosong: `session_title` sama dengan judul sesi dan `participant_key` sama dengan kelas peserta sesi.

Logika ini ada di satu tempat, `MeetingLocator::activeRescheduleFor(ScheduleSession, CarbonInterface $date)`,
dan dipakai oleh pembuat blok, My Class, dan pemeriksaan bentrok.

## 4. Komponen

| Unit | Tanggung jawab |
|---|---|
| `App\Services\Academic\MeetingRescheduleService` | `move()`, `moveAgain()`, `cancel()`; semua aturan & efek samping dalam transaksi |
| `App\Services\Academic\MeetingLocator` | Menemukan pertemuan asli pada tanggal tertentu & status pindahnya; dosen bergiliran per fase |
| `App\Services\Academic\LecturerAvailabilityChecker` | Bentrok jam mengajar dosen pada tanggal & jam tertentu + aturan pimpinan Kamis |
| `App\Models\Academic\SessionReschedule` | Model + relasi `reservation`, `lecturers`, `session` |
| `App\Http\Controllers\Keti\MeetingRescheduleController` | Halaman Pindahkan (GET) dan simpan (POST) |
| `App\Http\Requests\Keti\MoveMeetingRequest` | Validasi masukan |
| `ScheduleBlockBuilder` (diubah) | Kemunculan yang sudah dipindah dibuat dengan `is_active = false` |
| `ReservationService::cancel` (diubah) | Bila reservasi punya `session_reschedule_id`, delegasikan ke `MeetingRescheduleService::cancel` |
| `ReservationPolicy::view` (diubah) | Keti kelas peserta sesi asal boleh melihat reservasi pengganti |

`ReservationService` mendapat metode internal untuk membuat reservasi + blok tanpa aturan khusus booking
(batas 7 hari, batas reservasi aktif), dipakai oleh `MeetingRescheduleService`. Aturan bersama
(jam operasional, ruang aktif & bisa dipesan, ruang kosong, idempotensi) tetap memakai kode yang sama.

## 5. Alur & aturan

### 5.1 Memindahkan (`move`)

Masukan: Keti, sesi asal, tanggal asli, tanggal & jam mulai baru, ruang tujuan, daftar dosen terpilih,
kunci idempotensi.

Urutan dalam transaksi (lock dulu, baru membaca, agar aman di MySQL REPEATABLE READ):

1. Lock ruang tujuan, lalu semua kelas peserta (urut ID) dengan `lockForUpdate`.
2. Idempotensi: bila sudah ada reservasi dengan kunci yang sama dari Keti ini, kembalikan hasilnya.
3. Periksa, berhenti di pelanggaran pertama:

| # | Aturan | Kode |
|---|---|---|
| 1 | Keti disetujui dan kelasnya peserta sesi | `NOT_CLASS_REPRESENTATIVE` |
| 2 | Pertemuan ada di jadwal resmi tanggal itu (hari, fase UTS, bukan libur), luring dengan ruang | `MEETING_NOT_FOUND` |
| 3 | Pertemuan belum dipindah | `MEETING_ALREADY_MOVED` |
| 4 | Pertemuan belum dimulai (`original_start_at` > sekarang) | `MEETING_STARTED` |
| 5 | Slot baru: Senin–Jumat, dalam semester, bukan libur kalender, tidak beririsan istirahat Jumat (`sicma.scheduler.friday_break`), dalam jam operasional, belum lewat | `INVALID_TEACHING_DAY` / `OUTSIDE_OPERATING_HOURS` / `INVALID_TIME` |
| 6 | Durasi = durasi asli | `INVALID_DURATION` |
| 7 | Dosen terpilih: minimal satu, semuanya anggota tim sesi | `INVALID_LECTURER` |
| 8 | Ruang aktif, bisa dipesan, kosong (jadwal, reservasi, penutupan); jadwal resmi ada di tanggal tujuan | `ROOM_CLOSED` / `ROOM_CONFLICT` / `SCHEDULE_UNCERTAIN` |
| 9 | Semua kelas peserta tidak punya kuliah resmi lain (yang tidak sedang dipindah) maupun reservasi aktif di slot baru | `CLASS_TIME_CONFLICT` |
| 10 | Dosen terpilih tidak bentrok (5.3) | `LECTURER_CONFLICT` |
| 11 | Tidak ada dosen terpilih yang menjabat pimpinan bila tanggal tujuan hari Kamis | `LEADERSHIP_THURSDAY` |

Pemindahan ke tanggal & jam yang sama dengan ruang berbeda diperbolehkan (ganti ruang).

4. Efek:
   - Buat reservasi: kelas = kelas Keti pemindah, `purpose` = "Kuliah Pengganti", status Dipesan, blok `reservation`.
   - Buat `session_reschedules` (`active`) + `session_reschedule_lecturers`; isi `reservations.session_reschedule_id`.
   - Nonaktifkan blok jadwal asli (`source_type = schedule`, `source_id = session`, `start_at = original_start_at`).
   - `AvailabilityCache::bust()`, log audit `meeting.rescheduled`.
   - Notifikasi (setelah commit): admin + Keti aktif kelas peserta lain.

### 5.2 Dosen bergiliran (centang awal)

Dari `teaching_assignments` penawaran sesi, dibandingkan dengan tanggal pertemuan asli:

- fase `full` → bergiliran;
- `before_uts` → bergiliran bila tanggal < `periods.uts_start`;
- `after_uts` → bergiliran bila tanggal > `periods.uts_end`.

Bila tanggal UTS kosong, penawaran tidak terhubung, atau hasilnya kosong: centang pengampu (`lead`)
dari `session_lecturers`; bila tidak ada pengampu, centang semua dosen sesi.
Memilih dosen yang bukan gilirannya diperbolehkan, dengan keterangan "bukan giliran sesuai fase".

### 5.3 Bentrok dosen

Dosen terpilih L bentrok di slot [S, E) tanggal D bila L **bertugas** pada:

- kemunculan jadwal resmi lain pada D yang beririsan dan **tidak** sedang dipindah; "bertugas" mengikuti
  aturan giliran 5.2 untuk sesi itu (bila tidak bisa ditentukan, semua dosen sesi dianggap bertugas); atau
- pertemuan pindahan lain yang `active`, reservasinya Dipesan/Sedang dipakai, dan beririsan; "bertugas"
  = dosen yang dipilih di pemindahan itu.

Pertemuan yang sedang dipindah sendiri tidak dihitung.
Pesan menyebut nama, peran, dan bentroknya, mis. "Budi Santoso (pengampu) sedang mengajar Basis Data –
Tekom A pada Rabu 10.00–11.40."

### 5.4 Membatalkan pemindahan (`cancel`)

Dipicu tombol "Batalkan pemindahan" (Keti kelas pemindah) atau pembatalan admin (alasan wajib).

- Hanya bila reservasi pengganti masih Dipesan.
- Lock: ruang asli, lalu reservasi pengganti, sebelum membaca apa pun.
- Syarat kembali ke jam asli: `original_start_at` > sekarang dan ruang asli kosong di jam asli. Bila tidak
  terpenuhi → tolak dengan `RESTORE_NOT_POSSIBLE` dan arahkan ke "Pindahkan lagi".
- Efek: reservasi → Dibatalkan, blok reservasi nonaktif, `session_reschedules` → `cancelled`, blok jadwal
  asli aktif lagi, audit `meeting.reschedule_cancelled`, notifikasi seperti 5.1.

### 5.5 Pindahkan lagi (`moveAgain`)

Dari halaman reservasi pengganti yang masih Dipesan. Dalam satu transaksi: reservasi lama dibatalkan,
pemindahan lama → `replaced`, lalu pemindahan baru dibuat untuk pertemuan asli yang sama dengan aturan 5.1,
kecuali aturan 3 (pemindahan yang sedang diganti) dan aturan 4 (jam asli boleh sudah lewat). Sebagai
gantinya, reservasi pengganti lama harus masih Dipesan dan belum dimulai. Blok jadwal asli tetap nonaktif.
Ini jalan keluar ketika pembatalan ditolak `RESTORE_NOT_POSSIBLE`.

### 5.6 Penetapan ulang jadwal oleh admin

`ScheduleBlockBuilder::occurrences()` menandai kemunculan yang cocok dengan pemindahan `active`
(pencocokan bagian 3) sebagai `is_active = false`. Pemeriksaan draf terhadap reservasi
(`RESERVATION_CONFLICT`) yang sudah ada otomatis melindungi slot pengganti.

### 5.7 Booking biasa

- Hapus "Kuliah Pengganti" dari `sicma.reservation.purposes`; tetap di `academic_purposes` agar aturan
  hari kuliah berlaku pada data lama.
- `StoreReservationRequest`: `purpose` wajib salah satu dari `sicma.reservation.purposes`.
- `BookingModalComposer`: kegiatan bawaan tidak lagi "Kuliah Pengganti" (pakai kegiatan terakhir kelas bila
  masih tersedia, selain itu pilihan pertama yang diizinkan).
- Reservasi lama berkegiatan "Kuliah Pengganti" tidak diubah.

## 6. Tampilan

### 6.1 My Class

Badge kartu pertemuan mengikuti jam:

| Keadaan | Badge | Aksi |
|---|---|---|
| Sebelum mulai | Akan datang | Pindahkan, Detail ruangan |
| Dalam jam kuliah | Sedang berlangsung | Detail ruangan |
| Sesudah selesai | Selesai | — |
| Dipindah | Dipindahkan — "ke Rabu, 12 Nov • 10.00 • AE 209" | Lihat pengganti |
| Online | Online | — |

Pada tanggal tujuan, pertemuan pengganti tampil sebagai kartu "Kuliah Pengganti" dengan status
reservasinya, juga untuk Keti kelas gabungan lain.

### 6.2 Halaman Pindahkan pertemuan

Rute `GET /keti/my-class/pindahkan?sesi={id}&tanggal={Y-m-d}` dengan filter GET `ke_tanggal`, `jam`,
`dosen[]`; simpan `POST /keti/my-class/pindahkan`.

1. Ringkasan pertemuan asli (mata kuliah, kelas peserta, tanggal, jam, ruang).
2. Dosen yang masuk: kotak centang tim pengajar + peran; bergiliran tercentang.
3. Tanggal (Senin–Jumat dalam semester) dan jam mulai; durasi ditampilkan, terkunci.
4. Bila kelas atau dosen bentrok: pesan sebelum daftar ruang. Bila aman: daftar ruang kosong (memakai
   `AvailabilityService::search`), ruang asli di atas bila kosong; tiap ruang punya tombol
   "Pindahkan ke sini" (POST dengan `idempotency_key`).
5. Sukses → halaman reservasi pengganti, judul "Pertemuan berhasil dipindahkan".

### 6.3 Halaman reservasi pengganti

- Keterangan: mata kuliah, jadwal asal, dosen yang masuk.
- "Batalkan" menjadi "Batalkan pemindahan" (kembali ke jam asli); tambah "Pindahkan lagi".

### 6.4 Admin

- Riwayat reservasi: label "Pindahan" + jadwal asal + dosen.
- Dashboard/monitoring: tidak perlu perubahan logika; agenda ruang untuk reservasi pengganti menampilkan
  nama mata kuliah (`RoomAgendaDescriber`).

## 7. Penanganan error

Semua pelanggaran dilempar sebagai `App\Exceptions\DomainException` dengan kode di 5.1/5.4.
Halaman Pindahkan menampilkan pesannya di atas formulir dengan masukan tetap terisi; respons JSON
`409` untuk klien JSON, mengikuti pola `ReservationController::store`.

Kode baru: `MEETING_NOT_FOUND`, `MEETING_ALREADY_MOVED`, `MEETING_STARTED`, `INVALID_LECTURER`,
`LECTURER_CONFLICT`, `RESTORE_NOT_POSSIBLE`. Kode lain memakai yang sudah ada.

## 8. Pengujian (Pest)

Service:
- pemindahan berhasil: blok asli nonaktif, reservasi + blok baru, catatan & dosen tersimpan, notifikasi;
- setiap aturan penolakan 1–11;
- dosen bergiliran: pengampu sebelum UTS, mitra sesudah UTS, UTS kosong → pengampu;
- bentrok dosen terhadap jadwal resmi (menghormati fase) dan terhadap pemindahan lain (dosen terpilih);
- kelas gabungan: Keti kelas mana pun bisa memindahkan; semua kelas dicek;
- batal kembali ke jam asli; batal ditolak (`RESTORE_NOT_POSSIBLE`); pindahkan lagi;
- idempotensi (klik dua kali).

Penetapan ulang jadwal:
- pemindahan tetap berlaku setelah versi baru ditetapkan, lewat `course_offering_id` dan lewat pencocokan cadangan.

HTTP:
- status My Class (Akan datang, Sedang berlangsung, Selesai, Dipindahkan, kartu pengganti);
- halaman Pindahkan (centang awal, pesan bentrok, daftar ruang, simpan);
- hak lihat Keti kelas gabungan; hak kelola hanya kelas pemindah;
- "Kuliah Pengganti" tidak ada di modal booking dan ditolak `StoreReservationRequest`.

Batasan: penguncian baris tidak dapat dibuktikan di SQLite yang dipakai tes.

## 9. Di luar cakupan

- Mengubah jadwal mingguan (tetap wewenang admin lewat draf & penetapan).
- Persetujuan admin/dosen atas pemindahan.
- Cek `lecturer_availabilities`.
- Notifikasi ke dosen (dosen tidak punya akun di sistem).
- Memindahkan kuliah online.
