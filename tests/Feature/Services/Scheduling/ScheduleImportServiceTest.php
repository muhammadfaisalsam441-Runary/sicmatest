<?php

use App\Enums\ImportRowStatus;
use App\Enums\ImportStatus;
use App\Enums\LecturerProfileStatus;
use App\Enums\ScheduleSource;
use App\Enums\ScheduleVersionStatus;
use App\Enums\SessionMode;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\Course;
use App\Models\Academic\Period;
use App\Models\Lecturer\Lecturer;
use App\Models\Room\Room;
use App\Models\Scheduling\Import;
use App\Models\Scheduling\ScheduleSession;
use App\Models\User\User;
use App\Services\Scheduling\ScheduleImportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    $this->period = Period::factory()->create();
    foreach ([['I', 450, 500], ['II', 500, 550], ['III', 550, 600], ['X', 940, 990]] as [$label, $start, $end]) {
        $this->period->timeSlots()->create(['label' => $label, 'start_minute' => $start, 'end_minute' => $end]);
    }
    ClassGroup::factory()->create(['name' => 'Tekom C', 'code' => 'TEKOM-C-2025']);
    ClassGroup::factory()->create(['name' => 'PTIK B', 'code' => 'PTIK-B-2025']);
    Course::factory()->create(['code' => 'TKM2301', 'name' => 'Pemrograman Web', 'sks' => 3]);
    Course::factory()->create(['code' => 'TKM2305', 'name' => 'Sistem Operasi', 'sks' => 2]);
    Room::factory()->create(['name' => 'AE 107']);
    Lecturer::factory()->create(['name' => 'Rina Kartika']);
    $this->admin = User::factory()->superAdmin()->create();
});

function scheduleCsv(array $rows): UploadedFile
{
    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, ['Kelas', 'Kode MK', 'Mata Kuliah', 'Hari', 'Jam', 'Ruang', 'Dosen', 'Periode']);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    rewind($handle);

    return UploadedFile::fake()->createWithContent('jadwal.csv', stream_get_contents($handle));
}

test('baris Hubungi Dosen disimpan sebagai sesi belum terjadwal, bukan jam 00.00', function () {
    $import = app(ScheduleImportService::class)->store(scheduleCsv([
        ['PTIK B', 'TKM2305', 'Sistem Operasi', '', 'Hubungi Dosen', '', 'Rina Kartika', ''],
    ]), $this->period, $this->admin);

    $draft = app(ScheduleImportService::class)->commit($import, $this->admin);
    $session = $draft->sessions()->sole();

    expect($session->mode)->toBe(SessionMode::Unscheduled)
        ->and($session->start_minute)->toBeNull()
        ->and($session->day_of_week)->toBeNull()
        ->and($session->source_raw['waktu'])->toBe('Hubungi Dosen');
});

test('jam yang berbeda dari slot ditandai tetapi nilai sumber dipertahankan', function () {
    $import = app(ScheduleImportService::class)->store(scheduleCsv([
        ['Tekom C', 'TKM2301', 'Pemrograman Web', 'Senin', '15.45-16.30', 'AE 107', 'Rina Kartika', ''],
    ]), $this->period, $this->admin);

    $row = $import->rows()->sole();

    expect($row->status)->toBe(ImportRowStatus::Warning)
        ->and(collect($row->issues)->pluck('message')->implode(' '))->toContain('15.45')->toContain('15.40')
        ->and($row->parsed['start_minute'])->toBe(945);
});

test('pengampu dan dua mitra menjadi beberapa pengajar, nama asing menjadi dosen perlu verifikasi', function () {
    $import = app(ScheduleImportService::class)->store(scheduleCsv([
        ['Tekom C', 'TKM2301', 'Pemrograman Web', 'Senin', '07.30-10.00', 'AE 107', 'Rina Kartika; Bpk. Arman; Ibu Nurul H.', 'sebelum UTS'],
    ]), $this->period, $this->admin);

    $session = app(ScheduleImportService::class)->commit($import, $this->admin)->sessions()->with('lecturers')->sole();

    expect($session->lecturers)->toHaveCount(3)
        ->and($session->lecturers->firstWhere('name', 'Rina Kartika')->pivot->role)->toBe('lead')
        ->and($session->lecturers->where('pivot.role', 'partner'))->toHaveCount(2)
        ->and($session->phase->value)->toBe('before_uts')
        ->and(Lecturer::query()->where('source_name', 'Bpk. Arman')->value('profile_status'))->toBe(LecturerProfileStatus::Unverified);
});

