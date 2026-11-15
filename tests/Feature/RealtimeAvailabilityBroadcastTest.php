<?php

use App\Enums\ReservationStatus;
use App\Events\RoomAvailabilityUpdated;
use App\Models\Room\Room;
use App\Models\User\User;
use App\Services\Room\ReservationService;
use App\Support\AvailabilityCache;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

test('AvailabilityCache::bust menyiarkan RoomAvailabilityUpdated setelah transaksi commit dengan versi dan payload yang sesuai', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
    Cache::forget('sicma:availability:version');
    Event::fake([RoomAvailabilityUpdated::class]);

    DB::transaction(function (): void {
        AvailabilityCache::bust();
        Event::assertNotDispatched(RoomAvailabilityUpdated::class);
    });

    Event::assertDispatchedTimes(RoomAvailabilityUpdated::class, 1);
    Event::assertDispatched(RoomAvailabilityUpdated::class, function (RoomAvailabilityUpdated $event): bool {
        $channels = $event->broadcastOn();

        return $event instanceof ShouldBroadcastNow
            && count($channels) === 1
            && $channels[0] instanceof Channel
            && $channels[0]->name === 'sicma.availability'
            && $event->broadcastAs() === 'availability.updated'
            && $event->broadcastWith() === [
                'version' => 2,
                'updated_at' => '2026-11-04T10:00:00+08:00',
            ];
    });

    AvailabilityCache::bust();

    Event::assertDispatchedTimes(RoomAvailabilityUpdated::class, 2);
    Event::assertDispatched(RoomAvailabilityUpdated::class, function (RoomAvailabilityUpdated $event): bool {
        return $event->version === 3
            && $event->broadcastWith()['version'] === 3;
    });
});

test('AvailabilityCache::bust tidak menyiarkan event saat transaksi rollback atau realtime nonaktif', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
    Cache::forget('sicma:availability:version');
    Event::fake([RoomAvailabilityUpdated::class]);

    try {
        DB::transaction(function (): void {
            AvailabilityCache::bust();
            throw new RuntimeException('Batalkan transaksi');
        });
    } catch (RuntimeException) {
        // Transaksi sengaja digagalkan untuk menguji rollback afterCommit.
    }

    Event::assertNotDispatched(RoomAvailabilityUpdated::class);

    config(['sicma.realtime.enabled' => false]);
    AvailabilityCache::bust();

    Event::assertNotDispatched(RoomAvailabilityUpdated::class);
    expect(Cache::get('sicma:availability:version'))->toBe(2);
});

test('ReservationService::create menyiarkan RoomAvailabilityUpdated', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
    officialVersion();
    $keti = User::factory()->approvedKeti()->create();
    $room = Room::factory()->create();
    Cache::forget('sicma:availability:version');
    Event::fake([RoomAvailabilityUpdated::class]);

    $reservation = app(ReservationService::class)->create(
        $keti,
        $room,
        now(),
        now()->addHour(),
        'Diskusi / Kerja Kelompok',
        15,
        'realtime-create-key',
    );

    expect($reservation->exists)->toBeTrue();
    Event::assertDispatchedTimes(RoomAvailabilityUpdated::class, 1);
    Event::assertDispatched(RoomAvailabilityUpdated::class, function (RoomAvailabilityUpdated $event): bool {
        return $event->version === 2
            && $event->updatedAt === '2026-11-04T10:00:00+08:00';
    });
});

test('ReservationService::start menyiarkan RoomAvailabilityUpdated', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
    officialVersion();
    $keti = User::factory()->approvedKeti()->create();
    $room = Room::factory()->create();
    $service = app(ReservationService::class);
    $reservation = $service->create(
        $keti,
        $room,
        now(),
        now()->addHour(),
        'Diskusi / Kerja Kelompok',
        15,
        'realtime-start-key',
    );
    Cache::forget('sicma:availability:version');
    Event::fake([RoomAvailabilityUpdated::class]);

    $started = $service->start($reservation, $keti);

    expect($started->status)->toBe(ReservationStatus::InUse);
    Event::assertDispatchedTimes(RoomAvailabilityUpdated::class, 1);
    Event::assertDispatched(RoomAvailabilityUpdated::class, function (RoomAvailabilityUpdated $event): bool {
        return $event->version === 2;
    });
});

