<?php

namespace Tests\Feature\Services\Scheduling;

use App\Enums\IssueSeverity;
use App\Enums\ReservationStatus;
use App\Enums\ScheduleVersionStatus;
use App\Exceptions\DomainException;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\Course;
use App\Models\Academic\Period;
use App\Models\Lecturer\Lecturer;
use App\Models\Reservation\Reservation;
use App\Models\Room\Room;
use App\Models\Scheduling\Import;
use App\Models\Scheduling\ScheduleSession;
use App\Models\Scheduling\ScheduleVersion;
use App\Models\User\User;
use App\Services\Academic\ClassScheduleService;
use App\Services\Room\ReservationService;
use App\Services\Scheduling\ScheduleBlockBuilder;
use App\Services\Scheduling\ScheduleDraftService;
use App\Services\Scheduling\ScheduleImportService;
use App\Services\Scheduling\SchedulePublisher;
use App\Services\Scheduling\ScheduleValidator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TeachingDaysTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    }

    private function version(bool $official = false, ?Period $period = null): ScheduleVersion
    {
        $factory = ScheduleVersion::factory()->for($period ?? Period::factory()->create());

        return ($official ? $factory->official() : $factory)->create()->refresh();
    }

    private function createSession(ScheduleVersion $version, int $day, string $title = 'Kuliah uji'): ScheduleSession
    {
        return ScheduleSession::factory()->for($version, 'version')->create([
            'day_of_week' => $day, 'start_minute' => 450, 'end_minute' => 550, 'title' => $title,
        ]);
    }

    private function assertDomainError(callable $action, string $code): void
    {
        try {
            $action();
            $this->fail('Operasi seharusnya ditolak.');
        } catch (DomainException $exception) {
            $this->assertSame($code, $exception->errorCode);
        }
    }

    public static function weekends(): array
    {
        return ['Sabtu' => [6], 'Minggu' => [7]];
    }

    #[DataProvider('weekends')]
    public function test_admin_cannot_save_weekend_sessions_through_http_or_service(int $day): void
    {
        $draft = $this->version();
        $session = $this->createSession($draft, 5);
        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('admin.drafts.show', [$draft, 'sesi' => $session->id]))->assertOk()
            ->assertSee('<option value="5"', false)->assertDontSee('<option value="6"', false)->assertDontSee('<option value="7"', false);
        $this->put(route('admin.drafts.sessions.update', [$draft, $session]), [
            'mode' => 'offline', 'day_of_week' => $day, 'start_time' => '07:30', 'end_time' => '09:10', 'room_id' => $session->room_id,
        ])->assertSessionHasErrors(['day_of_week' => 'Perkuliahan hanya boleh dijadwalkan Senin–Jumat.']);
        $this->assertDomainError(fn () => app(ScheduleDraftService::class)->updateSession($session, [
            'mode' => 'offline', 'day_of_week' => $day, 'start_minute' => 450, 'end_minute' => 550, 'room_id' => $session->room_id,
        ]), 'INVALID_TEACHING_DAY');
        $this->assertSame(5, $session->fresh()->day_of_week);
        $this->assertSame(0, $draft->fresh()->lock_version);
    }

    public function test_admin_can_save_friday_and_sees_the_teaching_rule(): void
    {
        $draft = $this->version();
        $session = $this->createSession($draft, 1);
        $this->actingAs(User::factory()->admin()->create())->put(route('admin.drafts.sessions.update', [$draft, $session]), [
            'mode' => 'offline', 'day_of_week' => 5, 'start_time' => '07:30', 'end_time' => '09:10', 'room_id' => $session->room_id,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(5, $session->fresh()->day_of_week);
        $this->get(route('admin.academic.index', ['tab' => 'aturan']))->assertOk()->assertSee('Perkuliahan hanya Senin–Jumat');
    }

    public static function weekendModes(): array
    {
        return [[6, 'offline'], [7, 'offline'], [6, 'online'], [7, 'online']];
    }

    #[DataProvider('weekendModes')]
    public function test_validator_blocks_publication_and_weekend_session_generates_no_room_blocks(int $day, string $mode): void
    {
        $official = $this->version(true);
        $this->createSession($official, 5);
        app(ScheduleBlockBuilder::class)->rebuild($official);
        $blockCount = $official->roomBlocks()->count();
        $draft = $this->version(false, $official->period);
        $session = $this->createSession($draft, $day);
        if ($mode === 'online') {
            $session->update(['mode' => $mode, 'room_id' => null]);
        }

        $issues = app(ScheduleValidator::class)->validate($draft);
        $issue = $issues->firstWhere('code', 'TEACHING_DAY_NOT_ALLOWED');
        $this->assertNotNull($issue);
        $this->assertSame(IssueSeverity::Error, $issue->severity);
        $this->assertContains($session->id, $issue->session_ids);
        $this->assertSame([], app(ScheduleBlockBuilder::class)->occurrences($draft));

        $actor = User::factory()->superAdmin()->create();
        $this->assertDomainError(fn () => app(SchedulePublisher::class)->publish($draft, $actor, now(), $draft->lock_version), 'DRAFT_HAS_ISSUES');
        $this->assertSame(ScheduleVersionStatus::Draft, $draft->fresh()->status);
        $this->assertSame(ScheduleVersionStatus::Official, $official->fresh()->status);
        $this->assertSame($blockCount, $official->roomBlocks()->count());
    }

    private function importRows(array $days): Import
    {
        Storage::fake('local');
        $period = Period::factory()->create();
        ClassGroup::factory()->create(['name' => 'Kelas Uji']);
        Course::factory()->create(['code' => 'UJI001', 'name' => 'Mata Kuliah Uji', 'sks' => 2]);
        Lecturer::factory()->create(['name' => 'Dosen Uji']);
        Room::factory()->create(['name' => 'Ruang Uji']);
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['Kelas', 'Kode MK', 'Mata Kuliah', 'Hari', 'Jam', 'Ruang', 'Dosen', 'Periode']);
        foreach ($days as $day) {
            fputcsv($stream, ['Kelas Uji', 'UJI001', 'Mata Kuliah Uji', $day, '07.30-09.10', 'Ruang Uji', 'Dosen Uji', '']);
        }
        rewind($stream);
        $file = UploadedFile::fake()->createWithContent('hari-kuliah.csv', stream_get_contents($stream));
        fclose($stream);

        return app(ScheduleImportService::class)->store($file, $period, User::factory()->superAdmin()->create());
    }

    public function test_import_marks_weekend_rows_as_errors_and_only_commits_friday(): void
    {
        $import = $this->importRows(['Jumat', 'Sabtu', 'Minggu']);
        $rows = $import->rows()->orderBy('row_no')->get();
        $this->assertSame('error', $rows[1]->status->value);
        $this->assertSame('error', $rows[2]->status->value);
        $this->assertStringContainsString('Senin–Jumat', json_encode($rows[1]->issues, JSON_UNESCAPED_UNICODE));
        $draft = app(ScheduleImportService::class)->commit($import, User::factory()->superAdmin()->create());
        $this->assertSame(5, $draft->sessions()->sole()->day_of_week);
    }

    public function test_import_preview_checked_before_the_rule_cannot_commit_a_weekend(): void
    {
        $import = $this->importRows(['Jumat']);
        $row = $import->rows()->sole();
        $parsed = $row->parsed;
        $parsed['day'] = 6;
        $row->update(['parsed' => $parsed]);
        $this->assertDomainError(fn () => app(ScheduleImportService::class)->commit($import, User::factory()->superAdmin()->create()), 'INVALID_TEACHING_DAY');
        $this->assertDatabaseCount('schedule_sessions', 0);
        $this->assertDatabaseCount('schedule_versions', 0);
    }

    public function test_my_class_hides_legacy_weekend_sessions_but_shows_friday(): void
    {
        $official = $this->version(true);
        $class = ClassGroup::factory()->create();
        foreach ([5, 6, 7] as $day) {
            $this->createSession($official, $day, 'Kuliah hari '.$day)->participants()->attach($class);
        }
        $service = app(ClassScheduleService::class);
        $this->assertCount(1, $service->sessionsFor($class, CarbonImmutable::parse('2026-11-06')));
        $this->assertCount(0, $service->sessionsFor($class, CarbonImmutable::parse('2026-11-07')));
        $this->assertCount(0, $service->sessionsFor($class, CarbonImmutable::parse('2026-11-08')));
        $response = $this->actingAs(User::factory()->approvedKeti($class)->create())
            ->get(route('keti.my-class', ['tanggal' => '2026-11-07']))->assertOk()
            ->assertSee('Perkuliahan berlangsung Senin–Jumat')->assertDontSee('Kuliah hari 6');
        $this->assertSame([1, 2, 3, 4, 5], $response->viewData('days')->map->dayOfWeekIso->all());
    }

    public static function academicWeekends(): array
    {
        return [
            ['2026-11-07 10:00', 'Praktikum Tambahan'],
            ['2026-11-08 10:00', ' praktikum   tambahan '],
        ];
    }

    #[DataProvider('academicWeekends')]
    public function test_keti_cannot_book_academic_activities_on_weekends(string $start, string $purpose): void
    {
        $this->version(true);
        $room = Room::factory()->create();
        $this->actingAs(User::factory()->approvedKeti()->create())->postJson(route('keti.reservations.store'), [
            'room_id' => $room->id, 'start_at' => $start, 'duration' => 100, 'purpose' => $purpose, 'idempotency_key' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'INVALID_TEACHING_DAY');
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('room_blocks', 0);
    }

    public function test_weekend_keeps_general_booking_available_and_disables_academic_choices(): void
    {
        $this->version(true);
        $room = Room::factory()->create();
        $this->actingAs(User::factory()->approvedKeti()->create());
        $response = $this->get(route('keti.rooms.show', ['room' => $room->id, 'mulai' => '2026-11-07 10:00']))->assertOk();
        $this->assertMatchesRegularExpression('/<option value="Praktikum Tambahan"[^>]*disabled/', $response->getContent());
        foreach (['2026-11-07 10:00', '2026-11-08 10:00'] as $start) {
            $this->postJson(route('keti.reservations.store'), [
                'room_id' => $room->id, 'start_at' => $start, 'duration' => 100,
                'purpose' => 'Kegiatan Organisasi / Himpunan', 'idempotency_key' => (string) Str::uuid(),
            ])->assertCreated();
        }
        $this->assertDatabaseCount('reservations', 2);
    }

    public function test_legacy_weekend_academic_booking_cannot_start_or_be_extended(): void
    {
        $keti = User::factory()->approvedKeti()->create();
        $reservation = Reservation::factory()->create([
            'created_by' => $keti->id, 'purpose' => 'Kuliah Pengganti', 'status' => ReservationStatus::Booked,
            'class_group_id' => $keti->activeClassGroup()->id,
            'start_at' => '2026-11-07 10:00', 'end_at' => '2026-11-07 11:40',
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-11-07 10:00'));
        $service = app(ReservationService::class);
        $this->actingAs($keti)->get(route('keti.reservations.show', $reservation))->assertOk()
            ->assertSee('Reservasi perkuliahan di luar hari kuliah')->assertSee('Batalkan')->assertDontSee('Mulai gunakan');
        $this->assertDomainError(fn () => $service->start($reservation, $keti), 'INVALID_TEACHING_DAY');
        $this->assertSame(ReservationStatus::Booked, $reservation->fresh()->status);
        $reservation->update(['status' => ReservationStatus::InUse]);
        $this->assertDomainError(fn () => $service->extend($reservation, $keti), 'INVALID_TEACHING_DAY');
        $this->assertSame('11:40', $reservation->fresh()->end_at->format('H:i'));
    }

    public function test_friday_academic_booking_is_allowed(): void
    {
        $this->version(true);
        $room = Room::factory()->create();
        $this->actingAs(User::factory()->approvedKeti()->create())->postJson(route('keti.reservations.store'), [
            'room_id' => $room->id, 'start_at' => '2026-11-06 10:00', 'duration' => 100,
            'purpose' => 'Praktikum Tambahan', 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();

        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_friday_break_between_1200_and_1310_is_rejected_and_flagged_by_validator(): void
    {
        $draft = $this->version();
        $session = $this->createSession($draft, 5);
        $this->actingAs(User::factory()->admin()->create())->put(route('admin.drafts.sessions.update', [$draft, $session]), [
            'mode' => 'offline', 'day_of_week' => 5, 'start_time' => '11:50', 'end_time' => '12:40', 'room_id' => $session->room_id,
        ])->assertSessionHasErrors(['start_time' => 'Pada hari Jumat pukul 12.00–13.10 tidak ada perkuliahan.']);

        $this->assertDomainError(fn () => app(ScheduleDraftService::class)->updateSession($session, [
            'mode' => 'offline', 'day_of_week' => 5, 'start_minute' => 710, 'end_minute' => 760, 'room_id' => $session->room_id,
        ]), 'FRIDAY_BREAK_CONFLICT');

        $session->forceFill(['day_of_week' => 5, 'start_minute' => 710, 'end_minute' => 760])->save();
        $issues = app(ScheduleValidator::class)->validate($draft->fresh());
        $issue = $issues->firstWhere('code', 'FRIDAY_BREAK_CONFLICT');
        $this->assertNotNull($issue);
        $this->assertSame(IssueSeverity::Error, $issue->severity);
    }
}
