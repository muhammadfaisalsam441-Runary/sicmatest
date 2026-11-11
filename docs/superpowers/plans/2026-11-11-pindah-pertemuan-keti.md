# Pindahkan Pertemuan Kuliah (Portal Keti) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keti dapat memindahkan satu pertemuan kuliah kelasnya ke slot kosong; ruang asli terlepas, slot baru menjadi reservasi "Kuliah Pengganti" yang tetap berlaku saat jadwal ditetapkan ulang.

**Architecture:** Tabel `session_reschedules` mencatat pertemuan asli → reservasi pengganti. `MeetingLocator` menemukan pertemuan resmi per tanggal dan dosen bergiliran; `LecturerConflictChecker` mengecek dosen; `MeetingRescheduleService` menjalankan pindah/batal/pindah lagi dalam satu transaksi dengan memakai ulang `ReservationService` untuk reservasi + blok. `ScheduleBlockBuilder` membuat blok pertemuan yang dipindah dalam keadaan nonaktif.

**Tech Stack:** Laravel 13, PHP 8.4, Pest, Blade + Alpine, Pint.

**Spec:** `docs/superpowers/specs/2026-11-11-pindah-pertemuan-keti-design.md`

## Global Constraints

- PHP lewat Herd: `PHP="$USERPROFILE/.config/herd/bin/php84/php.exe"`; tes: `"$PHP" artisan test --compact <path>`; format: `"$PHP" vendor/bin/pint --dirty --format agent`.
- Semua teks antarmuka & pesan error berbahasa Indonesia, gaya yang sama dengan pesan `ReservationService`.
- Pelanggaran aturan dilempar sebagai `App\Exceptions\DomainException($code, $message, $details)`.
- Kegiatan reservasi pengganti: string persis `Kuliah Pengganti`.
- Kode error baru: `MEETING_NOT_FOUND`, `MEETING_ALREADY_MOVED`, `MEETING_STARTED`, `INVALID_LECTURER`, `LECTURER_CONFLICT`, `RESTORE_NOT_POSSIBLE`. Kode lain memakai yang sudah ada (`NOT_CLASS_REPRESENTATIVE`, `INVALID_TEACHING_DAY`, `OUTSIDE_OPERATING_HOURS`, `INVALID_TIME`, `INVALID_DURATION`, `ROOM_CLOSED`, `ROOM_CONFLICT`, `SCHEDULE_UNCERTAIN`, `CLASS_TIME_CONFLICT`, `LEADERSHIP_THURSDAY`, `INVALID_STATUS_TRANSITION`).
- Pemindahan **tidak** memakai `sicma.reservation.max_days_ahead` (7) dan `max_active_per_class` (3).
- Istirahat Jumat: `config('sicma.scheduler.friday_break')` = menit 720–790; hari kuliah: `config('sicma.scheduler.days')` = [1..5].
- Lock dulu sebelum membaca apa pun di dalam transaksi (MySQL REPEATABLE READ): ruang tujuan → kelas peserta (urut ID) → reservasi yang diganti.
- Notifikasi selalu lewat `NotificationService::notify` (sudah `afterCommit`).
- Tes memakai tanggal acuan `travelTo('2026-11-04 10:00')` (Rabu). `PeriodFactory`: semester 2026-09-01 – 2027-01-15, UTS 2026-10-26 – 2026-10-30 (jadi November = sesudah UTS → giliran mitra).
- Commit per tugas, pesan Bahasa Indonesia, diakhiri `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

### Penyesuaian spesifikasi (dicatat di Tugas 1)

1. `StoreReservationRequest` hanya **menolak** `Kuliah Pengganti` (tanpa membedakan huruf/spasi), bukan membatasi ke daftar kegiatan — banyak tes & klien memakai kegiatan teks bebas.
2. `LecturerAvailabilityChecker` diberi nama `LecturerConflictChecker` agar tidak tertukar dengan `lecturer_availabilities` (di luar cakupan).
3. Kartu pengganti di My Class untuk kelas gabungan dicari lewat `reservations.sessionReschedule.session.participants`.

## Review Focus

1. **Admin menetapkan ulang jadwal setelah ada pemindahan, dan slot asli sudah dipesan kelas lain** → slot asli tetap bebas, penetapan tidak tertahan `RESERVATION_CONFLICT`. Tes di Tugas 6.
2. **Klik dua kali / kirim ulang formulir Pindahkan** → hanya satu pemindahan & satu reservasi. Tes di Tugas 4.
3. **Membatalkan pemindahan setelah ruang asli dipesan kelas lain** → `RESTORE_NOT_POSSIBLE`, tidak ada data berubah. Tes di Tugas 5.
4. **Keti kelas gabungan lain membuka reservasi pengganti** → boleh melihat, tidak boleh Mulai/Batalkan. Tes di Tugas 8.
5. **Reservasi lama berkegiatan "Kuliah Pengganti" (sebelum fitur)** → tetap tampil dan dibatalkan lewat alur biasa. Tes di Tugas 5.

Batasan yang diterima: perpanjangan (+30 menit) reservasi pengganti tidak mengecek dosen.

---

## File Structure

| File | Tanggung jawab |
|---|---|
| `database/migrations/2026_11_11_000001_create_session_reschedules_table.php` | 2 tabel baru + kolom `reservations.session_reschedule_id` |
| `app/Enums/RescheduleStatus.php` | `active`, `cancelled`, `replaced` |
| `app/Models/Academic/SessionReschedule.php` | model, relasi, pencocokan pertemuan |
| `database/factories/SessionRescheduleFactory.php` | factory untuk tes |
| `app/Services/Academic/Meeting.php` | value object satu kemunculan pertemuan |
| `app/Services/Academic/MeetingLocator.php` | cari pertemuan, status pindah, dosen bergiliran |
| `app/Services/Academic/LecturerConflictChecker.php` | bentrok dosen + pimpinan Kamis |
| `app/Services/Academic/MeetingRescheduleService.php` | `move`, `cancel`, `moveAgain` |
| `app/Http/Controllers/Keti/MeetingRescheduleController.php`, `app/Http/Requests/Keti/MoveMeetingRequest.php`, `resources/views/keti/meetings/reschedule.blade.php` | halaman Pindahkan |
| Diubah: `ReservationService`, `ScheduleBlockBuilder`, `ScheduleValidator`, `ClassScheduleService`, `NotificationService`, `ReservationPolicy`, `MyClassController`, `RoomAgendaDescriber`, `BookingModalComposer`, `StoreReservationRequest`, `config/sicma.php`, view My Class / reservasi / admin reservasi, `routes/keti.php`, `tests/Pest.php` | |

---

### Task 1: Data — tabel, model, helper tes

**Files:**
- Create: migrasi di atas, `app/Enums/RescheduleStatus.php`, `app/Models/Academic/SessionReschedule.php`, `database/factories/SessionRescheduleFactory.php`, `tests/Feature/Services/Academic/SessionRescheduleTest.php`
- Modify: `app/Models/Reservation/Reservation.php` (fillable + relasi), `tests/Pest.php` (helper), `docs/superpowers/specs/2026-11-11-pindah-pertemuan-keti-design.md` (penyesuaian 1–3)

**Interfaces:**
- Produces:
  - Tabel `session_reschedules` persis seperti spec bagian 3 (+ indeks `(original_start_at, status)`, `(course_offering_id, original_start_at)`); tabel `session_reschedule_lecturers(session_reschedule_id, lecturer_id, role)` PK gabungan; `reservations.session_reschedule_id` nullable FK nullOnDelete.
  - `enum RescheduleStatus: string { Active='active'; Cancelled='cancelled'; Replaced='replaced'; }`
  - `SessionReschedule` relasi: `session(): BelongsTo<ScheduleSession>` (kolom `schedule_session_id`), `courseOffering()`, `originalRoom()`, `reservation(): BelongsTo`, `creator()`, `lecturers(): BelongsToMany` (pivot `role`, tabel `session_reschedule_lecturers`); scope `active()`; casts datetime + enum.
  - `SessionReschedule::participantKey(iterable $classGroupIds): string` — ID urut naik, dipisah koma.
  - `SessionReschedule::matches(ScheduleSession $session, CarbonInterface $start): bool` — aturan pencocokan spec bagian 3 (start sama menit; offering sama bila keduanya terisi, selain itu judul + participantKey sama).
  - `Reservation::sessionReschedule(): BelongsTo`.
  - Helper tes `meetingSession(array $classGroups, array $attributes = [], array $team = []): ScheduleSession` — sesi di `officialVersion()`, `course_offering_id` untuk kelas pertama (offering baru bila belum ada), peserta = `$classGroups`; `$team` = list `[Lecturer, TeachingRole, SessionPhase]` → buat `TeachingAssignment` + lampirkan `session_lecturers` dengan role; lalu `app(ScheduleBlockBuilder::class)->rebuild($version)`. (Catatan di docblock: panggil `scheduleBlock()` **sesudah** helper ini, karena rebuild menghapus blok jadwal versi tersebut.)

- [ ] **Step 1: Tes gagal** — `tests/Feature/Services/Academic/SessionRescheduleTest.php`:
  - `participantKey([7, 3, 7])` → `'3,7'`.
  - `matches` true untuk offering sama & start sama; false bila start beda 1 menit; untuk offering null: true bila judul & peserta sama, false bila peserta beda.
  - `Reservation::sessionReschedule` dan `SessionReschedule::lecturers` (pivot role `lead`) bisa dimuat.
- [ ] **Step 2:** Jalankan `"$PHP" artisan test --compact tests/Feature/Services/Academic/SessionRescheduleTest.php` → FAIL (kelas belum ada).
- [ ] **Step 3:** Buat migrasi, enum, model (atribut `#[Fillable]` seperti model lain), factory, relasi Reservation, helper `meetingSession` di `tests/Pest.php`. Perbarui spec dengan 3 penyesuaian di atas (bagian 4, 5.7, 6.1).
- [ ] **Step 4:** Tes → PASS.
- [ ] **Step 5: Commit** `Tambah tabel dan model pemindahan pertemuan`.

