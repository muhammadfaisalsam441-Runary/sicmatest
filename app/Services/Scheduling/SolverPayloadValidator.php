<?php

namespace App\Services\Scheduling;

/**
 * Validator payload masukan dan verifikator kelayakan solusi hasil pencarian jadwal.
 */
class SolverPayloadValidator
{
    /**
     * Pemeriksaan keluaran tidak mempercayai status FEASIBLE dari mesin pencari.
     *
     * @param  array<string, mixed>  $input
     * @param  list<array<string, mixed>>  $assignments
     */
    public function accepts(array $input, array $assignments, ?PhpScheduleSolver $solver = null): bool
    {
        if (! $this->validInput($input)) {
            return false;
        }

        $solver ??= new PhpScheduleSolver($this);
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
            foreach ($solver->resources($row, $row['room']) as $resource) {
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
            $expectedEnd = isset($session['locked']) ? $row['start_minute'] + $session['duration'] : $solver->resolveEnd($slots, $row['start_minute'], $session['duration']);

            if (($session['mode'] === 'online' ? $room !== null : ! is_int($room) || ! isset($rooms[$room]))
                || ($row['end_minute'] !== $expectedEnd && $session['duration'] !== $row['end_minute'] - $row['start_minute'])
                || $solver->overlapsFridayBreak($row['day'], $row['start_minute'], $row['end_minute'], $input)
                || ! $solver->covered($slots, $row['start_minute'], $row['end_minute'])
                || ! $solver->available($session, $row['day'], $row['start_minute'], $row['end_minute'], $input)
                || ($row['day'] === 4 && array_intersect($session['lecturers'], $input['leaders'] ?? []) !== [])
                || (isset($session['locked']) && [$row['day'], $row['start_minute'], $room] !== [$session['locked']['day'], $session['locked']['start'], $session['mode'] === 'online' ? null : $session['locked']['room']])
                || (($input['capacity_blocks_publish'] ?? false) && $room !== null && ($session['students'] ?? null) !== null
                    && $rooms[$room]['capacity'] !== null && $session['students'] > $rooms[$room]['capacity'])
                || $solver->roomBlocked($session, [$row['day'], $row['start_minute'], $row['end_minute'], $room, 0], $input)) {
                return false;
            }

            foreach ($solver->resources($session, $room) as $resource) {
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

    /**
     * Validasi seluruh struktur dan batasan nilai payload input solver.
     *
     * @param  array<string, mixed>  $input
     */
    public function validInput(array $input): bool
    {
        if (isset($input['time_limit']) && ((! is_int($input['time_limit']) && ! is_float($input['time_limit']))
            || ! is_finite((float) $input['time_limit']) || $input['time_limit'] < 0)) {
            return false;
        }

        if (isset($input['strategy']) && ! in_array($input['strategy'], ['preserve', 'fresh'], true)) {
            return false;
        }

        if (isset($input['seed']) && (! is_int($input['seed']) || $input['seed'] < 0)) {
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

    /**
     * Validasi fase, daftar dosen, dan daftar kelas pada sebuah sesi atau blok tetap.
     *
     * @param  array<string, mixed>  $session
     */
    public function validResources(array $session): bool
    {
        return in_array($session['phase'] ?? null, ['full', 'before_uts', 'after_uts'], true)
            && $this->ids($session['lecturers'] ?? null) && $this->ids($session['classes'] ?? null);
    }

    /**
     * Validasi daftar ID bilangan bulat positif tanpa duplikasi.
     */
    public function ids(mixed $ids): bool
    {
        return is_array($ids) && array_all($ids, fn (mixed $id): bool => is_int($id) && $id > 0) && count($ids) === count(array_unique($ids));
    }

    /**
     * Validasi nomor hari ISO (1–7).
     */
    public function validDay(mixed $day): bool
    {
        return is_int($day) && $day >= 1 && $day <= 7;
    }

    /**
     * Validasi interval menit setengah terbuka dalam satu hari (0–1440, selesai > mulai).
     */
    public function validInterval(mixed $start, mixed $end): bool
    {
        return is_int($start) && is_int($end) && $start >= 0 && $end <= 1440 && $end > $start;
    }
}
