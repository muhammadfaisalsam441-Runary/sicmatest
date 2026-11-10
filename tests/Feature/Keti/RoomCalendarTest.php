<?php

namespace Tests\Feature\Keti;

use App\Enums\RoomBlockSource;
use App\Models\Academic\Period;
use App\Models\Reservation\Reservation;
use App\Models\Room\Building;
use App\Models\Room\Room;
use App\Models\Room\RoomBlock;
use App\Models\Scheduling\ScheduleVersion;
use App\Models\User\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoomCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:07'));
    }

    private function keti(): User
    {
        ScheduleVersion::factory()->official()->for(Period::factory())->create();

        return User::factory()->approvedKeti()->create();
    }

    private function block(Room $room, string $start, string $end, array $attributes = []): void
    {
        RoomBlock::factory()->for($room)->create([
            'start_at' => '2026-11-05 '.$start, 'end_at' => '2026-11-05 '.$end,
            'source_type' => RoomBlockSource::Schedule,
            'schedule_version_id' => ScheduleVersion::query()->official()->firstOrFail()->id,
            ...$attributes,
        ]);
    }

    public function test_calendar_merges_busy_intervals_and_only_offers_windows_long_enough(): void
    {
        $keti = $this->keti();
        $room = Room::factory()->create(['name' => 'AE 108']);
        $this->block($room, '07:30', '09:10');
        $this->block($room, '09:00', '10:05', ['source_type' => RoomBlockSource::Reservation, 'schedule_version_id' => null]);
        $this->block($room, '11:00', '12:00', ['source_type' => RoomBlockSource::Closure, 'schedule_version_id' => null]);
        $this->block($room, '13:10', '14:00');
        $this->block($room, '17:00', '18:00', ['source_type' => RoomBlockSource::Closure, 'schedule_version_id' => null]);
        $this->block($room, '07:30', '17:30', ['is_active' => false]);
        $draft = ScheduleVersion::factory()->for(Period::query()->first())->create();
        $this->block($room, '14:00', '17:00', ['schedule_version_id' => $draft->id]);

        $response = $this->actingAs($keti)->get(route('keti.rooms.calendar', ['tanggal' => '2026-11-05', 'durasi' => 60]))
            ->assertOk()->assertSee('AE 108')->assertSee('12:00–13:10 WITA')->assertSee('14:00–17:00 WITA');

        $windows = $response->viewData('rows')->sole()['windows'];
        $this->assertSame(['12:00', '14:00'], array_map(fn ($window) => $window['start']->format('H:i'), $windows));
        $this->assertSame(['12:00', '12:10'], array_map(fn ($time) => $time->format('H:i'), $windows[0]['starts']));
        $this->assertSame('16:00', $windows[1]['starts'][array_key_last($windows[1]['starts'])]->format('H:i'));
    }

    public function test_calendar_filters_rooms_and_excludes_closed_inactive_and_unbookable_rooms(): void
    {
        $keti = $this->keti();
        $building = Building::factory()->create();
        Room::factory()->for($building)->create(['name' => 'Pilihan sesuai', 'capacity' => 40]);
        Room::factory()->for($building)->create(['name' => 'Pilihan kecil', 'capacity' => 10]);
        Room::factory()->for($building)->create(['name' => 'Pilihan nonaktif', 'capacity' => 40, 'is_active' => false]);
        Room::factory()->for($building)->create(['name' => 'Pilihan dibatasi', 'capacity' => 40, 'is_bookable' => false]);
        Room::factory()->create(['name' => 'Pilihan gedung lain', 'capacity' => 40]);
        $closed = Room::factory()->for($building)->create(['name' => 'Pilihan ditutup', 'capacity' => 40]);
        $this->block($closed, '00:00', '23:59', ['source_type' => RoomBlockSource::Closure, 'schedule_version_id' => null]);

        $response = $this->actingAs($keti)->get(route('keti.rooms.calendar', [
            'tanggal' => '2026-11-05', 'q' => 'Pilihan', 'gedung' => $building->id, 'orang' => 30,
        ]))->assertOk();

        $this->assertSame(['Pilihan sesuai'], $response->viewData('rows')->pluck('room.name')->all());
    }

    public function test_today_has_no_past_slots_and_closes_at_end_of_operating_hours(): void
    {
        $keti = $this->keti();
        Room::factory()->create();
        $response = $this->actingAs($keti)->get(route('keti.rooms.calendar'))->assertOk();
        $window = $response->viewData('rows')->sole()['windows'][0];
        $this->assertSame('10:07', $window['start']->format('H:i'));
        $this->assertSame('17:30', $window['end']->format('H:i'));

        $this->travelTo(CarbonImmutable::parse('2026-11-04 17:01'));
        $this->get(route('keti.rooms.calendar', ['tanggal' => '2026-11-04', 'durasi' => 30]))->assertOk()->assertSee('Tidak ada slot yang bisa dipesan');
    }

    public function test_closed_room_at_night_offers_its_next_free_slot_and_can_be_booked_for_tomorrow(): void
    {
        $keti = $this->keti();
        $room = Room::factory()->create(['capacity' => 30]);
        Room::factory()->create();
        $this->block($room, '07:30', '09:10');
        $this->travelTo(CarbonImmutable::parse('2026-11-04 23:40'));

        $details = $this->actingAs($keti)->get(route('keti.rooms.show', ['room' => $room->id, 'durasi' => 60]))->assertOk()
            ->assertSee('Status saat ini')->assertSee('Tutup')->assertSee('Slot kosong terdekat dipilihkan')
            ->assertSee('09:10–10:10 WITA')->assertSee('Pesan ruang');
        $this->assertSame('2026-11-05 09:10', $details->viewData('start')->format('Y-m-d H:i'));
        $this->assertTrue($details->viewData('requested')->isAvailable());

        $this->postJson(route('keti.reservations.store'), [
            'room_id' => $room->id, 'start_at' => '2026-11-05 09:10', 'duration' => 60,
            'purpose' => 'Kuliah pengganti', 'participants' => 20, 'idempotency_key' => $details->viewData('idempotencyKey'),
        ])->assertCreated();
        $this->assertDatabaseHas('reservations', [
            'room_id' => $room->id, 'start_at' => '2026-11-05 09:10:00', 'end_at' => '2026-11-05 10:10:00',
        ]);
    }

    public function test_calendar_defaults_to_tomorrow_at_night_but_respects_explicit_date(): void
    {
        $keti = $this->keti();
        Room::factory()->create();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 23:40'));

        $response = $this->actingAs($keti)->get(route('keti.rooms.calendar'))->assertOk()
            ->assertSee('Booking tetap bisa dilakukan sekarang');
        $this->assertSame('2026-11-05', $response->viewData('date')->toDateString());
        $this->assertCount(1, $response->viewData('rows'));
        $this->assertSame('07:30', $response->viewData('rows')->sole()['windows'][0]['start']->format('H:i'));

        $response = $this->get(route('keti.rooms.calendar', ['tanggal' => '2026-11-04']))->assertOk();
        $this->assertSame('2026-11-04', $response->viewData('date')->toDateString());
        $this->assertTrue($response->viewData('rows')->isEmpty());
    }

    public function test_calendar_navigation_and_date_options_only_include_teaching_days(): void
    {
        $keti = $this->keti();
        $this->travelTo(CarbonImmutable::parse('2026-11-07 08:00'));
        $response = $this->actingAs($keti)->get(route('keti.rooms.calendar'))->assertOk();
        $this->assertSame('2026-11-09', $response->viewData('date')->toDateString());
        $this->assertSame(['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13'], $response->viewData('days')->map->toDateString()->all());
        $response->assertSee('Senin')->assertSee('Jumat')
            ->assertDontSee('tanggal=2026-11-07', false)->assertDontSee('tanggal=2026-11-08', false)->assertDontSee('tanggal=2026-11-14', false)
            ->assertDontSee('<option value="2026-11-07"', false)->assertDontSee('<option value="2026-11-08"', false)
            ->assertDontSee('Sabtu')->assertDontSee('Minggu');
    }

    public function test_friday_after_hours_and_sunday_default_to_monday(): void
    {
        $keti = $this->keti();
        foreach (['2026-11-06 23:00', '2026-11-08 08:00'] as $now) {
            $this->travelTo(CarbonImmutable::parse($now));
            $response = $this->actingAs($keti)->get(route('keti.rooms.calendar'))->assertOk();
            $this->assertSame('2026-11-09', $response->viewData('date')->toDateString());
            $this->assertTrue($response->viewData('showingNextTeachingDay'));
        }
    }

    public function test_old_weekend_calendar_link_redirects_to_a_valid_calendar_without_a_loop(): void
    {
        $url = route('keti.rooms.calendar', ['tanggal' => '2026-11-07']);
        $this->actingAs($this->keti())->from($url)->get($url)
            ->assertRedirect(route('keti.rooms.calendar'))
            ->assertSessionHasErrors(['tanggal' => 'Kalender pemesanan hanya menampilkan Senin–Jumat. Pilih hari kuliah.']);
        $this->get(route('keti.rooms.calendar'))->assertOk();
    }

    public function test_explicit_unavailable_time_is_preserved_and_use_outside_operating_hours_is_rejected(): void
    {
        $keti = $this->keti();
        $room = Room::factory()->create();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 23:40'));
        $response = $this->actingAs($keti)->get(route('keti.rooms.show', [
            'room' => $room->id, 'mulai' => '2026-11-05 22:00',
        ]))->assertOk()->assertSee('Waktu pilihan belum bisa dipesan');
        $this->assertSame('22:00', $response->viewData('start')->format('H:i'));
        $this->assertNull($response->viewData('suggestedStart'));
        $this->assertFalse($response->viewData('requested')->isAvailable());

        $this->postJson(route('keti.reservations.store'), [
            'room_id' => $room->id, 'start_at' => '2026-11-05 22:00', 'duration' => 60,
            'purpose' => 'Rapat', 'idempotency_key' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'OUTSIDE_OPERATING_HOURS');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_next_slot_skips_closure_day_and_never_enables_an_unbookable_room(): void
    {
        $keti = $this->keti();
        $room = Room::factory()->create();
        $this->block($room, '00:00', '23:59', ['source_type' => RoomBlockSource::Closure, 'schedule_version_id' => null]);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 23:40'));
        $response = $this->actingAs($keti)->get(route('keti.rooms.show', $room))->assertOk();
        $this->assertSame('2026-11-06 07:30', $response->viewData('start')->format('Y-m-d H:i'));

        $room->update(['is_bookable' => false]);
        $response = $this->get(route('keti.rooms.show', $room))->assertOk();
        $this->assertNull($response->viewData('suggestedStart'));
        $this->assertFalse($response->viewData('requested')->isAvailable());
    }

    public function test_selected_slot_preserves_time_duration_and_participants_and_books_once(): void
    {
        $keti = $this->keti();
        $room = Room::factory()->create(['capacity' => 30]);
        $this->actingAs($keti)->get(route('keti.rooms.calendar', ['tanggal' => '2026-11-05', 'durasi' => 90, 'orang' => 25]))
            ->assertOk()->assertSee('name="durasi" value="90"', false)->assertSee('name="orang" value="25"', false);
        $details = $this->get(route('keti.rooms.show', [
            'room' => $room->id, 'mulai' => '2026-11-05 13:10', 'durasi' => 90, 'orang' => 25,
        ]))->assertOk()->assertSee('13:10–14:40 WITA')
            ->assertViewHas('participants', 25)->assertViewHas('duration', 90);

        $payload = ['room_id' => $room->id, 'start_at' => '2026-11-05 13:10', 'duration' => 90,
            'purpose' => 'Kuliah pengganti', 'participants' => 25, 'idempotency_key' => $details->viewData('idempotencyKey')];
        $this->post(route('keti.reservations.store'), $payload)->assertRedirect();
        $reservation = Reservation::query()->sole();
        $this->assertSame('2026-11-05 13:10', $reservation->start_at->format('Y-m-d H:i'));
        $this->assertSame('14:40', $reservation->end_at->format('H:i'));
        $this->assertSame(25, $reservation->participants);

        $this->post(route('keti.reservations.store'), $payload)->assertRedirect(route('keti.reservations.show', $reservation));
        $this->assertDatabaseCount('reservations', 1);
        $response = $this->get(route('keti.rooms.calendar', ['tanggal' => '2026-11-05', 'durasi' => 90]))->assertOk();
        $windows = $response->viewData('rows')->sole()['windows'];
        $this->assertSame('13:10', $windows[0]['end']->format('H:i'));
        $this->assertSame('14:40', $windows[1]['start']->format('H:i'));

        $this->actingAs(User::factory()->approvedKeti()->create())->postJson(route('keti.reservations.store'), [
            ...$payload, 'idempotency_key' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'ROOM_CONFLICT');
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_calendar_and_booking_reject_uncertain_schedule_after_official_is_withdrawn(): void
    {
        $keti = $this->keti();
        $room = Room::factory()->create();
        $this->actingAs($keti)->get(route('keti.rooms.calendar'))->assertOk()->assertSee('Pesan');
        ScheduleVersion::query()->update(['status' => 'draft']);

        $response = $this->get(route('keti.rooms.calendar'))->assertOk()->assertSee('Jadwal resmi belum ditetapkan');
        $this->assertTrue($response->viewData('rows')->isEmpty());
        $this->postJson(route('keti.reservations.store'), [
            'room_id' => $room->id, 'start_at' => '2026-11-04 11:00', 'duration' => 60,
            'purpose' => 'Rapat', 'idempotency_key' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('code', 'SCHEDULE_UNCERTAIN');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_calendar_requires_an_approved_keti(): void
    {
        $this->get(route('keti.rooms.calendar'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->admin()->create())->get(route('keti.rooms.calendar'))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('keti.rooms.calendar'))->assertRedirect(route('keti.application'));
    }

    #[DataProvider('invalidFilters')]
    public function test_calendar_rejects_invalid_filters(array $filters, string $field): void
    {
        $this->actingAs($this->keti())->getJson(route('keti.rooms.calendar', $filters))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidFilters(): array
    {
        return [
            'past date' => [['tanggal' => '2026-11-03'], 'tanggal'],
            'beyond booking horizon' => [['tanggal' => '2026-11-12'], 'tanggal'],
            'invalid date' => [['tanggal' => '2026-02-31'], 'tanggal'],
            'Saturday' => [['tanggal' => '2026-11-07'], 'tanggal'],
            'Sunday' => [['tanggal' => '2026-11-08'], 'tanggal'],
            'short duration' => [['durasi' => 29], 'durasi'],
            'long duration' => [['durasi' => 201], 'durasi'],
            'invalid capacity' => [['orang' => 0], 'orang'],
            'invalid building' => [['gedung' => 'tidak-valid'], 'gedung'],
        ];
    }

    public function test_calendar_escapes_room_names(): void
    {
        $keti = $this->keti();
        Room::factory()->create(['name' => '<script>alert(1)</script>']);

        $this->actingAs($keti)->get(route('keti.rooms.calendar'))->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_keti_pages_offer_matching_sks_durations_and_default_to_two_sks(): void
    {
        $keti = $this->keti();
        $room = Room::factory()->create();
        foreach ([
            route('keti.search', ['tab' => 'filter']),
            route('keti.rooms.calendar'),
            route('keti.rooms.show', $room),
        ] as $url) {
            $this->actingAs($keti)->get($url)->assertOk()
                ->assertSee('2 SKS (100 menit)')->assertSee('3 SKS (150 menit)')->assertSee('4 SKS (200 menit)')
                ->assertDontSee('value="60"', false)
                ->assertViewHas('duration', 100);
        }
    }

    public function test_keti_pages_show_database_content_without_demo_or_simulation_labels(): void
    {
        config(['sicma.public_portal.show_sim_label' => true]);
        $keti = $this->keti();
        Room::factory()->create(['name' => 'Ruang aktual', 'capacity' => null, 'floor' => null]);
        foreach (['keti.search', 'keti.rooms.index', 'keti.rooms.calendar', 'keti.profile'] as $route) {
            $this->actingAs($keti)->get(route($route))->assertOk()
                ->assertDontSee('Data contoh')->assertDontSee('Data simulasi');
        }
        $this->get(route('keti.rooms.index'))->assertSee('Ruang aktual');
    }

    public function test_keti_can_book_four_sks_from_purpose_dropdown_without_participant_count(): void
    {
        $keti = $this->keti();
        $room = Room::factory()->create();
        $details = $this->actingAs($keti)->get(route('keti.rooms.show', [
            'room' => $room->id, 'mulai' => '2026-11-05 10:00', 'durasi' => 200,
        ]))->assertOk()->assertSee('10:00–13:20 WITA')
            ->assertSee('select name="purpose"', false)->assertDontSee('input name="purpose"', false)
            ->assertDontSee('name="participants"', false);
        foreach (['Kuliah Pengganti', 'Diskusi / Kerja Kelompok', 'Praktikum Tambahan', 'Seminar / Ujian', 'Kegiatan Organisasi / Himpunan', 'Lainnya'] as $purpose) {
            $details->assertSee('<option value="'.$purpose.'"', false);
        }

        $this->postJson(route('keti.reservations.store'), [
            'room_id' => $room->id, 'start_at' => '2026-11-05 10:00', 'duration' => 200,
            'purpose' => 'Kuliah Pengganti', 'idempotency_key' => $details->viewData('idempotencyKey'),
        ])->assertCreated();
        $reservation = Reservation::query()->sole();
        $this->assertNull($reservation->participants);
        $this->assertSame('Kuliah Pengganti', $reservation->purpose);
        $this->assertSame('13:20', $reservation->end_at->format('H:i'));
        $this->assertSame('13:20', $reservation->block->end_at->format('H:i'));
    }

    public function test_sks_booking_still_rejects_excessive_duration_and_passing_closing_time(): void
    {
        $keti = $this->keti();
        $room = Room::factory()->create();
        $payload = ['room_id' => $room->id, 'purpose' => 'Lainnya', 'idempotency_key' => (string) Str::uuid()];
        $this->actingAs($keti)->postJson(route('keti.reservations.store'), [
            ...$payload, 'start_at' => '2026-11-05 10:00', 'duration' => 201,
        ])->assertUnprocessable()->assertJsonValidationErrors('duration');
        $this->postJson(route('keti.reservations.store'), [
            ...$payload, 'start_at' => '2026-11-05 15:00', 'duration' => 200,
        ])->assertConflict()->assertJsonPath('code', 'OUTSIDE_OPERATING_HOURS');
        $this->assertDatabaseCount('reservations', 0);
    }
}