test('impor file yang sama dua kali tidak menggandakan data', function () {
    $content = scheduleCsv([['Tekom C', 'TKM2301', 'Pemrograman Web', 'Senin', '07.30-10.00', 'AE 107', 'Rina Kartika', '']]);
    $service = app(ScheduleImportService::class);

    $first = $service->store($content, $this->period, $this->admin);
    $second = $service->store($content, $this->period, $this->admin);

    $draft = $service->commit($first, $this->admin);
    $again = $service->commit($second, $this->admin);

    expect($second->id)->toBe($first->id)
        ->and($again->id)->toBe($draft->id)
        ->and(Import::query()->count())->toBe(1)
        ->and(ScheduleSession::query()->count())->toBe(1);
});

test('baris ganda dalam satu file dilewati dan baris bermasalah tidak masuk draf', function () {
    $import = app(ScheduleImportService::class)->store(scheduleCsv([
        ['Tekom C', 'TKM2301', 'Pemrograman Web', 'Senin', '07.30-10.00', 'AE 107', 'Rina Kartika', ''],
        ['Tekom C', 'TKM2301', 'Pemrograman Web', 'Senin', '07.30-10.00', 'AE 107', 'Rina Kartika', ''],
        ['Kelas Hantu', 'TKM2301', 'Pemrograman Web', 'Selasa', '07.30-10.00', 'AE 107', 'Rina Kartika', ''],
    ]), $this->period, $this->admin);

    expect($import->rows()->pluck('status')->map->value->all())->toBe(['ok', 'skipped', 'error'])
        ->and($import->summary['rows'])->toBe(3);

    $draft = app(ScheduleImportService::class)->commit($import, $this->admin);

    expect($draft->sessions()->count())->toBe(1)
        ->and($draft->status)->toBe(ScheduleVersionStatus::Draft)
        ->and($draft->source)->toBe(ScheduleSource::Import)
        ->and($import->fresh()->status)->toBe(ImportStatus::Committed);
});

test('ruang Online menjadi sesi online yang tidak memesan ruang', function () {
    $import = app(ScheduleImportService::class)->store(scheduleCsv([
        ['Tekom C', 'TKM2301', 'Pemrograman Web', 'Selasa', '07.30-10.00', 'Online', 'Rina Kartika', ''],
    ]), $this->period, $this->admin);

    $session = app(ScheduleImportService::class)->commit($import, $this->admin)->sessions()->sole();

    expect($session->mode)->toBe(SessionMode::Online)
        ->and($session->room_id)->toBeNull()
        ->and($session->blocksRoom())->toBeFalse();
});

test('alur impor lewat halaman admin sampai draf', function () {
    $this->withoutVite();

    $this->actingAs($this->admin)->get(route('admin.imports.create'))->assertOk()->assertSee('Impor jadwal perkuliahan');

    $response = $this->post(route('admin.imports.store'), [
        'period_id' => $this->period->id,
        'file' => scheduleCsv([['Tekom C', 'TKM2301', 'Pemrograman Web', 'Senin', '07.30-10.00', 'AE 107', 'Rina Kartika', '']]),
    ]);

    $import = Import::query()->sole();
    $response->assertRedirect(route('admin.imports.show', $import));

    $this->get(route('admin.imports.show', $import))->assertOk()->assertSee('Periksa hasil impor');
    $this->post(route('admin.imports.commit', $import))->assertRedirect(route('admin.drafts.show', $import->fresh()->schedule_version_id));

    $this->get(route('admin.schedules.template'))->assertDownload('templat-jadwal-sicma.csv');
});

test('file PDF ditolak dengan pesan yang jelas', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.imports.store'), ['period_id' => $this->period->id, 'file' => UploadedFile::fake()->create('jadwal.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors(['file' => 'Format yang didukung: Excel (.xlsx, .xls) dan CSV. PDF belum dapat dibaca otomatis; simpan sebagai Excel dulu.']);
});
