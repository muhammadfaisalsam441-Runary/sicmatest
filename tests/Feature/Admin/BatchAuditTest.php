<?php

use App\Enums\LecturerHomebase;
use App\Enums\LecturerProfileStatus;
use App\Enums\ScheduleVersionStatus;
use App\Models\Lecturer\Lecturer;
use App\Models\Room\Room;
use App\Models\Scheduling\ScheduleSession;
use App\Models\Scheduling\ScheduleVersion;
use App\Models\User\AuditLog;
use App\Models\User\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    officialVersion();
    $this->admin = User::factory()->admin()->create();
});

test('aksi massal ruang tercatat di audit log per ruang dengan nilai sebelum dan sesudah', function () {
    $rooms = Room::factory()->count(2)->create(['capacity' => 20]);

    $this->actingAs($this->admin)->post(route('admin.rooms.batch'), [
        'action' => 'set_capacity',
        'room_ids' => $rooms->pluck('id')->all(),
        'capacity' => 35,
    ])->assertSessionHasNoErrors();

    $logs = AuditLog::query()->where('action', 'room.batch_set_capacity')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs->pluck('auditable_id')->sort()->values()->all())->toBe($rooms->pluck('id')->sort()->values()->all())
        ->and($logs->first()->before['capacity'])->toBe(20)
        ->and($logs->first()->after['capacity'])->toBe(35)
        ->and($logs->first()->user_id)->toBe($this->admin->id);
});

test('aksi massal dosen tercatat di audit log', function () {
    $lecturers = Lecturer::factory()->count(3)->unverified()->create(['homebase' => null]);

    $this->actingAs($this->admin)->post(route('admin.lecturers.batch'), [
        'action' => 'homebase_jtik',
        'lecturer_ids' => $lecturers->pluck('id')->all(),
    ])->assertSessionHas('toast.title', 'Berhasil memperbarui 3 dosen');

    expect(Lecturer::query()->where('homebase', LecturerHomebase::Jtik)->count())->toBe(3)
        ->and(AuditLog::query()->where('action', 'lecturer.batch_homebase_jtik')->count())->toBe(3);

    $this->post(route('admin.lecturers.batch'), ['action' => 'verify', 'lecturer_ids' => [$lecturers->first()->id]]);

    expect($lecturers->first()->fresh()->profile_status)->toBe(LecturerProfileStatus::Verified)
        ->and(AuditLog::query()->where('action', 'lecturer.batch_verify')->value('before'))->toBe(['profile_status' => 'unverified', 'homebase' => 'jtik']);
});

test('target SKS massal menolak maksimum yang lebih kecil dari target', function () {
    $lecturer = Lecturer::factory()->create();

    $this->actingAs($this->admin)->post(route('admin.lecturers.batch'), [
        'action' => 'set_target_sks',
        'lecturer_ids' => [$lecturer->id],
        'target_sks' => 16,
        'max_sks' => 12,
    ])->assertSessionHasErrors(['max_sks' => 'Maksimum SKS harus lebih besar atau sama dengan target SKS.']);

    expect($lecturer->loadTargets()->count())->toBe(0);

    $this->post(route('admin.lecturers.batch'), [
        'action' => 'set_target_sks',
        'lecturer_ids' => [$lecturer->id],
        'target_sks' => 12,
        'max_sks' => 16,
    ])->assertSessionHasNoErrors();

    expect((float) $lecturer->loadTargets()->value('max_sks'))->toBe(16.0)
        ->and(AuditLog::query()->where('action', 'lecturer.batch_set_target_sks')->exists())->toBeTrue();
});

test('ruang yang masih dipakai draf jadwal tidak bisa dihapus', function () {
    $room = Room::factory()->create(['name' => 'AE 102']);
    $draft = ScheduleVersion::factory()->for(officialVersion()->period)->create(['status' => ScheduleVersionStatus::Draft, 'version_no' => 7]);
    ScheduleSession::factory()->for($draft, 'version')->create(['room_id' => $room->id]);

    $this->actingAs($this->admin)
        ->from(route('admin.rooms.index'))
        ->delete(route('admin.rooms.destroy', $room))
        ->assertSessionHas('error_code', 'ROOM_IN_USE')
        ->assertSessionHas('error', fn (string $message) => str_contains($message, 'draf v7'));

    expect($room->fresh()->trashed())->toBeFalse();
});

test('ruang yang dipakai jadwal lama yang masih berlaku tidak bisa dihapus', function () {
    $room = Room::factory()->create();
    $old = ScheduleVersion::factory()->for(officialVersion()->period)->create([
        'status' => ScheduleVersionStatus::Superseded, 'version_no' => 1, 'effective_to' => '2026-11-30',
    ]);
    ScheduleSession::factory()->for($old, 'version')->create(['room_id' => $room->id]);

    $this->actingAs($this->admin)->from(route('admin.rooms.index'))->delete(route('admin.rooms.destroy', $room))
        ->assertSessionHas('error_code', 'ROOM_IN_USE');

    $old->update(['effective_to' => '2026-10-31']);

    $this->delete(route('admin.rooms.destroy', $room))->assertRedirect(route('admin.rooms.index'));
    expect($room->fresh()->trashed())->toBeTrue();
});