---

### Task 2: MeetingLocator — pertemuan per tanggal & dosen bergiliran

**Files:**
- Create: `app/Services/Academic/Meeting.php`, `app/Services/Academic/MeetingLocator.php`, `tests/Feature/Services/Academic/MeetingLocatorTest.php`
- Modify: `app/Services/Academic/ClassScheduleService.php` — jadikan `hasClassesOn()` dan `phaseCovers()` `public` (tanpa ubah perilaku).

**Interfaces:**
- Consumes: Task 1 (`SessionReschedule::matches`, scope `active`).
- Produces:
  - `final class Meeting { public function __construct(public readonly ScheduleSession $session, public readonly CarbonImmutable $start, public readonly CarbonImmutable $end, public readonly ?SessionReschedule $reschedule) {} public function isMoved(): bool }`
  - `MeetingLocator::find(ScheduleSession $session, CarbonInterface $date): ?Meeting` — hanya bila sesi milik versi resmi yang berlaku pada tanggal itu, `day_of_week` cocok, `hasClassesOn`, `phaseCovers`, dan `blocksRoom()`.
  - `MeetingLocator::meetingsOn(CarbonInterface $date): Collection<int, Meeting>` — semua sesi versi resmi tanggal itu dengan mode ≠ unscheduled (termasuk online), eager-load `participants`, `lecturers`, `courseOffering.teachingAssignments`, `version.period`.
  - `MeetingLocator::activeRescheduleFor(ScheduleSession $session, CarbonInterface $start): ?SessionReschedule`
  - `MeetingLocator::rotationLecturerIds(ScheduleSession $session, CarbonInterface $date): ?Collection<int,int>` — ID dosen dari `teaching_assignments` yang fasenya mencakup tanggal (full; before_uts bila < `uts_start`; after_uts bila > `uts_end`) dan juga tercantum di `session_lecturers`; `null` bila offering kosong, `uts_start`/`uts_end` kosong, atau hasil kosong.
  - `MeetingLocator::defaultLecturerIds(ScheduleSession $session, CarbonInterface $date): Collection<int,int>` — rotasi ?? dosen ber-role `lead` di sesi ?? semua dosen sesi.
  - `MeetingLocator::dutyLecturerIds(ScheduleSession $session, CarbonInterface $date): Collection<int,int>` — rotasi ?? semua dosen sesi.

