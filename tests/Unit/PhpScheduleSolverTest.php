<?php

namespace Tests\Unit;

use App\Services\Scheduling\PhpScheduleSolver;
use PHPUnit\Framework\TestCase;

class PhpScheduleSolverTest extends TestCase
{
    public function test_progress_reports_real_placements_and_resets_for_each_search(): void
    {
        $solver = new PhpScheduleSolver;
        $progress = [];
        $result = $solver->solve($this->input([$this->session(1), $this->session(2)]), null,
            function (array $update) use (&$progress): void {
                $progress[] = $update;
            });

        $this->assertSame('FEASIBLE', $result['status']);
        $this->assertSame(['stage' => 'Menyiapkan kandidat', 'total' => 2, 'placed' => 0], $progress[0]);
        $this->assertSame(['stage' => 'Mencari susunan', 'total' => 2, 'placed' => 2], $progress[array_key_last($progress)]);
        $next = [];
        $solver->solve($this->input([$this->session(1)]), null,
            function (array $update) use (&$next): void {
                $next[] = $update;
            });
        $this->assertSame(0, $next[0]['placed']);
        $this->assertSame(1, $next[0]['total']);
    }

    private function session(int $id, array $overrides = []): array
    {
        return array_replace(['id' => $id, 'duration' => 50, 'mode' => 'offline', 'phase' => 'full',
            'lecturers' => [1], 'classes' => [1], 'students' => 30, 'locked' => null, 'preferred' => null], $overrides);
    }

    private function input(array $sessions, array $overrides = []): array
    {
        return array_replace(['time_limit' => 1, 'days' => [1], 'slots' => [['start' => 450, 'end' => 500], ['start' => 500, 'end' => 550]],
            'rooms' => [['id' => 1, 'capacity' => 40]], 'sessions' => $sessions, 'fixed' => []], $overrides);
    }

    public function test_shared_resources_use_non_overlapping_half_open_intervals(): void
    {
        $result = (new PhpScheduleSolver)->solve($this->input([$this->session(1), $this->session(2)]));

        $this->assertSame('FEASIBLE', $result['status']);
        $this->assertCount(2, $result['assignments']);
        $starts = array_column($result['assignments'], 'start_minute');
        sort($starts);
        $this->assertSame([450, 500], $starts);
    }

    public function test_scope_exclusions_and_locked_sessions_are_never_moved(): void
    {
        $input = $this->input([$this->session(1, ['locked' => ['day' => 1, 'start' => 500, 'room' => 1]])],
            ['fixed' => [['day' => 1, 'start' => 450, 'end' => 500, 'room' => 1, 'lecturers' => [2], 'classes' => [2], 'phase' => 'full']]]);
        $result = (new PhpScheduleSolver)->solve($input);

        $this->assertSame('FEASIBLE', $result['status']);
        $this->assertSame(500, $result['assignments'][0]['start_minute']);
    }

    public function test_lecturer_and_class_conflicts_are_checked_across_different_rooms(): void
    {
        foreach (['lecturers', 'classes'] as $resource) {
            $input = $this->input([$this->session(1, ['lecturers' => [9], 'classes' => [9], $resource => [1]])],
                ['fixed' => [['day' => 1, 'start' => 450, 'end' => 500, 'room' => 2, 'lecturers' => [1], 'classes' => [1], 'phase' => 'full']]]);
            $result = (new PhpScheduleSolver)->solve($input);
            $this->assertSame(500, $result['assignments'][0]['start_minute']);
        }
    }

    public function test_before_and_after_uts_can_reuse_the_same_resources(): void
    {
        $result = (new PhpScheduleSolver)->solve($this->input([$this->session(1, ['phase' => 'before_uts']), $this->session(2, ['phase' => 'after_uts'])],
            ['slots' => [['start' => 450, 'end' => 500]]]));

        $this->assertSame('FEASIBLE', $result['status']);
        $this->assertSame([450, 450], array_column($result['assignments'], 'start_minute'));
    }

    public function test_online_sessions_do_not_consume_a_room_but_still_block_the_lecturer(): void
    {
        $result = (new PhpScheduleSolver)->solve($this->input([$this->session(1, ['mode' => 'online']), $this->session(2, ['mode' => 'online'])], ['rooms' => []]));

        $this->assertSame('FEASIBLE', $result['status']);
        $this->assertSame([null, null], array_column($result['assignments'], 'room_id'));
        $this->assertCount(2, array_unique(array_column($result['assignments'], 'start_minute')));
    }

