<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Enums\ScheduleVersionStatus;
use App\Exceptions\DomainException;
use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\Import;
use App\Models\ImportRow;
use App\Models\Lecturer;
use App\Models\Period;
use App\Models\Room;
use App\Models\ScheduleSession;
use App\Models\ScheduleVersion;
use App\Models\User;
use App\Services\JtikSpreadsheetImportService;
use App\Services\SchedulePublisher;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class JtikSpreadsheetImportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function workbook(string $semester, array $rows, ?array $roster = null): string
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Daftar Dosen')->fromArray([
            ['Daftar dosen'], [], [],
            ['NO', 'NAMA', 'NIP', 'PANGKAT', 'GOL.', 'TMT GOL.', 'JABATAN', 'TMT JAB.'],
            ...($roster ?? [[1, 'DR. ABDUL SAMPEL, M.T.', '001234567890123456', 'Penata', 'III/c', '', 'LEKTOR', '']]),
        ]);
        $header = ['KATEGORI', 'NO', 'KELAS', 'HARI', 'JAM', 'WITA', 'RUANG', 'KODE MK', 'MATA KULIAH', 'SKS', 'DOSEN PENGAMPU', 'DOSEN MITRA', 'DOSEN MITRA 2'];
        $book->createSheet()->setTitle('Jadwal Perkuliahan')->fromArray([['Jadwal'], [], [], $header, ...$rows]);
        $book->createSheet()->setTitle('Angkatan 2025')->fromArray([['Salinan'], [], [], $header, ...$rows]);
        $book->createSheet()->setTitle('Matriks Ruangan')->fromArray([[null], [null], [null], [null, null, '07:30 - 08:20', '08:20 - 09:10']], null, 'A1');
        $book->setActiveSheetIndex(0);
        $path = Storage::disk('local')->path('Jadwal_dan_Dosen_JTIK_UNM_'.$semester.'.xlsx');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    private function row(int $number = 1, int $sks = 2, string $class = 'TEKOM C 2025'): array
    {
        return ['ANGKATAN 2025', $number, $class, 'Senin', 'I - II', '07.30 - 09.10', 'AE 107', 'ASLI101', 'Mata Kuliah Sumber', $sks, 'Dr. Abd. Sampel, M.T.', 'Mitra Contoh, M.T.', 'Mitra Kedua, M.T.'];
    }

    private function calendars(): array
    {
        return ['Ganjil 2026/2027' => ['start_date' => '2026-08-18', 'end_date' => '2026-12-12', 'uts_start' => '2026-10-12', 'uts_end' => '2026-10-18']];
    }

    private function sources(): array
    {
        $service = app(JtikSpreadsheetImportService::class);

        return [
            $service->inspect($this->workbook('Ganjil_2026_2027', [$this->row()])),
            $service->inspect($this->workbook('Genap_2025_2026', [$this->row(1, 2, 'PTIK U 14')])),
        ];
    }

    public function test_recap_sheet_and_wita_are_read_without_duplicate_cohort_sheets(): void
    {
        Storage::fake('local');
        $source = app(JtikSpreadsheetImportService::class)->inspect($this->workbook('Ganjil_2026_2027', [$this->row()]));

        $this->assertCount(1, $source['rows']);
        $this->assertSame('07.30 - 09.10', $source['rows'][0]['raw']['time']);
        $this->assertSame('I - II', $source['rows'][0]['raw']['slot']);
        $this->assertSame('001234567890123456', $source['roster'][0]['nip']);
        $this->assertCount(2, $source['slots']);
    }

    public function test_both_semesters_are_imported_as_drafts_with_real_roles_and_unknown_calendar(): void
    {
        Storage::fake('local');
        $admin = User::factory()->superAdmin()->create();
        $existingRoom = Room::factory()->create(['name' => 'AE 107', 'capacity' => 47]);
        ClassGroup::factory()->create(['name' => 'Tekom C', 'code' => 'TEKOM-C-2025']);
        $service = app(JtikSpreadsheetImportService::class);
        $service->import($this->sources(), $this->calendars(), 'Ganjil 2026/2027', $admin);

        $active = Period::query()->active()->sole();
        $this->assertSame('Ganjil 2026/2027', $active->name);
        $this->assertSame('2026-12-12', $active->end_date->toDateString());
        $historical = Period::query()->where('name', 'Genap 2025/2026')->sole();
        $this->assertNull($historical->start_date);
        $this->assertNull($historical->end_date);
        $this->assertNull(ClassGroup::query()->where('name', 'PTIK U 14')->sole()->cohort_year);
        $this->assertSame(47, $existingRoom->fresh()->capacity);
        $this->assertDatabaseCount('rooms', 1);
        $this->assertDatabaseCount('schedule_sessions', 2);
        $this->assertSame(0, $active->scheduleVersions()->official()->count());
        $this->assertSame(ScheduleVersionStatus::Draft, $historical->scheduleVersions()->sole()->status);
        $this->assertTrue($historical->scheduleVersions()->sole()->issues()->where('code', 'PERIOD_CALENDAR_MISSING')->exists());

        $lecturer = Lecturer::query()->where('nip', '001234567890123456')->sole();
        $this->assertNull($lecturer->nidn);
        $this->assertCount(2, $lecturer->source_data['rosters']);
        $this->assertContains('Dr. Abd. Sampel, M.T.', $lecturer->source_data['aliases']);
        $session = $active->scheduleVersions()->sole()->sessions()->with('lecturers')->sole();
        $this->assertCount(3, $session->lecturers);
        $this->assertSame('lead', $session->lecturers->firstWhere('id', $lecturer->id)->pivot->role);
        $this->assertCount(2, $session->lecturers->where('pivot.role', 'partner'));
        $this->assertSame(450, $session->start_minute);
        $this->assertSame(550, $session->end_minute);
        $this->assertSame('2', $session->source_raw['sks']);
        $this->assertDatabaseCount('leadership_terms', 0);
        $this->assertDatabaseCount('lecturer_load_targets', 0);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_reimport_is_idempotent_and_preserves_the_original_import_records(): void
    {
        Storage::fake('local');
        $admin = User::factory()->superAdmin()->create();
        $service = app(JtikSpreadsheetImportService::class);
        $sources = $this->sources();
        $service->import($sources, $this->calendars(), 'Ganjil 2026/2027', $admin);
        $ids = ScheduleSession::query()->pluck('id')->all();
        $service->import($sources, $this->calendars(), 'Ganjil 2026/2027', $admin);

        $this->assertDatabaseCount('periods', 2);
        $this->assertDatabaseCount('imports', 2);
        $this->assertDatabaseCount('schedule_versions', 2);
        $this->assertDatabaseCount('lecturers', 3);
        $this->assertSame($ids, ScheduleSession::query()->pluck('id')->all());
        $this->assertSame(2, Import::query()->where('status', ImportStatus::Committed)->count());
    }

    public function test_sks_conflicts_are_preserved_and_block_publication(): void
    {
        Storage::fake('local');
        $admin = User::factory()->superAdmin()->create();
        $service = app(JtikSpreadsheetImportService::class);
        $sources = [
            $service->inspect($this->workbook('Ganjil_2026_2027', [$this->row(1, 2)])),
            $service->inspect($this->workbook('Genap_2025_2026', [$this->row(1, 3)])),
        ];
        $service->import($sources, $this->calendars(), 'Ganjil 2026/2027', $admin);
        $historical = Period::query()->where('name', 'Genap 2025/2026')->sole()->scheduleVersions()->sole();
        $this->assertSame('3', $historical->sessions()->sole()->source_raw['sks']);
        $this->assertSame(2, Course::query()->sole()->sks);
        $this->assertTrue($historical->issues()->where('code', 'SOURCE_SKS_CONFLICT')->exists());
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Lengkapi kalender');
        app(SchedulePublisher::class)->publish($historical, $admin, CarbonImmutable::today(), 0);
    }

    public function test_a_partner_does_not_become_lead_when_the_lead_column_is_blank(): void
    {
        Storage::fake('local');
        $admin = User::factory()->superAdmin()->create();
        $row = $this->row();
        $row[10] = '';
        $service = app(JtikSpreadsheetImportService::class);
        $sources = [$service->inspect($this->workbook('Ganjil_2026_2027', [$row]))];
        $service->import($sources, $this->calendars(), 'Ganjil 2026/2027', $admin);
        $this->assertSame(['partner', 'partner'], ScheduleSession::query()->sole()->lecturers()->pluck('role')->all());
    }

    public function test_available_source_workbooks_reconcile_with_imported_data(): void
    {
        $directory = getenv('SICMA_SOURCE_DIRECTORY') ?: base_path();
        $ganjil = $directory.'/Jadwal_dan_Dosen_JTIK_UNM_Ganjil_2026_2027.xlsx';
        $genap = $directory.'/Jadwal_dan_Dosen_JTIK_UNM_Genap_2025_2026.xlsx';
        if (! is_file($ganjil) || ! is_file($genap)) {
            $this->markTestSkipped('Uji rekonsiliasi memerlukan kedua spreadsheet sumber lokal.');
        }
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
        $this->seed(DatabaseSeeder::class);
        $officialIds = ScheduleVersion::query()->official()->pluck('id')->all();
        $admin = User::query()->where('role', 'super_admin')->firstOrFail();
        $service = app(JtikSpreadsheetImportService::class);
        $sources = [$service->inspect($ganjil), $service->inspect($genap)];
        $this->assertCount(364, $sources[0]['rows']);
        $this->assertCount(394, $sources[1]['rows']);
        $results = $service->import($sources, $this->calendars(), 'Ganjil 2026/2027', $admin);
        $this->assertSame(364, $results[0]['sessions']);
        $this->assertSame(394, $results[1]['sessions']);
        foreach ($results as $result) {
            $this->assertSame($result['rows'], $result['sessions'] + $result['skipped']);
        }
        $this->assertSame($officialIds, ScheduleVersion::query()->official()->pluck('id')->all());
        $this->assertDatabaseCount('rooms', 28);
        $this->assertSame(2, Import::query()->count());
        $this->assertSame(758, ImportRow::query()->count());
        $sessionCount = ScheduleSession::query()->count();
        $lecturerCount = Lecturer::query()->count();
        $service->import($sources, $this->calendars(), 'Ganjil 2026/2027', $admin);
        $this->assertSame($sessionCount, ScheduleSession::query()->count());
        $this->assertSame($lecturerCount, Lecturer::query()->count());
    }

    public function test_invalid_source_rolls_back_master_and_period_changes(): void
    {
        Storage::fake('local');
        $admin = User::factory()->superAdmin()->create();
        $sources = $this->sources();
        $sources[1]['rows'][0]['raw']['sks'] = '';
        try {
            app(JtikSpreadsheetImportService::class)->import($sources, $this->calendars(), 'Ganjil 2026/2027', $admin);
            $this->fail('Impor semestinya ditolak.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('periods', 0);
            $this->assertDatabaseCount('courses', 0);
            $this->assertDatabaseCount('lecturers', 0);
            $this->assertDatabaseCount('imports', 0);
        }
    }
}