- [ ] **Step 1: Tes gagal** (`MeetingLocatorTest`):
  - `find` mengembalikan Meeting Senin 2026-11-09 07:30–10:00 untuk sesi default; `null` untuk Selasa 2026-11-10, untuk tanggal `CalendarException`, untuk sesi `online()`, dan untuk sesi draf.
  - `find(...)->isMoved()` true setelah `SessionReschedule` aktif dibuat untuk start itu.
  - `defaultLecturerIds` 2026-11-09 dengan tim pengampu (before_uts) + mitra (after_uts) → `[mitra]`; setelah `Period` di-update `uts_start=2026-11-23, uts_end=2026-11-27` → `[pengampu]`; dengan `uts_start=null` → `[pengampu]`; sesi tanpa offering & dua dosen tanpa role lead → keduanya.
  - `dutyLecturerIds` dengan `uts_start=null` → pengampu + mitra.
- [ ] **Step 2:** Jalankan → FAIL.
- [ ] **Step 3:** Implementasi; `find` memakai `ClassScheduleService::officialVersionOn`, `hasClassesOn`, `phaseCovers`.
- [ ] **Step 4:** Tes → PASS; jalankan juga `tests/Feature/Services/Scheduling/TeachingDaysTest.php` (ClassScheduleService) → PASS.
- [ ] **Step 5: Commit** `Tambah MeetingLocator untuk pertemuan dan dosen bergiliran`.

