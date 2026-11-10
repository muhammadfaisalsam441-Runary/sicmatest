<?php

use App\Models\Academic\CalendarException;
use App\Models\Academic\Period;
use App\Models\User\User;

/*
 * Regresi: partial tab-semester memakai Wita:: dan harus mengimpornya sendiri
 * (@use di halaman induk tidak berlaku di dalam @include).
 */
test('tab Semester & Kalender menampilkan hari libur tanpa galat', function () {
    $this->withoutVite();
    $period = Period::factory()->create(['is_active' => true]);
    CalendarException::factory()->create(['period_id' => $period->id, 'date' => '2026-12-25', 'note' => 'Hari Raya Natal']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.academic.index', ['tab' => 'semester']))
        ->assertOk()
        ->assertSee('Hari Raya Natal');
});