    public function test_locked_leaders_cannot_bypass_the_thursday_rule(): void
    {
        $result = (new PhpScheduleSolver)->solve($this->input([$this->session(1, ['locked' => ['day' => 4, 'start' => 450, 'room' => 1]])], ['days' => [4], 'leaders' => [1]]));

        $this->assertSame('INFEASIBLE', $result['status']);
        $this->assertSame([], $result['assignments']);
    }

    public function test_unavailability_and_explicit_available_windows_restrict_candidates(): void
    {
        $result = (new PhpScheduleSolver)->solve($this->input([$this->session(1)], ['availability' => [
            ['lecturer' => 1, 'type' => 'available', 'day' => 1, 'start' => 450, 'end' => 550],
            ['lecturer' => 1, 'type' => 'unavailable', 'day' => 1, 'start' => 450, 'end' => 500],
        ]]));

        $this->assertSame(500, $result['assignments'][0]['start_minute']);
    }

    public function test_capacity_obeys_the_configured_hard_or_warning_policy(): void
    {
        foreach ([false => 'FEASIBLE', true => 'INFEASIBLE'] as $hard => $status) {
            $result = (new PhpScheduleSolver)->solve($this->input([$this->session(1, ['students' => 90])], ['capacity_blocks_publish' => (bool) $hard]));
            $this->assertSame($status, $result['status']);
        }
    }

    public function test_room_closures_respect_actual_dates_and_session_phase(): void
    {
        $input = $this->input([$this->session(1, ['phase' => 'before_uts'])], ['uts_start' => '2026-10-12', 'uts_end' => '2026-10-18',
            'room_blocks' => [['room' => 1, 'date' => '2026-11-02', 'day' => 1, 'start' => 450, 'end' => 550]]]);
        $this->assertSame('FEASIBLE', (new PhpScheduleSolver)->solve($input)['status']);
        $input['sessions'][0]['phase'] = 'full';
        $this->assertSame('INFEASIBLE', (new PhpScheduleSolver)->solve($input)['status']);
    }

    public function test_partial_search_is_unknown_and_lists_unplaced_sessions(): void
    {
        $result = (new PhpScheduleSolver)->solve($this->input([$this->session(1), $this->session(2), $this->session(3)]));

        $this->assertSame('UNKNOWN', $result['status']);
        $this->assertCount(2, $result['assignments']);
        $this->assertCount(1, $result['unplaced']);
        $this->assertNotEmpty($result['unplaced_details'][0]['reason']);
    }

    public function test_long_sessions_cannot_bridge_a_large_gap_or_escape_the_slots(): void
    {
        $result = (new PhpScheduleSolver)->solve($this->input([$this->session(1, ['duration' => 100])],
            ['slots' => [['start' => 450, 'end' => 500], ['start' => 600, 'end' => 650]]]));
        $this->assertSame('INFEASIBLE', $result['status']);
    }

    public function test_preferred_placements_are_kept_when_legal(): void
    {
        $result = (new PhpScheduleSolver)->solve($this->input([$this->session(1, ['preferred' => ['day' => 1, 'start' => 500, 'room' => 1]])]));
        $this->assertSame(500, $result['assignments'][0]['start_minute']);
        $this->assertSame(0, $result['objective']);
    }

    public function test_expired_or_cancelled_search_does_not_claim_infeasibility(): void
    {
        $this->assertSame('UNKNOWN', (new PhpScheduleSolver)->solve($this->input([$this->session(1)], ['time_limit' => 0]))['status']);
        $this->assertSame('UNKNOWN', (new PhpScheduleSolver)->solve($this->input([$this->session(1)]), fn (): bool => true)['status']);
    }

    public function test_invalid_or_duplicate_sessions_are_rejected(): void
    {
        $this->assertSame('MODEL_INVALID', (new PhpScheduleSolver)->solve($this->input([$this->session(1), $this->session(1)]))['status']);
        $this->assertSame('MODEL_INVALID', (new PhpScheduleSolver)->solve($this->input([$this->session(1, ['duration' => 0])]))['status']);
    }