---

### Task 3: LecturerConflictChecker

**Files:**
- Create: `app/Services/Academic/LecturerConflictChecker.php`, `tests/Feature/Services/Academic/LecturerConflictCheckerTest.php`

**Interfaces:**
- Consumes: Task 2 (`meetingsOn`, `dutyLecturerIds`, `Meeting::isMoved`).
- Produces: `LecturerConflictChecker::assertFree(Collection $lecturers, CarbonImmutable $start, CarbonImmutable $end, ?Meeting $excludeMeeting = null, ?int $ignoreRescheduleId = null): void`
  - `$lecturers`: `Collection<Lecturer>` (dengan `leadershipTerms` dimuat atau dimuat di sini).
  - Bentrok bila dosen ada di `dutyLecturerIds` pertemuan resmi lain yang beririsan, tidak `isMoved()`, dan bukan `$excludeMeeting` (sesi & start sama); atau di `lecturers` pemindahan `active` lain (≠ `$ignoreRescheduleId`) yang reservasinya Booked/InUse dan beririsan.
  - Lempar `LECTURER_CONFLICT` dengan pesan `"{nama} ({pengampu|mitra}) sedang mengajar {judul} – {kelas peserta} pada {Wita::dayName} {Wita::range}. Pilih jam lain."`; peran diambil dari pivot sesi/pemindahan yang ditinjau.
  - Lalu bila `$start->dayOfWeekIso === 4` dan dosen punya `leadershipTerms` aktif pada tanggal itu → `LEADERSHIP_THURSDAY` `"{nama} sedang menjabat pimpinan dan tidak mengajar hari Kamis."`

- [ ] **Step 1: Tes gagal:**
  - mitra mengajar sesi lain Selasa 2026-11-10 10:00–12:30 → `assertFree([mitra], Selasa 11:00–12:00)` melempar `LECTURER_CONFLICT`, pesan memuat nama dan `(mitra)`.
  - pengampu (before_uts) di sesi lain yang sama jamnya, tanggal sesudah UTS → tidak bentrok.
  - sesi lain yang sudah dipindah (reschedule aktif) → tidak bentrok.
  - pemindahan aktif lain dengan dosen yang sama, reservasi Booked, beririsan → bentrok; reservasinya Cancelled → tidak.
  - `$excludeMeeting` = pertemuan itu sendiri → tidak bentrok.
  - Kamis 2026-11-12 dengan dosen ber-`LeadershipTerm` aktif → `LEADERSHIP_THURSDAY`; term berakhir 2026-11-01 → lolos.
- [ ] **Step 2:** Jalankan → FAIL.
- [ ] **Step 3:** Implementasi.
- [ ] **Step 4:** Tes → PASS.
- [ ] **Step 5: Commit** `Tambah pengecekan bentrok dosen untuk pemindahan`.

---

### Task 4: MeetingRescheduleService::move

**Files:**
- Create: `app/Services/Academic/MeetingRescheduleService.php`, `tests/Feature/Services/Academic/MeetingRescheduleServiceTest.php`
- Modify: `app/Services/Room/ReservationService.php`, `app/Services/Account/NotificationService.php`

**Interfaces:**
- Consumes: Task 1–3.
- Produces:
  - `ReservationService::createForReschedule(User $keti, ClassGroup $classGroup, Room $lockedRoom, CarbonImmutable $start, CarbonImmutable $end, string $idempotencyKey): Reservation` — dipanggil di dalam transaksi yang sudah me-lock ruang; menjalankan: ruang aktif & bisa dipesan (`ROOM_CLOSED`), belum lewat (`INVALID_TIME`), jam operasional (`OUTSIDE_OPERATING_HOURS`), `assertRoomFree` (termasuk `SCHEDULE_UNCERTAIN`); lalu membuat Reservation (`purpose` = `Kuliah Pengganti`, status Booked) + RoomBlock + `AvailabilityCache::bust()`. Tanpa audit/notifikasi. Bagian simpan reservasi+blok diekstrak ke metode privat `persist(...)` yang juga dipakai `create()`.
  - `ReservationService::assertClassFree(...)` menjadi `public` (signature tetap).
  - `NotificationService::ketisOf(iterable $classGroupIds): Collection<User>` — Keti aktif dengan `ClassRepresentative::activeOn(now())` (query sama seperti `SchedulePublisher::announce`; refactor `announce` memakai metode ini).
  - `MeetingRescheduleService::move(User $keti, ScheduleSession $session, CarbonInterface $originalDate, CarbonInterface $newStart, Room $room, array $lecturerIds, string $idempotencyKey): SessionReschedule`

