<?php

namespace App\Services\Scheduling;

use Closure;

/**
 * Pencarian kandidat dengan urutan paling terbatas dan perbaikan lokal berbatas.
 * Hasil lengkap disebut FEASIBLE; pencarian ini tidak membuktikan optimalitas global.
 */
class PhpScheduleSolver
{
    private array $sessions = [];

    private array $domains = [];

    private array $placed = [];

    private array $busy = [];

    private float $deadline = 0;

    private float $nextCancellationCheck = 0;

    private int $nodes = 0;

    private int $repairNodeLimit = 0;

    private bool $cancelled = false;

    private ?Closure $shouldCancel = null;

    private ?Closure $onProgress = null;

    private string $stage = 'Menyiapkan kandidat';

    private int $bestPlaced = 0;

    public function __construct(
        private ?SolverPayloadValidator $validator = null,
        private ?SolverCostCalculator $costCalculator = null,
    ) {
        $this->validator ??= new SolverPayloadValidator;
        $this->costCalculator ??= new SolverCostCalculator;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function solve(array $input, ?Closure $shouldCancel = null, ?Closure $onProgress = null): array
    {
        $started = $this->clock();
        $this->sessions = $this->domains = $this->placed = $this->busy = [];
        $this->nodes = 0;
        $this->cancelled = false;
        $this->nextCancellationCheck = 0;
        $this->shouldCancel = $shouldCancel;
        $this->onProgress = $onProgress;
        $this->stage = 'Menyiapkan kandidat';
        $this->bestPlaced = 0;
        if (! $this->validator->validInput($input)) {
            return $this->result('MODEL_INVALID', $started, 'Data sesi, slot, hari, ruang, atau ketersediaan tidak valid.');
        }
        $this->deadline = $started + (float) ($input['time_limit'] ?? 15);
        $slots = $input['slots'] ?? [];
        usort($slots, fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        foreach ($input['sessions'] ?? [] as $session) {
            $this->sessions[$session['id']] = $session;
        }
        if ($slots === [] || $this->sessions === [] || empty($input['days'])) {
            return $this->result('MODEL_INVALID', $started, 'Slot, hari, atau sesi belum tersedia.');
        }

        $fixedBusy = [];
        foreach ($input['fixed'] ?? [] as $index => $fixed) {
            $candidate = [$fixed['day'], $fixed['start'], $fixed['end'], $fixed['room'], 0];
            foreach ($this->resources($fixed, $fixed['room']) as $resource) {
                $fixedBusy[$resource][$fixed['day']][-1 - $index] = $candidate;
            }
        }
        foreach ($this->sessions as $id => $session) {
            if (! isset($session['locked'])) {
                continue;
            }
            $lock = $session['locked'];
            $room = $session['mode'] === 'online' ? null : $lock['room'];
            foreach ($this->resources($session, $room) as $resource) {
                $fixedBusy[$resource][$lock['day']][$id] = [$lock['day'], $lock['start'], $lock['start'] + $session['duration'], $room, 0];
            }
        }
        $this->busy = $fixedBusy;
        foreach ($this->sessions as $id => $session) {
            $options = [];
            $starts = array_column($slots, 'start');
            if (isset($session['preferred']['start'])) {
                $starts[] = $session['preferred']['start'];
            }
            if (isset($session['locked'])) {
                $starts = [$session['locked']['start']];
            }
            foreach ($input['days'] as $day) {
                if (isset($session['locked']) && $session['locked']['day'] !== $day) {
                    continue;
                }
                if ($day === 4 && array_intersect($session['lecturers'], $input['leaders'] ?? []) !== []) {
                    continue;
                }
                foreach (array_unique($starts) as $start) {
                    $end = isset($session['locked']) ? $start + $session['duration'] : $this->resolveEnd($slots, $start, $session['duration']);
                    if ($this->overlapsFridayBreak($day, $start, $end, $input)
                        || ! $this->covered($slots, $start, $end)
                        || ! $this->available($session, $day, $start, $end, $input)) {
                        continue;
                    }
                    $rooms = $session['mode'] === 'online' ? [['id' => null, 'capacity' => null]] : ($input['rooms'] ?? []);
                    foreach ($rooms as $room) {
                        if (isset($session['locked']) && $session['mode'] !== 'online' && $session['locked']['room'] !== $room['id']) {
                            continue;
                        }
                        $shortage = max(0, ($session['students'] ?? 0) - ($room['capacity'] ?? PHP_INT_MAX));
                        if ($shortage > 0 && ($input['capacity_blocks_publish'] ?? false)) {
                            continue;
                        }
                        $candidate = [$day, $start, $end, $room['id'], $this->costCalculator->cost($session, $day, $start, $room['id']) + $shortage];
                        if ($this->blockers($id, $candidate) === [] && ! $this->roomBlocked($session, $candidate, $input)) {
                            $options[] = $candidate;
                        }
                    }
                    if ($this->expired()) {
                        break 2;
                    }
                }
            }
            usort($options, fn (array $a, array $b): int => [$a[4], $a[0], $a[1], $a[3]] <=> [$b[4], $b[0], $b[1], $b[3]]);
            $this->domains[$id] = $options;
            if ($this->expired()) {
                return $this->result('UNKNOWN', $started, 'Batas pencarian tercapai saat menyiapkan kandidat.');
            }
        }

        $order = array_keys($this->sessions);
        usort($order, fn (int $a, int $b): int => [isset($this->sessions[$a]['locked']) ? 0 : 1, count($this->domains[$a]), -$this->sessions[$a]['duration'], $a]
            <=> [isset($this->sessions[$b]['locked']) ? 0 : 1, count($this->domains[$b]), -$this->sessions[$b]['duration'], $b]);
        $best = [];
        $bestCost = PHP_INT_MAX;
        $this->stage = 'Mencari susunan';
        $baseOrder = $order;
        $attempts = 0;
        for ($attempt = 0; ! $this->expired(); $attempt++) {
            $attempts++;
            $this->placed = [];
            $this->busy = $fixedBusy;
            foreach ($order as $id) {
                $this->repairNodeLimit = $this->nodes + 10000;
                $this->place($id, 0, []);
                if ($this->expired()) {
                    break;
                }
            }
            foreach (array_diff($order, array_keys($this->placed)) as $id) {
                $this->repairNodeLimit = $this->nodes + 5000;
                $this->place($id, 3, []);
                if ($this->expired()) {
                    break;
                }
            }
            $cost = array_sum(array_column($this->placed, 4));
            if (count($this->placed) > count($best) || (count($this->placed) === count($best) && $cost < $bestCost)) {
                $best = $this->placed;
                $bestCost = $cost;
            }
            if (count($best) === count($this->sessions)) {
                break;
            }
            $missing = array_diff(array_keys($this->sessions), array_keys($best));
            $order = $baseOrder;
            usort($order, fn (int $a, int $b): int => [isset($this->sessions[$a]['locked']) ? 0 : 1,
                in_array($a, $missing, true) ? 0 : 1, count($this->domains[$a]), crc32($attempt.':'.$a)]
                <=> [isset($this->sessions[$b]['locked']) ? 0 : 1, in_array($b, $missing, true) ? 0 : 1,
                    count($this->domains[$b]), crc32($attempt.':'.$b)]);
            if (array_any($this->domains, fn (array $domain): bool => $domain === []) && count($best) === count(array_filter($this->domains))) {
                break;
            }
        }
        $this->placed = $best;
        $complete = count($best) === count($this->sessions);
        $emptyDomain = in_array([], $this->domains, true);

        return [...$this->result($complete ? 'FEASIBLE' : ($emptyDomain && $best === [] ? 'INFEASIBLE' : 'UNKNOWN'), $started,
            $complete ? 'Susunan lengkap ditemukan dengan PHP; belum dibuktikan optimal.' : 'Belum ditemukan susunan lengkap. Periksa sesi yang belum ditempatkan atau perluas cakupan/waktu.'
        ), 'attempts' => $attempts];
    }

    /** @param list<array<string, mixed>> $slots */
    public function resolveEnd(array $slots, int $start, int $duration): int
    {
        if ($duration < 95) {
            return $start + $duration;
        }
        $count = $duration >= 190 ? 4 : ($duration >= 140 ? 3 : 2);
        foreach ($slots as $index => $slot) {
            if ($slot['start'] !== $start || ! isset($slots[$index + $count - 1])) {
                continue;
            }
            for ($step = 1; $step < $count; $step++) {
                if ($slots[$index + $step]['start'] > $slots[$index + $step - 1]['end'] + 15) {
                    return $start + $duration;
                }
            }
            $slotEnd = $slots[$index + $count - 1]['end'];
            if (abs(($slotEnd - $start) - $duration) <= 10) {
                return $slotEnd;
            }
        }

        return $start + $duration;
    }

    /** @param list<array<string, mixed>> $slots */
    public function covered(array $slots, int $start, int $end): bool
    {
        $slotEnds = array_column($slots, 'end');
        $reach = null;
        foreach ($slots as $slot) {
            if ($reach === null && $slot['start'] <= $start && $slot['end'] > $start) {
                $reach = $slot['end'];
            } elseif ($reach !== null && ($slot['start'] <= $reach + 15 || ($slot['start'] <= $reach + 30 && in_array($end, $slotEnds, true)))) {
                $reach = max($reach, $slot['end']);
            }
            if ($reach !== null && $reach >= $end) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $input */
    public function overlapsFridayBreak(int $day, int $start, int $end, array $input): bool
    {
        if ($day !== 5) {
            return false;
        }
        $break = $input['friday_break'] ?? ['start' => 720, 'end' => 790];

        return $start < $break['end'] && $end > $break['start'];
    }

    /** @param array<string, mixed> $session @param array<string, mixed> $input */
    public function available(array $session, int $day, int $start, int $end, array $input): bool
    {
        foreach ($session['lecturers'] as $lecturer) {
            $windows = [];
            foreach ($input['availability'] ?? $input['unavailable'] ?? [] as $entry) {
                if ($entry['lecturer'] !== $lecturer) {
                    continue;
                }
                if (($entry['type'] ?? 'unavailable') === 'available') {
                    $windows[] = $entry;
                } elseif ($entry['day'] === $day && $start < $entry['end'] && $entry['start'] < $end) {
                    return false;
                }
            }
            if ($windows !== []) {
                usort($windows, fn (array $a, array $b): int => $a['start'] <=> $b['start']);
                $reach = $start;
                foreach ($windows as $window) {
                    if ($window['day'] === $day && $window['start'] <= $reach) {
                        $reach = max($reach, $window['end']);
                    }
                }
                if ($reach < $end) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string, mixed> $session @param array<int, mixed> $candidate @param array<string, mixed> $input */
    public function roomBlocked(array $session, array $candidate, array $input): bool
    {
        foreach ($input['room_blocks'] ?? [] as $block) {
            if ($candidate[3] === null || $block['room'] !== $candidate[3] || $block['day'] !== $candidate[0]
                || $candidate[1] >= $block['end'] || $block['start'] >= $candidate[2]) {
                continue;
            }
            if ($session['phase'] === 'before_uts' && isset($input['uts_start']) && $block['date'] >= $input['uts_start']) {
                continue;
            }
            if ($session['phase'] === 'after_uts' && isset($input['uts_end']) && $block['date'] <= $input['uts_end']) {
                continue;
            }

            return true;
        }

        return false;
    }

    /** @param array<string, mixed> $session @return list<string> */
    public function resources(array $session, ?int $room): array
    {
        $worlds = match ($session['phase']) {
            'before_uts' => ['before'],
            'after_uts' => ['after'],
            default => ['before', 'after'],
        };
        $keys = [];
        foreach ($worlds as $world) {
            foreach ($session['lecturers'] as $id) {
                $keys[] = 'l:'.$id.':'.$world;
            }
            foreach ($session['classes'] as $id) {
                $keys[] = 'c:'.$id.':'.$world;
            }
            if ($room !== null) {
                $keys[] = 'r:'.$room.':'.$world;
            }
        }

        return $keys;
    }

    /**
     * Pemeriksaan keluaran tidak mempercayai status FEASIBLE dari mesin pencari.
     *
     * @param  array<string, mixed>  $input
     * @param  list<array<string, mixed>>  $assignments
     */
    public function accepts(array $input, array $assignments): bool
    {
        return $this->validator->accepts($input, $assignments, $this);
    }

    /** @param array<int, mixed> $candidate @return list<int> */
    private function blockers(int $id, array $candidate): array
    {
        $ids = [];
        foreach ($this->resources($this->sessions[$id], $candidate[3]) as $resource) {
            foreach ($this->busy[$resource][$candidate[0]] ?? [] as $otherId => $other) {
                if ($otherId !== $id && $candidate[1] < $other[2] && $other[1] < $candidate[2]) {
                    $ids[$otherId] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /** @param list<int> $ancestors */
    private function place(int $id, int $depth, array $ancestors): bool
    {
        if ($this->expired() || $this->nodes >= $this->repairNodeLimit) {
            return false;
        }
        $repairs = [];
        foreach ($this->domains[$id] as $candidate) {
            $this->nodes++;
            if ($this->expired() || $this->nodes >= $this->repairNodeLimit) {
                return false;
            }
            $blockers = $this->blockers($id, $candidate);
            if ($blockers === []) {
                $this->assign($id, $candidate);

                return true;
            }
            if ($depth > 0 && count($repairs) < 48 && count($blockers) <= 4
                && ! array_any($blockers, fn (int $other): bool => $other < 0 || isset($this->sessions[$other]['locked']) || in_array($other, $ancestors, true))) {
                $repairs[] = [$candidate, $blockers];
            }
        }
        usort($repairs, fn (array $a, array $b): int => [count($a[1]), $a[0][4]] <=> [count($b[1]), $b[0][4]]);
        $uniqueRepairs = [];
        $seenBlockers = [];
        foreach ($repairs as $repair) {
            $key = implode(',', $repair[1]);
            if (! isset($seenBlockers[$key])) {
                $seenBlockers[$key] = true;
                $uniqueRepairs[] = $repair;
            }
        }
        foreach (array_slice($uniqueRepairs, 0, 12) as [$candidate, $blockers]) {
            $previous = $this->placed;
            $busy = $this->busy;
            foreach ($blockers as $other) {
                $this->unassign($other);
            }
            $this->assign($id, $candidate);
            $success = true;
            foreach ($blockers as $other) {
                if (! $this->place($other, $depth - 1, [...$ancestors, $id])) {
                    $success = false;
                    break;
                }
            }
            if ($success) {
                return true;
            }
            $this->placed = $previous;
            $this->busy = $busy;
        }

        return false;
    }

    /** @param array<int, mixed> $candidate */
    private function assign(int $id, array $candidate): void
    {
        $this->placed[$id] = $candidate;
        foreach ($this->resources($this->sessions[$id], $candidate[3]) as $resource) {
            $this->busy[$resource][$candidate[0]][$id] = $candidate;
        }
    }

    private function unassign(int $id): void
    {
        $candidate = $this->placed[$id];
        unset($this->placed[$id]);
        foreach ($this->resources($this->sessions[$id], $candidate[3]) as $resource) {
            unset($this->busy[$resource][$candidate[0]][$id]);
        }
    }

    private function clock(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    private function expired(): bool
    {
        $now = $this->clock();
        if ($this->shouldCancel !== null && $now >= $this->nextCancellationCheck) {
            $this->cancelled = ($this->shouldCancel)();
            $this->nextCancellationCheck = $now + 0.25;
        }

        if ($this->stage === 'Mencari susunan') {
            $this->bestPlaced = max($this->bestPlaced, count($this->placed));
        }
        if ($this->onProgress !== null) {
            ($this->onProgress)(['stage' => $this->stage, 'total' => count($this->sessions), 'placed' => $this->bestPlaced]);
        }

        return $this->cancelled || $now >= $this->deadline;
    }

    /** @return array<string, mixed> */
    private function result(string $status, float $started, string $message): array
    {
        $assignments = [];
        foreach ($this->placed as $id => [$day, $start, $end, $room]) {
            $assignments[] = ['id' => $id, 'day' => $day, 'start_minute' => $start, 'end_minute' => $end, 'room_id' => $room];
        }
        $unplaced = array_values(array_diff(array_keys($this->sessions), array_keys($this->placed)));

        return [
            'status' => $status, 'message' => $message, 'assignments' => $assignments, 'unplaced' => $unplaced,
            'unplaced_details' => array_map(fn (int $id): array => ['id' => $id, 'reason' => ($this->domains[$id] ?? []) === []
                ? 'Tidak ada kandidat yang memenuhi slot, ruang, kunci, dan ketersediaan.' : 'Belum ditemukan kombinasi bebas bentrok dalam batas pencarian.'], $unplaced),
            'objective' => array_sum(array_column($this->placed, 4)), 'wall_time' => round($this->clock() - $started, 3),
        ];
    }
}