    public function test_malformed_nested_input_returns_model_invalid_instead_of_crashing(): void
    {
        foreach ([['days' => [0]], ['slots' => [['start' => 500, 'end' => 450]]], ['rooms' => [['id' => 1]]],
            ['sessions' => [['id' => 1]]], ['availability' => [['day' => 1]]], ['time_limit' => []]] as $invalid) {
            $this->assertSame('MODEL_INVALID', (new PhpScheduleSolver)->solve($this->input([$this->session(1)], $invalid))['status']);
        }
    }

    public function test_adjacent_available_windows_can_cover_one_complete_session(): void
    {
        $input = $this->input([$this->session(1, ['duration' => 100])], ['availability' => [
            ['lecturer' => 1, 'type' => 'available', 'day' => 1, 'start' => 450, 'end' => 500],
            ['lecturer' => 1, 'type' => 'available', 'day' => 1, 'start' => 500, 'end' => 550],
        ]]);
        $this->assertSame('FEASIBLE', (new PhpScheduleSolver)->solve($input)['status']);
        $input['availability'][1]['start'] = 501;
        $this->assertSame('INFEASIBLE', (new PhpScheduleSolver)->solve($input)['status']);
    }

    public function test_solution_validation_rejects_time_outside_slots_and_resource_conflicts(): void
    {
        $solver = new PhpScheduleSolver;
        $input = $this->input([$this->session(1), $this->session(2)]);
        $valid = [['id' => 1, 'day' => 1, 'start_minute' => 450, 'end_minute' => 500, 'room_id' => 1],
            ['id' => 2, 'day' => 1, 'start_minute' => 500, 'end_minute' => 550, 'room_id' => 1]];
        $this->assertTrue($solver->accepts($input, $valid));
        $outside = $valid;
        $outside[0]['start_minute'] = 0;
        $outside[0]['end_minute'] = 50;
        $this->assertFalse($solver->accepts($input, $outside));
        $overlap = $valid;
        $overlap[1]['start_minute'] = 450;
        $overlap[1]['end_minute'] = 500;
        $this->assertFalse($solver->accepts($input, $overlap));
        $this->assertFalse($solver->accepts($input, [['id' => []]]));
    }

    public function test_locked_sessions_remain_obstacles_even_when_they_violate_a_rule(): void
    {
        $input = $this->input([$this->session(1, ['locked' => ['day' => 1, 'start' => 450, 'room' => 1]]),
            $this->session(2, ['lecturers' => [2], 'classes' => [2]])], ['unavailable' => [
                ['lecturer' => 1, 'day' => 1, 'start' => 450, 'end' => 500],
            ]]);
        $result = (new PhpScheduleSolver)->solve($input);
        $this->assertSame([1], $result['unplaced']);
        $this->assertSame(500, $result['assignments'][0]['start_minute']);
        $this->assertTrue((new PhpScheduleSolver)->accepts($input, $result['assignments']));
    }

    public function test_solution_validation_checks_hard_capacity_and_lecturer_availability(): void
    {
        $solver = new PhpScheduleSolver;
        $input = $this->input([$this->session(1, ['students' => 50])], ['capacity_blocks_publish' => true]);
        $solution = [['id' => 1, 'day' => 1, 'start_minute' => 450, 'end_minute' => 500, 'room_id' => 1]];
        $this->assertFalse($solver->accepts($input, $solution));
        $input['capacity_blocks_publish'] = false;
        $input['unavailable'] = [['lecturer' => 1, 'day' => 1, 'start' => 450, 'end' => 500]];
        $this->assertFalse($solver->accepts($input, $solution));
    }

    public function test_local_repair_moves_an_earlier_session_to_complete_a_feasible_plan(): void
    {
        $input = $this->input([
            $this->session(1, ['lecturers' => [13], 'classes' => [1]]),
            $this->session(2, ['lecturers' => [24], 'classes' => [2]]),
            $this->session(3, ['lecturers' => [13, 34], 'classes' => [3]]),
            $this->session(4, ['lecturers' => [24, 34], 'classes' => [4]]),
        ], ['rooms' => [['id' => 1, 'capacity' => 40], ['id' => 2, 'capacity' => 40]]]);
        $solver = new PhpScheduleSolver;
        $result = $solver->solve($input);

        $this->assertSame('FEASIBLE', $result['status']);
        $this->assertCount(4, $result['assignments']);
        $this->assertTrue($solver->accepts($input, $result['assignments']));
    }
}