- [ ] **Step 1: Tes gagal** (`MeetingRescheduleServiceTest`, sesi Senin 07:30–10:00 kelas Tekom C, ruang AE 107, tim pengampu+mitra):
  - berhasil pindah ke Selasa 2026-11-10 10:00 AE 209: reservasi `Kuliah Pengganti` 10:00–12:30 milik Tekom C dengan `session_reschedule_id`; blok jadwal asli Senin `is_active=false`; blok reservasi baru aktif; reschedule `active` dengan lecturers + role; audit `meeting.rescheduled`; notifikasi admin.
  - kelas gabungan: Keti kelas kedua bisa memindahkan; Keti kelas pertama mendapat notifikasi `Pertemuan dipindahkan`.
  - aturan penolakan (dataset, satu kasus per kode): Keti kelas lain → `NOT_CLASS_REPRESENTATIVE`; tanggal asli Selasa → `MEETING_NOT_FOUND`; dipindah dua kali → `MEETING_ALREADY_MOVED`; `travelTo('2026-11-09 08:00')` → `MEETING_STARTED`; tujuan Sabtu 2026-11-14 / CalendarException / 2027-01-20 / Jumat 2026-11-13 11:30 (menyentuh 12:00) → `INVALID_TEACHING_DAY`; tujuan 16:00 → `OUTSIDE_OPERATING_HOURS`; tujuan sudah lewat → `INVALID_TIME`; `lecturerIds=[]` atau dosen luar tim → `INVALID_LECTURER`; ruang tujuan ada blok jadwal → `ROOM_CONFLICT`; Tekom C punya sesi resmi lain Selasa 11:00 → `CLASS_TIME_CONFLICT`; Tekom C punya reservasi Selasa 11:00 → `CLASS_TIME_CONFLICT`; mitra bentrok → `LECTURER_CONFLICT`; Kamis + pimpinan → `LEADERSHIP_THURSDAY`. Setiap kasus: jumlah `reservations` & `session_reschedules` tetap 0.
  - tujuan 2026-11-30 (lebih dari 7 hari) berhasil; kelas sudah punya 3 reservasi aktif → tetap berhasil.
  - ganti ruang di tanggal & jam yang sama berhasil.
  - **Review Focus 2:** dua panggilan dengan kunci idempotensi sama → reschedule & reservasi yang sama, hitungan 1.
- [ ] **Step 2:** Jalankan → FAIL.
- [ ] **Step 3:** Implementasi `move` mengikuti urutan spec 5.1: idempotensi di luar transaksi (cari reservasi `created_by`+kunci dengan `session_reschedule_id`), lalu transaksi: lock ruang, lock semua kelas peserta urut ID, cek idempotensi lagi, aturan 1–11 (aturan kelas: pertemuan resmi lain dari `meetingsOn` yang pesertanya beririsan, tidak dipindah, bukan pertemuan ini; reservasi lewat `ReservationService::assertClassFree` per kelas), `createForReschedule`, buat reschedule + lecturers, isi `session_reschedule_id`, nonaktifkan blok asli (`source_type=schedule`, `source_id=session`, `start_at=original start`), audit, notifikasi admin + `ketisOf(peserta lain)` dengan `['reservation_id' => ...]`.
- [ ] **Step 4:** Tes → PASS; jalankan juga `tests/Feature/Services/Room` dan `tests/Feature/Keti` → PASS (refactor `persist`).
- [ ] **Step 5: Commit** `Tambah layanan pindahkan pertemuan`.

---

### Task 5: Batalkan pemindahan & Pindahkan lagi

**Files:**
- Modify: `app/Services/Academic/MeetingRescheduleService.php`, `app/Services/Room/ReservationService.php` (`cancel`), `tests/Feature/Services/Academic/MeetingRescheduleServiceTest.php`

**Interfaces:**
- Produces:
  - `MeetingRescheduleService::cancel(Reservation $reservation, User $actor, ?string $reason = null): Reservation`
  - `MeetingRescheduleService::moveAgain(User $keti, Reservation $replacement, CarbonInterface $newStart, Room $room, array $lecturerIds, string $idempotencyKey): SessionReschedule`
  - `ReservationService::cancel` — setelah cek alasan admin, bila `$reservation->session_reschedule_id !== null` delegasikan ke `app(MeetingRescheduleService::class)->cancel(...)`.

