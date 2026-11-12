<?php

use App\Enums\RescheduleStatus;
use App\Enums\ReservationStatus;
use App\Enums\SessionPhase;
use App\Enums\TeachingRole;
use App\Models\Lecturer\Lecturer;
use App\Models\Reservation\Reservation;
use App\Models\Room\Room;
use App\Models\Room\RoomBlock;
use App\Models\User\AppNotification;
use App\Models\User\User;
use App\Services\Academic\MeetingRescheduleService;
use App\Services\Room\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    officialVersion();
    $this->admin = User::factory()->admin()->create();
    $this->keti = User::factory()->approvedKeti()->create();
    $this->room = Room::factory()->create(['name' => 'AE 106']);
});

test('admin diberi tahu saat reservasi dibuat, dibatalkan Keti, dan selesai digunakan', function () {
    $service = app(ReservationService::class);
    $reservation = $service->create($this->keti, $this->room, now(), now()->addHour(), 'Kelas pengganti', 20, 'k1');

    expect(AppNotification::query()->where('user_id', $this->admin->id)->where('type', 'reservation.booked')->value('title'))->toBe('Reservasi baru: AE 106');

    $service->start($reservation, $this->keti);
    $service->finish($reservation->fresh(), $this->keti);
    expect(AppNotification::query()->where('user_id', $this->admin->id)->where('type', 'reservation.finished')->exists())->toBeTrue();

    $other = $service->create($this->keti, $this->room, now()->addHours(2), now()->addHours(3), 'Diskusi', 20, 'k2');
    $service->cancel($other, $this->keti);
    expect(AppNotification::query()->where('user_id', $this->admin->id)->where('type', 'reservation.cancelled')->exists())->toBeTrue();
});

test('reservasi yang tidak dimulai dan pemakaian yang melewati batas ditandai sekali tanpa mengubah status', function () {
    $notStarted = Reservation::factory()->for($this->room)->for($this->keti, 'creator')->create(['start_at' => '2026-11-04 09:00', 'end_at' => '2026-11-04 10:30']);
    $overrun = Reservation::factory()->for($this->keti, 'creator')->create(['status' => ReservationStatus::InUse, 'start_at' => '2026-11-04 08:00', 'end_at' => '2026-11-04 09:30', 'actual_start_at' => '2026-11-04 08:00']);
    Reservation::factory()->for($this->keti, 'creator')->create(['start_at' => '2026-11-04 09:55', 'end_at' => '2026-11-04 11:00']);

    $this->artisan('sicma:flag-overdue')->expectsOutputToContain('Belum dimulai: 1 • Melewati batas: 1')->assertSuccessful();
    $this->artisan('sicma:flag-overdue')->expectsOutputToContain('Belum dimulai: 0 • Melewati batas: 0')->assertSuccessful();

    expect($notStarted->fresh()->status)->toBe(ReservationStatus::Booked)
        ->and($overrun->fresh()->status)->toBe(ReservationStatus::InUse)
        ->and(AppNotification::query()->where('user_id', $this->keti->id)->where('type', 'reservation.not_started')->count())->toBe(1)
        ->and(AppNotification::query()->where('user_id', $this->admin->id)->where('type', 'reservation.overrun')->count())->toBe(1);

    $this->withoutVite()->actingAs($this->admin)->get(route('admin.dashboard'))->assertSee('Perlu perhatian')->assertSee('belum menekan Mulai gunakan');
});

test('pembatalan otomatis hanya berjalan bila kebijakannya diaktifkan', function () {
    config(['sicma.reservation.auto_expire_no_show' => true]);
    $reservation = Reservation::factory()->for($this->room)->for($this->keti, 'creator')->create(['start_at' => '2026-11-04 09:00', 'end_at' => '2026-11-04 10:30']);
    RoomBlock::factory()->for($this->room)->create(['start_at' => '2026-11-04 09:00', 'end_at' => '2026-11-04 10:30', 'source_id' => $reservation->id]);

    $this->artisan('sicma:flag-overdue')->assertSuccessful();

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Cancelled)
        ->and($reservation->block()->first()->is_active)->toBeFalse();
});

test('pembatalan otomatis pada reservasi pemindahan membatalkan SessionReschedule dan memulihkan blok asli jika belum lewat', function () {
    config(['sicma.reservation.auto_expire_no_show' => true]);
    $lecturer = Lecturer::factory()->create();
    $team = [[$lecturer, TeachingRole::Lead, SessionPhase::Full]];
    $session = meetingSession([$this->keti->activeClassGroup()], ['title' => 'Algoritma', 'day_of_week' => 5, 'start_minute' => 450, 'end_minute' => 550, 'room_id' => $this->room->id], $team);
    $originalBlock = RoomBlock::query()->where('source_id', $session->id)->where('start_at', '2026-11-06 07:30:00')->first();

    $newRoom = Room::factory()->create();
    $reschedule = app(MeetingRescheduleService::class)->move(
        $this->keti,
        $session,
        CarbonImmutable::parse('2026-11-06'),
        CarbonImmutable::parse('2026-11-04 10:00'),
        $newRoom,
        [$lecturer->id],
        (string) str()->uuid()
    );

    expect($originalBlock->fresh()->is_active)->toBeFalse();

    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:20'));

    $this->artisan('sicma:flag-overdue')->assertSuccessful();

    expect($reschedule->reservation->fresh()->status)->toBe(ReservationStatus::Cancelled)
        ->and($reschedule->fresh()->status)->toBe(RescheduleStatus::Cancelled)
        ->and($originalBlock->fresh()->is_active)->toBeTrue();
});

test('command penanda terjadwal setiap lima menit', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'sicma:flag-overdue'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *');
});
