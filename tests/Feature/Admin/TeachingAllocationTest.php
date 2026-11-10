<?php

namespace Tests\Feature\Admin;

use App\Models\Academic\Course;
use App\Models\Academic\CourseOffering;
use App\Models\Academic\Period;
use App\Models\Lecturer\Lecturer;
use App\Models\Lecturer\LecturerExpertise;
use App\Models\Lecturer\TeachingAssignment;
use App\Models\Scheduling\ScheduleSession;
use App\Models\Scheduling\ScheduleVersion;
use App\Models\User\User;
use App\Services\Academic\TeachingLoadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeachingAllocationTest extends TestCase
{
    use RefreshDatabase;

    private Period $period;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 11, 5)->setTime(9, 0));
        $this->period = Period::factory()->create();
        $this->admin = User::factory()->admin()->create();
    }

    private function lecturer(string $name, int $target, int $max): Lecturer
    {
        $lecturer = Lecturer::factory()->create(['name' => $name]);
        $lecturer->loadTargets()->create(['period_id' => $this->period->id, 'target_sks' => $target, 'max_sks' => $max]);

        return $lecturer;
    }

    private function assignment(Course $course, Lecturer $lecturer, bool $locked = false): TeachingAssignment
    {
        $offering = CourseOffering::factory()->for($this->period)->for($course)->create();

        return TeachingAssignment::factory()->for($offering, 'courseOffering')->for($lecturer)->create(['is_locked' => $locked]);
    }

    private function allowCourse(Lecturer $lecturer, Course $course): void
    {
        $lecturer->expertises()->create(['course_id' => $course->id, 'field' => LecturerExpertise::ADMIN_TEACHING]);
    }

    public function test_apply_balances_load_preserves_locks_and_prepares_a_schedule_draft(): void
    {
        $course = Course::factory()->create(['sks' => 3]);
        $donor = $this->lecturer('Dosen Beban Tinggi', 3, 6);
        $receiver = $this->lecturer('Dosen Penerima', 6, 6);
        $this->allowCourse($receiver, $course);
        $locked = $this->assignment($course, $donor, true);
        $first = $this->assignment($course, $donor);
        $second = $this->assignment($course, $donor);
        $official = ScheduleVersion::factory()->official()->for($this->period)->create();
        foreach ([$locked, $first, $second] as $assignment) {
            $session = ScheduleSession::factory()->for($official, 'version')->create(['course_offering_id' => $assignment->course_offering_id]);
            $session->lecturers()->attach($donor->id, ['role' => 'lead']);
            $session->participants()->attach($assignment->courseOffering->class_group_id);
        }

        $plan = $this->actingAs($this->admin)->get(route('admin.teaching.allocation.preview'))
            ->assertOk()->assertSee(['Perbandingan SKS Semester', 'Dosen Beban Tinggi', 'Dosen Penerima'])->viewData('plan');
        $this->assertCount(2, $plan['moves']);
        $this->assertSame($donor->id, $first->fresh()->lecturer_id);
        $this->post(route('admin.teaching.allocation.apply'), ['fingerprint' => $plan['fingerprint']])
            ->assertRedirect(route('admin.teaching.index'))->assertSessionHasNoErrors();

        $this->assertSame($donor->id, $locked->fresh()->lecturer_id);
        $this->assertSame($receiver->id, $first->fresh()->lecturer_id);
        $this->assertSame($receiver->id, $second->fresh()->lecturer_id);
        $summary = app(TeachingLoadService::class)->summary($this->period)->keyBy('lecturer.id');
        $this->assertSame(3.0, $summary[$donor->id]['assigned']);
        $this->assertSame(6.0, $summary[$receiver->id]['assigned']);
        $draft = ScheduleVersion::query()->findOrFail(session('allocation_draft'));
        $this->assertSame($official->id, $draft->based_on_version_id);
        $this->assertSame(3, $official->sessions()->whereHas('lecturers', fn ($query) => $query->where('lecturers.id', $donor->id))->count());
        $this->assertSame(2, $draft->sessions()->whereHas('lecturers', fn ($query) => $query->where('lecturers.id', $receiver->id))->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'teaching_assignment.reallocated', 'auditable_id' => $first->id]);
        $this->post(route('admin.teaching.allocation.apply'), ['fingerprint' => $plan['fingerprint']])->assertSessionHasErrors('fingerprint');
    }

    public function test_receivers_require_eligibility_active_verification_and_capacity(): void
    {
        $course = Course::factory()->create(['sks' => 3]);
        $donor = $this->lecturer('Dosen Sumber', 3, 3);
        $this->assignment($course, $donor);
        $this->assignment($course, $donor);
        $unqualified = $this->lecturer('Tanpa Keahlian', 3, 6);
        $otherPeriod = Period::factory()->create(['is_active' => false]);
        $otherOffering = CourseOffering::factory()->for($otherPeriod)->for($course)->create();
        TeachingAssignment::factory()->for($otherOffering, 'courseOffering')->for($unqualified)->create();
        $inactive = $this->lecturer('Nonaktif', 3, 6);
        $inactive->update(['is_active' => false]);
        $unverified = $this->lecturer('Belum Terverifikasi', 3, 6);
        $unverified->update(['profile_status' => 'unverified']);
        $full = $this->lecturer('Kapasitas Terbatas', 2, 2);
        $unknown = Lecturer::factory()->create();
        foreach ([$inactive, $unverified, $full, $unknown] as $lecturer) {
            $this->allowCourse($lecturer, $course);
        }

        $plan = $this->actingAs($this->admin)->get(route('admin.teaching.allocation.preview'))
            ->assertOk()->assertDontSee('Terapkan Pembagian Beban')->viewData('plan');
        $this->assertSame([], $plan['moves']);
        $this->assertNotEmpty($plan['warnings']);
        $this->assertSame(2, $donor->teachingAssignments()->count());
        $this->assertSame(1, $unqualified->teachingAssignments()->count());
    }

    public function test_a_dosen_already_in_the_same_team_cannot_receive_another_role(): void
    {
        $course = Course::factory()->create(['sks' => 3]);
        $donor = $this->lecturer('Pengampu', 3, 3);
        $partner = $this->lecturer('Mitra', 6, 9);
        $assignment = $this->assignment($course, $donor);
        TeachingAssignment::factory()->for($assignment->courseOffering, 'courseOffering')->for($partner)->create(['role' => 'partner']);
        $this->assignment($course, $donor, true);

        $plan = $this->actingAs($this->admin)->get(route('admin.teaching.allocation.preview'))->assertOk()->viewData('plan');
        $this->assertSame([], $plan['moves']);
    }

    public function test_changing_a_lock_after_preview_rejects_the_entire_proposal(): void
    {
        $course = Course::factory()->create(['sks' => 3]);
        $donor = $this->lecturer('Sumber', 3, 3);
        $receiver = $this->lecturer('Penerima', 3, 3);
        $this->allowCourse($receiver, $course);
        $this->assignment($course, $donor);
        $this->assignment($course, $donor);
        $plan = $this->actingAs($this->admin)->get(route('admin.teaching.allocation.preview'))->assertOk()->viewData('plan');
        TeachingAssignment::query()->whereKey($plan['moves'][0]['assignment_ids'])->update(['is_locked' => true]);

        $this->post(route('admin.teaching.allocation.apply'), ['fingerprint' => $plan['fingerprint']])->assertSessionHasErrors('fingerprint');
        $this->assertSame(0, $receiver->teachingAssignments()->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'teaching.balance_applied']);
    }

    public function test_multiple_phases_of_one_dosen_are_moved_and_counted_as_one_offering(): void
    {
        $course = Course::factory()->create(['sks' => 3]);
        $donor = $this->lecturer('Sumber', 3, 3);
        $receiver = $this->lecturer('Penerima', 3, 3);
        $this->allowCourse($receiver, $course);
        $assignment = $this->assignment($course, $donor);
        $assignment->update(['phase' => 'before_uts']);
        $otherPhase = TeachingAssignment::factory()->for($assignment->courseOffering, 'courseOffering')->for($donor)->create(['role' => 'partner', 'phase' => 'after_uts']);
        $this->assignment($course, $donor, true);

        $plan = $this->actingAs($this->admin)->get(route('admin.teaching.allocation.preview'))->assertOk()->viewData('plan');
        $this->assertCount(1, $plan['moves']);
        $this->assertCount(2, $plan['moves'][0]['assignment_ids']);
        $this->post(route('admin.teaching.allocation.apply'), ['fingerprint' => $plan['fingerprint']])->assertSessionHasNoErrors();
        $this->assertSame($receiver->id, $assignment->fresh()->lecturer_id);
        $this->assertSame($receiver->id, $otherPhase->fresh()->lecturer_id);
    }

    public function test_keti_cannot_preview_or_apply_allocation(): void
    {
        $this->actingAs(User::factory()->approvedKeti()->create())
            ->get(route('admin.teaching.allocation.preview'))->assertForbidden();
        $this->post(route('admin.teaching.allocation.apply'), ['fingerprint' => str_repeat('a', 64)])->assertForbidden();
    }
}