- [ ] **Step 1: Tes gagal:**
  - batal (Keti) → reservasi Cancelled, blok reservasi nonaktif, reschedule `cancelled`, blok jadwal asli aktif lagi, notifikasi admin; dipanggil lewat `ReservationService::cancel` hasilnya sama.
  - admin batal tanpa alasan → `DATA_INCOMPLETE`; dengan alasan → berhasil, Keti pemindah diberi tahu.
  - reservasi pengganti InUse → `INVALID_STATUS_TRANSITION`.
  - **Review Focus 3:** Keti kelas lain memesan AE 107 Senin 07:30 setelah slot dilepas → batal pemindahan `RESTORE_NOT_POSSIBLE`; reschedule tetap `active`, reservasi pengganti tetap Booked.
  - `travelTo` melewati jam asli → `RESTORE_NOT_POSSIBLE`.
  - `moveAgain` setelah `RESTORE_NOT_POSSIBLE` → reservasi lama Cancelled, reschedule lama `replaced`, reschedule baru `active` untuk `original_start_at` yang sama, blok asli tetap nonaktif; `moveAgain` saat jam asli sudah lewat juga berhasil; `moveAgain` untuk reservasi biasa (tanpa reschedule) → `MEETING_NOT_FOUND`.
  - **Review Focus 5:** reservasi biasa berkegiatan `Kuliah Pengganti` tanpa `session_reschedule_id` dibatalkan lewat `ReservationService::cancel` → alur lama (slot dilepas, tidak ada reschedule tersentuh).
- [ ] **Step 2:** Jalankan → FAIL.
- [ ] **Step 3:** Implementasi sesuai spec 5.4 & 5.5 (lock ruang asli → reservasi pengganti; `moveAgain` lock ruang tujuan → kelas peserta → reservasi lama; aturan 3 & 4 dilewati; `ignoreRescheduleId` = reschedule lama untuk cek dosen; abaikan reservasi lama saat cek kelas).
- [ ] **Step 4:** Tes → PASS; `tests/Feature/Services/Room/ReservationServiceTest.php` → PASS.
- [ ] **Step 5: Commit** `Tambah batal dan pindahkan lagi pertemuan`.

---

### Task 6: Penetapan ulang jadwal menghormati pemindahan

**Files:**
- Modify: `app/Services/Scheduling/ScheduleBlockBuilder.php` (`occurrences`), `app/Services/Scheduling/ScheduleValidator.php` (`reservationConflicts`), `tests/Feature/Services/Scheduling/SchedulePublisherTest.php`

**Interfaces:**
- Consumes: `SessionReschedule::matches`, scope `active` (Task 1).
- Produces: `occurrences()` memuat reschedule aktif dalam rentang versi sekali, lalu `'is_active' => ! $moved` per baris. `reservationConflicts()` hanya memeriksa occurrence dengan `is_active === true`.

- [ ] **Step 1: Tes gagal** (SchedulePublisherTest):
  - setelah `move`, draf baru (salinan versi resmi, sesi baru dengan offering sama) ditetapkan → blok Senin 07:30 versi baru `is_active=false`, blok Senin minggu lain aktif.
  - sesi tanpa offering (judul + peserta sama) → tetap nonaktif di versi baru.
  - **Review Focus 1:** slot asli dipesan kelas lain setelah pemindahan → penetapan versi baru berhasil (tidak ada `RESERVATION_CONFLICT`).
- [ ] **Step 2:** Jalankan → FAIL.
- [ ] **Step 3:** Implementasi.
- [ ] **Step 4:** Tes → PASS; `tests/Feature/Services/Scheduling` → PASS.
- [ ] **Step 5: Commit** `Jaga pemindahan pertemuan saat jadwal ditetapkan ulang`.

---

### Task 7: Halaman Pindahkan pertemuan

**Files:**
- Create: `app/Http/Controllers/Keti/MeetingRescheduleController.php`, `app/Http/Requests/Keti/MoveMeetingRequest.php`, `resources/views/keti/meetings/reschedule.blade.php`, `tests/Feature/Keti/MeetingRescheduleTest.php`
- Modify: `routes/keti.php`

