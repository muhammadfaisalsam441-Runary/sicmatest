<?php

use App\Enums\RoomBlockSource;
use App\Enums\TeachingRole;
use App\Models\ClassGroup;
use App\Models\KetiApplication;
use App\Models\Lecturer;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\ScheduleSession;
use App\Models\User;

test('blok milik reservasi tidak tertukar dengan blok jadwal yang source_id-nya sama', function () {
    $reservation = Reservation::factory()->create();
    RoomBlock::factory()->for($reservation->room)->create([
        'source_type' => RoomBlockSource::Schedule,
        'source_id' => $reservation->id,
    ]);
    $reservationBlock = RoomBlock::factory()->for($reservation->room)->create([
        'source_type' => RoomBlockSource::Reservation,
        'source_id' => $reservation->id,
    ]);

    expect($reservation->block->is($reservationBlock))->toBeTrue();
});

test('sesi menyimpan kelas peserta dan peran dosen', function () {
    $session = ScheduleSession::factory()->create();
    $classGroups = ClassGroup::factory()->count(2)->create();
    $lead = Lecturer::factory()->create();
    $partner = Lecturer::factory()->create();

    $session->participants()->attach($classGroups);
    $session->lecturers()->attach([$lead->id => ['role' => 'lead'], $partner->id => ['role' => 'partner']]);

    $session->refresh();

    expect($session->participants)->toHaveCount(2)
        ->and($session->lecturers->firstWhere('id', $partner->id)->pivot->role)->toBe(TeachingRole::Partner->value);
});

test('ruang tanpa gedung tetap tersimpan dengan building kosong', function () {
    $room = Room::factory()->withoutBuilding()->create(['capacity' => null]);

    expect($room->building)->toBeNull()
        ->and($room->fresh()->capacity)->toBeNull();
});

test('pengajuan Keti terbaru diambil dari pengajuan paling akhir', function () {
    $user = User::factory()->create();
    KetiApplication::factory()->for($user)->needsRevision()->create(['created_at' => now()->subDay()]);
    $latest = KetiApplication::factory()->for($user)->create();

    expect($user->latestKetiApplication->is($latest))->toBeTrue();
});

test('favorit menghubungkan pengguna dan ruang', function () {
    $user = User::factory()->create();
    $room = Room::factory()->create();

    $user->favoriteRooms()->attach($room);

    expect($room->favoritedBy->pluck('id')->all())->toBe([$user->id]);
});

test('peran admin dan keti dibedakan', function () {
    expect(User::factory()->admin()->make()->isAdmin())->toBeTrue()
        ->and(User::factory()->superAdmin()->make()->isAdmin())->toBeTrue()
        ->and(User::factory()->make()->isAdmin())->toBeFalse()
        ->and(User::factory()->make()->isKeti())->toBeTrue();
});