test('ReservationService::cancel menyiarkan RoomAvailabilityUpdated', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
    officialVersion();
    $keti = User::factory()->approvedKeti()->create();
    $room = Room::factory()->create();
    $service = app(ReservationService::class);
    $reservation = $service->create(
        $keti,
        $room,
        now(),
        now()->addHour(),
        'Diskusi / Kerja Kelompok',
        15,
        'realtime-cancel-key',
    );
    Cache::forget('sicma:availability:version');
    Event::fake([RoomAvailabilityUpdated::class]);

    $cancelled = $service->cancel($reservation, $keti);

    expect($cancelled->status)->toBe(ReservationStatus::Cancelled);
    Event::assertDispatchedTimes(RoomAvailabilityUpdated::class, 1);
    Event::assertDispatched(RoomAvailabilityUpdated::class, function (RoomAvailabilityUpdated $event): bool {
        return $event->version === 2;
    });
});

test('route home merender meta reverb-config saat broadcaster reverb dan realtime aktif', function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
    officialVersion();
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'sicma-key',
        'broadcasting.connections.reverb.client.host' => null,
        'broadcasting.connections.reverb.client.port' => 8080,
        'broadcasting.connections.reverb.client.scheme' => 'http',
        'sicma.realtime.enabled' => true,
        'sicma.realtime.channel' => 'sicma.availability',
        'sicma.realtime.event' => 'availability.updated',
    ]);

    $response = $this->get(route('home'));

    $response->assertOk()->assertSee('<meta name="reverb-config"', false);
    preg_match('/<meta name="reverb-config" content="([^"]+)">/', $response->getContent(), $matches);
    expect($matches)->toHaveKey(1);

    $payload = json_decode(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);
    expect($payload)->toBe([
        'key' => 'sicma-key',
        'wsHost' => null,
        'wsPort' => 8080,
        'wssPort' => 8080,
        'forceTLS' => false,
        'channel' => 'sicma.availability',
        'event' => 'availability.updated',
    ]);
});

test('layouts.partials.head mengatur forceTLS dan host klien dari konfigurasi reverb', function () {
    $this->withoutVite();
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'prod-key',
        'broadcasting.connections.reverb.client.host' => 'ws.sicma.test',
        'broadcasting.connections.reverb.client.port' => 443,
        'broadcasting.connections.reverb.client.scheme' => 'https',
        'sicma.realtime.enabled' => true,
        'sicma.realtime.channel' => 'custom.channel',
        'sicma.realtime.event' => 'availability.updated',
    ]);

    $html = view('layouts.partials.head')->render();

    preg_match('/<meta name="reverb-config" content="([^"]+)">/', $html, $matches);
    expect($matches)->toHaveKey(1);

    $payload = json_decode(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);
    expect($payload)->toBe([
        'key' => 'prod-key',
        'wsHost' => 'ws.sicma.test',
        'wsPort' => 443,
        'wssPort' => 443,
        'forceTLS' => true,
        'channel' => 'custom.channel',
        'event' => 'availability.updated',
    ]);
});

test('route home tidak merender meta reverb-config saat broadcaster bukan reverb, realtime nonaktif, atau key kosong', function (array $overrides) {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
    officialVersion();
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'sicma-key',
        'sicma.realtime.enabled' => true,
        ...$overrides,
    ]);

    $response = $this->get(route('home'));

    $response->assertOk()->assertDontSee('name="reverb-config"', false);
})->with([
    'broadcaster null' => [['broadcasting.default' => 'null']],
    'realtime nonaktif' => [['sicma.realtime.enabled' => false]],
    'reverb key kosong' => [['broadcasting.connections.reverb.key' => '']],
]);