**Interfaces:**
- Consumes: Task 2 (`find`, `defaultLecturerIds`, `rotationLecturerIds`), Task 4–5 (`move`, `moveAgain`), `AvailabilityService::search`.
- Produces:
  - `GET /keti/my-class/pindahkan` → `create`, nama `keti.meetings.reschedule`; query: `sesi` (wajib), `tanggal` (Y-m-d, wajib), `pengganti` (ID reservasi, opsional → mode Pindahkan lagi), `ke_tanggal`, `jam` (`HH:MM`), `dosen[]`.
  - `POST /keti/my-class/pindahkan` → `store`, nama `keti.meetings.reschedule.store`, `throttle:20,1`; body: `session_id`, `original_date`, `replaces_reservation_id?`, `start_at` (`Y-m-d H:i`), `room_id`, `lecturer_ids[]`, `idempotency_key` (uuid).
  - View data: `meeting` (Meeting), `team` (dosen sesi + role + `isRotation`), `selectedLecturerIds`, `duration`, `start` (?CarbonImmutable), `conflict` (?array `{code,message}`), `rooms` (kartu ruang kosong; ruang asli di urutan pertama bila ada), `idempotencyKey`.

- [ ] **Step 1: Tes gagal** (HTTP):
  - halaman tampil: judul "Pindahkan pertemuan", nama mata kuliah, dosen mitra `checked`, pengampu tidak, keterangan "bukan giliran sesuai fase" pada pengampu.
  - dengan `ke_tanggal=2026-11-10&jam=10:00` → daftar ruang kosong, ruang asli AE 107 tampil pertama, tombol "Pindahkan ke sini".
  - dosen bentrok → pesan bentrok tampil, daftar ruang tidak tampil.
  - POST sukses → redirect `keti.reservations.show` reservasi baru; reschedule tersimpan.
  - POST melanggar aturan → redirect kembali ke halaman dengan pesan error (session `reschedule_error.code`); `postJson` → 409 dengan `code`.
  - pertemuan tidak ditemukan (GET) → 404; Keti kelas bukan peserta → 403.
- [ ] **Step 2:** Jalankan → FAIL.
- [ ] **Step 3:** Implementasi; `MoveMeetingRequest::authorize` = `isApprovedKeti()`; aturan bisnis tetap di service.
- [ ] **Step 4:** Tes → PASS.
- [ ] **Step 5: Commit** `Tambah halaman Pindahkan pertemuan untuk Keti`.

---

### Task 8: My Class, halaman reservasi pengganti, hak lihat kelas gabungan

**Files:**
- Modify: `app/Http/Controllers/Keti/MyClassController.php`, `resources/views/keti/my-class.blade.php`, `app/Http/Controllers/Keti/ReservationController.php` (`show` eager-load), `resources/views/keti/reservations/show.blade.php`, `app/Policies/ReservationPolicy.php` (`view`), `tests/Feature/Keti/MeetingRescheduleTest.php`

**Interfaces:**
- Produces:
  - `MyClassController` mengirim `meetings`: list `{session, start, end, status, reschedule}` dengan `status ∈ {'upcoming','ongoing','finished','moved','online'}` (badge: Akan datang, Sedang berlangsung, Selesai, Dipindahkan, Online) dan `replacements`: reservasi pengganti pada tanggal itu yang `sessionReschedule.session.participants` memuat kelas Keti.
  - `ReservationPolicy::view` juga true bila Keti aktif kelasnya peserta `reservation.sessionReschedule.session`. `manage` tidak berubah.

- [ ] **Step 1: Tes gagal:**
  - My Class 2026-11-04 10:00 dengan sesi Rabu 07:30–10:00 → "Selesai"; sesi Rabu 13:00 → "Akan datang" + tombol "Pindahkan" menuju `keti.meetings.reschedule`; `travelTo` 13:30 → "Sedang berlangsung"; sesi online → "Online" tanpa tombol Pindahkan.
  - setelah dipindah: kartu asli "Dipindahkan" + `'ke '.Wita::longDate($start).' • '.Wita::clock($start, false).' • AE 209'` (nilai harapan dihitung dengan helper `Wita`, bukan ditulis tangan) + "Lihat pengganti"; My Class tanggal tujuan menampilkan kartu "Kuliah Pengganti".
  - halaman reservasi pengganti: "Pertemuan berhasil dipindahkan", jadwal asal, "Dosen: {nama} (mitra)", tombol "Batalkan pemindahan" dan "Pindahkan lagi"; reservasi biasa tetap "Batalkan".
  - **Review Focus 4:** Keti kelas gabungan kedua: GET reservasi pengganti → 200, tanpa tombol "Mulai gunakan"; POST `keti.reservations.start` → 403.
