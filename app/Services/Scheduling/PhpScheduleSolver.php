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
        if (! $this->validInput($input)) {
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
                        $candidate = [$day, $start, $end, $room['id'], $this->cost($session, $day, $start, $room['id']) + $shortage];
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
    private function resolveEnd(array $slots, int $start, int $duration): int
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
    private function covered(array $slots, int $start, int $end): bool
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
    private function overlapsFridayBreak(int $day, int $start, int $end, array $input): bool
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
    private function roomBlocked(array $session, array $candidate, array $input): bool
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
    private function resources(array $session, ?int $room): array
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

    /** @param array<string, mixed> $session */
    private function cost(array $session, int $day, int $start, ?int $room): int
    {
        $preferred = $session['preferred'] ?? null;
        $pattern = ($preferred === null || $preferred['start'] !== $start)
            ? $this->slotPatternCost((int) ($session['duration'] ?? 0), $day, $start)
                + $this->cohortPatternCost($session['cohort_profile'] ?? null, $start)
            : 0;

        return ($preferred === null ? $day : (int) ($preferred['day'] !== $day) * 6
            + (int) ($preferred['start'] !== $start) * 3 + (int) ($preferred['room'] !== $room) * 2) + $pattern;
    }

    private function cohortPatternCost(?string $cohortProfile, int $start): int
    {
        return match ($cohortProfile) {
            'ulang_senior' => $start < 720 ? 0 : ($start < 890 ? 2 : 5),
            'maba' => $start < 890 ? 0 : 4,
            default => 0,
        };
    }

    private function slotPatternCost(int $duration, int $day, int $start): int
    {
        if ($duration >= 190) {
            return match ($start) {
                450 => 0,
                790 => 1,
                default => 6,
            };
        }
        if ($duration >= 140) {
            $primary = $day === 5 ? [450, 550, 555, 890] : [450, 605, 890];

            return in_array($start, $primary, true) ? 0 : ($start === 790 ? 2 : 6);
        }
        if ($duration >= 90) {
            $primary = $day === 5 ? [450, 605, 790] : [450, 550, 555, 660, 790];

            return in_array($start, $primary, true) ? 0 : (in_array($start, [890, 940], true) ? 2 : 6);
        }

        return 0;
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

    /**
     * Pemeriksaan keluaran tidak mempercayai status FEASIBLE dari mesin pencari.
     *
     * @param  array<string, mixed>  $input
     * @param  list<array<string, mixed>>  $assignments
     */
    public function accepts(array $input, array $assignments): bool
    {
        if (! $this->validInput($input)) {
            return false;
        }
        $sessions = array_column($input['sessions'], null, 'id');
        $rooms = array_column($input['rooms'] ?? [], null, 'id');
        $slots = $input['slots'];
        usort($slots, fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $busy = [];
        $seen = [];
        $fixed = $input['fixed'] ?? [];
        $assignedIds = array_column($assignments, 'id');
        foreach ($sessions as $id => $session) {
            if (isset($session['locked']) && ! in_array($id, $assignedIds, true)) {
                $fixed[] = [...$session, 'day' => $session['locked']['day'], 'start' => $session['locked']['start'],
                    'end' => $session['locked']['start'] + $session['duration'], 'room' => $session['mode'] === 'online' ? null : $session['locked']['room']];
            }
        }
        foreach ($fixed as $row) {
            foreach ($this->resources($row, $row['room']) as $resource) {
                $busy[$resource][$row['day']][] = [$row['start'], $row['end']];
            }
        }
        foreach ($assignments as $row) {
            if (! is_array($row) || ! is_int($row['id'] ?? null) || ! isset($sessions[$row['id']]) || isset($seen[$row['id']])
                || ! $this->validInterval($row['start_minute'] ?? null, $row['end_minute'] ?? null)
                || ! in_array($row['day'] ?? null, $input['days'], true) || ! array_key_exists('room_id', $row)) {
                return false;
            }
            $session = $sessions[$row['id']];
            $room = $row['room_id'];
            $expectedEnd = isset($session['locked']) ? $row['start_minute'] + $session['duration'] : $this->resolveEnd($slots, $row['start_minute'], $session['duration']);
            if (($session['mode'] === 'online' ? $room !== null : ! is_int($room) || ! isset($rooms[$room]))
                || ($row['end_minute'] !== $expectedEnd && $session['duration'] !== $row['end_minute'] - $row['start_minute'])
                || $this->overlapsFridayBreak($row['day'], $row['start_minute'], $row['end_minute'], $input)
                || ! $this->covered($slots, $row['start_minute'], $row['end_minute'])
                || ! $this->available($session, $row['day'], $row['start_minute'], $row['end_minute'], $input)
                || ($row['day'] === 4 && array_intersect($session['lecturers'], $input['leaders'] ?? []) !== [])
                || (isset($session['locked']) && [$row['day'], $row['start_minute'], $room] !== [$session['locked']['day'], $session['locked']['start'], $session['mode'] === 'online' ? null : $session['locked']['room']])
                || (($input['capacity_blocks_publish'] ?? false) && $room !== null && ($session['students'] ?? null) !== null
                    && $rooms[$room]['capacity'] !== null && $session['students'] > $rooms[$room]['capacity'])
                || $this->roomBlocked($session, [$row['day'], $row['start_minute'], $row['end_minute'], $room, 0], $input)) {
                return false;
            }
            foreach ($this->resources($session, $room) as $resource) {
                foreach ($busy[$resource][$row['day']] ?? [] as [$start, $end]) {
                    if ($row['start_minute'] < $end && $start < $row['end_minute']) {
                        return false;
                    }
                }
                $busy[$resource][$row['day']][] = [$row['start_minute'], $row['end_minute']];
            }
            $seen[$row['id']] = true;
        }

        return true;
    }

    /** @param array<string, mixed> $input */
    private function validInput(array $input): bool
    {
        if (isset($input['time_limit']) && ((! is_int($input['time_limit']) && ! is_float($input['time_limit']))
            || ! is_finite((float) $input['time_limit']) || $input['time_limit'] < 0)) {
            return false;
        }
        foreach (['sessions', 'slots', 'days'] as $key) {
            if (! is_array($input[$key] ?? null) || $input[$key] === []) {
                return false;
            }
        }
        foreach (['fixed', 'rooms', 'leaders', 'availability', 'unavailable', 'room_blocks', 'friday_break'] as $key) {
            if (isset($input[$key]) && ! is_array($input[$key])) {
                return false;
            }
        }
        if (isset($input['friday_break']) && ! $this->validInterval($input['friday_break']['start'] ?? null, $input['friday_break']['end'] ?? null)) {
            return false;
        }
        if (! $this->ids($input['days']) || array_any($input['days'], fn (int $day): bool => $day > 7)
            || ! $this->ids($input['leaders'] ?? [])) {
            return false;
        }
        foreach ($input['slots'] as $slot) {
            if (! is_array($slot) || ! $this->validInterval($slot['start'] ?? null, $slot['end'] ?? null)) {
                return false;
            }
        }
        $roomIds = [];
        foreach ($input['rooms'] ?? [] as $room) {
            if (! is_array($room) || ! $this->ids([$room['id'] ?? null]) || isset($roomIds[$room['id']])
                || ! array_key_exists('capacity', $room) || ($room['capacity'] !== null && (! is_int($room['capacity']) || $room['capacity'] < 0))) {
                return false;
            }
            $roomIds[$room['id']] = true;
        }
        $ids = [];
        foreach ($input['sessions'] as $session) {
            if (! is_array($session) || ! $this->ids([$session['id'] ?? null]) || isset($ids[$session['id']])
                || ! is_int($session['duration'] ?? null) || $session['duration'] <= 0 || $session['duration'] > 1440
                || ! in_array($session['mode'] ?? null, ['online', 'offline'], true) || ! $this->validResources($session)
                || (isset($session['students']) && (! is_int($session['students']) || $session['students'] < 0))
                || (isset($session['cohort_profile']) && ! in_array($session['cohort_profile'], ['maba', 'ulang_senior', 'reguler'], true))) {
                return false;
            }
            foreach (['locked', 'preferred'] as $key) {
                if (isset($session[$key]) && (! is_array($session[$key]) || ! $this->validDay($session[$key]['day'] ?? null)
                    || ! is_int($session[$key]['start'] ?? null) || $session[$key]['start'] < 0 || $session[$key]['start'] >= 1440
                    || ! array_key_exists('room', $session[$key]) || ($session[$key]['room'] !== null && ! $this->ids([$session[$key]['room']])))) {
                    return false;
                }
            }
            $ids[$session['id']] = true;
        }
        foreach ($input['fixed'] ?? [] as $fixed) {
            if (! is_array($fixed) || ! $this->validResources($fixed) || ! $this->validDay($fixed['day'] ?? null)
                || ! $this->validInterval($fixed['start'] ?? null, $fixed['end'] ?? null) || ! array_key_exists('room', $fixed)
                || ($fixed['room'] !== null && ! $this->ids([$fixed['room']]))) {
                return false;
            }
        }
        foreach ($input['availability'] ?? $input['unavailable'] ?? [] as $entry) {
            if (! is_array($entry) || ! $this->ids([$entry['lecturer'] ?? null]) || ! $this->validDay($entry['day'] ?? null)
                || ! $this->validInterval($entry['start'] ?? null, $entry['end'] ?? null)
                || ! in_array($entry['type'] ?? 'unavailable', ['available', 'unavailable'], true)) {
                return false;
            }
        }
        foreach ($input['room_blocks'] ?? [] as $block) {
            if (! is_array($block) || ! $this->ids([$block['room'] ?? null]) || ! $this->validDay($block['day'] ?? null)
                || ! $this->validInterval($block['start'] ?? null, $block['end'] ?? null) || ! is_string($block['date'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $session */
    private function validResources(array $session): bool
    {
        return in_array($session['phase'] ?? null, ['full', 'before_uts', 'after_uts'], true)
            && $this->ids($session['lecturers'] ?? null) && $this->ids($session['classes'] ?? null);
    }

    private function ids(mixed $ids): bool
    {
        return is_array($ids) && array_all($ids, fn (mixed $id): bool => is_int($id) && $id > 0) && count($ids) === count(array_unique($ids));
    }

    private function validDay(mixed $day): bool
    {
        return is_int($day) && $day >= 1 && $day <= 7;
    }

    private function validInterval(mixed $start, mixed $end): bool
    {
        return is_int($start) && is_int($end) && $start >= 0 && $end <= 1440 && $end > $start;
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