- [ ] **Step 2:** Jalankan → FAIL.
- [ ] **Step 3:** Implementasi.
- [ ] **Step 4:** Tes → PASS; `tests/Feature/Keti` → PASS.
- [ ] **Step 5: Commit** `Tampilkan status pertemuan dan pengganti di My Class`.

---

### Task 9: Hapus "Kuliah Pengganti" dari booking biasa

**Files:**
- Modify: `config/sicma.php` (`reservation.purposes` tanpa `Kuliah Pengganti`; `academic_purposes` tetap), `app/Http/Requests/Keti/StoreReservationRequest.php`, `app/View/Composers/BookingModalComposer.php`, tes: `tests/Feature/Keti/QuickBookingTest.php` (baris 68, 81), `tests/Feature/Keti/RoomCalendarTest.php` (117, 220, 332–342), `tests/Feature/Services/Scheduling/TeachingDaysTest.php` (dataset 198–199, 223)

**Interfaces:**
- Produces: `StoreReservationRequest` menolak `purpose` yang `Str::lower(Str::squish())`-nya = `kuliah pengganti` dengan pesan `"Untuk mengganti jadwal kuliah, gunakan Pindahkan di My Class."`. Bawaan modal: kegiatan terakhir kelas bila masih diizinkan, selain itu pilihan pertama yang diizinkan.

- [ ] **Step 1: Tes gagal:**
  - POST booking dengan `Kuliah Pengganti` dan ` kuliah  pengganti ` → 422 error `purpose` berisi pesan di atas.
  - modal booking tidak memuat `<option value="Kuliah Pengganti"`; bawaan hari kerja = `Diskusi / Kerja Kelompok`.
  - Perbarui tes lama: ganti kegiatan booking `Kuliah Pengganti`/`Kuliah pengganti` → `Praktikum Tambahan` (hari kerja) atau `Diskusi / Kerja Kelompok`; dataset akhir pekan TeachingDaysTest hanya `Praktikum Tambahan`; asersi opsi disabled memakai `Praktikum Tambahan`; daftar opsi RoomCalendarTest tanpa `Kuliah Pengganti`.
- [ ] **Step 2:** Jalankan tes baru → FAIL.
- [ ] **Step 3:** Implementasi.
- [ ] **Step 4:** `tests/Feature/Keti`, `tests/Feature/Services/Scheduling/TeachingDaysTest.php` → PASS.
- [ ] **Step 5: Commit** `Arahkan kuliah pengganti ke fitur Pindahkan`.

---

### Task 10: Sisi admin

**Files:**
- Modify: `app/Http/Controllers/Admin/ReservationController.php` (eager-load `sessionReschedule.session`, `sessionReschedule.lecturers`), `resources/views/admin/reservations/index.blade.php`, `app/Services/Room/RoomAgendaDescriber.php` (`reservation()`, `describeMany`), `tests/Feature/Admin/AdminPanelTest.php`

**Interfaces:**
- Produces: agenda reservasi pengganti `title` = `"Kuliah Pengganti: {judul sesi}"`, `class` = nama peserta digabung `" + "`.

- [ ] **Step 1: Tes gagal:**
  - riwayat reservasi admin menampilkan label "Pindahan", `'dari '.Wita::longDate($originalStart).' '.Wita::clock($originalStart, false)`, dan nama dosen.
  - dashboard admin saat jam pengganti: baris AE 209 memuat "Kuliah Pengganti: {judul}"; baris AE 107 di jam asli "Tersedia".
  - admin membatalkan reservasi pengganti dengan alasan → pertemuan kembali (blok asli aktif).
- [ ] **Step 2:** Jalankan → FAIL.
- [ ] **Step 3:** Implementasi.
- [ ] **Step 4:** `tests/Feature/Admin` → PASS.
- [ ] **Step 5: Commit** `Tampilkan pemindahan pertemuan di panel admin`.

---

### Task 11: Verifikasi akhir

- [ ] **Step 1:** `"$PHP" vendor/bin/pint --dirty --format agent` → passed.
- [ ] **Step 2:** `"$PHP" artisan test --compact` → semua lulus (1 skipped seperti sebelumnya).
- [ ] **Step 3:** `"$PHP" artisan migrate --pretend` pada database lokal → hanya migrasi Task 1.
- [ ] **Step 4:** Uji manual singkat di browser (`/keti/my-class` → Pindahkan → Batalkan pemindahan) dengan akun Keti seed.
- [ ] **Step 5: Commit** sisa format bila ada.
