<?php

namespace App\Services\Scheduling;

use App\Enums\IssueSeverity;
use App\Enums\SessionMode;
use App\Models\Scheduling\ScheduleIssue;
use App\Models\Scheduling\ScheduleSession;
use App\Models\Scheduling\ScheduleVersion;
use Illuminate\Support\Collection;

/**
 * Menghitung skor mutu draf jadwal dan selisih penempatan terhadap versi dasar secara batch.
 */
class ScheduleDraftMetricsService
{
    /**
     * Ringkas skor mutu, sub-metrik, dan jumlah perubahan penempatan untuk setiap draf.
     *
     * @param  Collection<int, ScheduleVersion>  $versions
     * @return array<int, array{
     *     score: int,
     *     grade: string,
     *     variant: string,
     *     changed_count: ?int,
     *     baseline_version_no: ?int,
     *     baseline_label: ?string,
     *     summary_tooltip: string,
     *     metrics: array{
     *         scheduled_ratio: int,
     *         capacity_fit: int,
     *         day_balance: int,
     *         gap_score: int,
     *         error_count: int,
     *         warning_count: int,
     *     },
     * }>
     */
    public function summarizeMany(Collection $versions, ?ScheduleVersion $official = null): array
    {
        if ($versions->isEmpty()) {
            return [];
        }

        $versionIds = $versions->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $baselineIds = $versions->pluck('based_on_version_id')->filter()->map(fn ($id): int => (int) $id)->all();
        $allVersionIds = array_values(array_unique(array_filter([
            ...$versionIds,
            ...$baselineIds,
            $official?->id !== null ? (int) $official->id : null,
        ])));

        $sessionsByVersion = ScheduleSession::query()
            ->whereIn('schedule_version_id', $allVersionIds)
            ->with(['room:id,capacity', 'participants:id,student_count', 'lecturers:id'])
            ->orderBy('id')
            ->get()
            ->groupBy('schedule_version_id');

        $issueCounts = $this->unresolvedIssueCounts($versionIds);

        $unloadedBaselineIds = $versions
            ->filter(fn (ScheduleVersion $version): bool => $version->based_on_version_id !== null && ! $version->relationLoaded('basedOn'))
            ->pluck('based_on_version_id')
            ->unique()
            ->values()
            ->all();

        $extraBaselines = $unloadedBaselineIds !== []
            ? ScheduleVersion::query()->whereIn('id', $unloadedBaselineIds)->get(['id', 'version_no', 'label'])->keyBy('id')
            : collect();

        $placementMaps = [];
        $summaries = [];

        foreach ($versions as $version) {
            /** @var Collection<int, ScheduleSession> $sessions */
            $sessions = $sessionsByVersion->get($version->id, collect());
            $errors = $issueCounts[$version->id]['error'] ?? 0;
            $warnings = $issueCounts[$version->id]['warning'] ?? 0;

            $evaluated = $this->evaluateVersion($sessions, $errors, $warnings);

            $baseline = $version->relationLoaded('basedOn')
                ? ($version->basedOn ?? $official)
                : ($version->based_on_version_id !== null ? ($extraBaselines->get($version->based_on_version_id) ?? $official) : $official);

            $changedCount = null;
            $baselineVersionNo = null;
            $baselineLabel = null;

            if ($baseline !== null && $baseline->id !== $version->id) {
                $baselineVersionNo = $baseline->version_no;
                $baselineLabel = $baseline->label;

                $placementMaps[$version->id] ??= $this->placementSignatureMap($sessions);
                $placementMaps[$baseline->id] ??= $this->placementSignatureMap($sessionsByVersion->get($baseline->id, collect()));

                $changedCount = $this->countPlacementDifferences($placementMaps[$version->id], $placementMaps[$baseline->id]);
            }

            $summaries[$version->id] = [
                'score' => $evaluated['score'],
                'grade' => $evaluated['grade'],
                'variant' => $evaluated['variant'],
                'changed_count' => $changedCount,
                'baseline_version_no' => $baselineVersionNo,
                'baseline_label' => $baselineLabel,
                'summary_tooltip' => $evaluated['summary_tooltip'],
                'metrics' => $evaluated['metrics'],
            ];
        }

        return $summaries;
    }

    /**
     * @param  list<int>  $versionIds
     * @return array<int, array{error?: int, warning?: int}>
     */
    private function unresolvedIssueCounts(array $versionIds): array
    {
        $rows = ScheduleIssue::query()
            ->select('schedule_version_id', 'severity')
            ->selectRaw('COUNT(*) as aggregate')
            ->whereIn('schedule_version_id', $versionIds)
            ->whereNull('resolved_at')
            ->groupBy('schedule_version_id', 'severity')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $vid = (int) $row->schedule_version_id;
            $severity = $row->severity instanceof IssueSeverity ? $row->severity->value : (string) $row->severity;
            $counts[$vid][$severity] = (int) $row->aggregate;
        }

        return $counts;
    }

    /**
     * @param  Collection<int, ScheduleSession>  $sessions
     * @return array{
     *     score: int,
     *     grade: string,
     *     variant: string,
     *     summary_tooltip: string,
     *     metrics: array{
     *         scheduled_ratio: int,
     *         capacity_fit: int,
     *         day_balance: int,
     *         gap_score: int,
     *         error_count: int,
     *         warning_count: int,
     *     },
     * }
     */
    private function evaluateVersion(Collection $sessions, int $errorCount, int $warningCount): array
    {
        $totalSessions = $sessions->count();
        $unscheduledCount = 0;
        $measurableCapacityCount = 0;
        $undersizedCount = 0;
        $excessWasteCount = 0;

        /** @var array<string, list<ScheduleSession>> $entityDaySessions */
        $entityDaySessions = [];

        foreach ($sessions as $session) {
            if (! $this->isTimed($session)) {
                $unscheduledCount++;

                continue;
            }

            if ($session->mode === SessionMode::Offline
                && $session->room !== null
                && $session->room->capacity !== null
                && $session->participants->isNotEmpty()
                && ! $session->participants->pluck('student_count')->contains(null)) {
                $measurableCapacityCount++;
                $students = (int) $session->participants->sum('student_count');
                $capacity = (int) $session->room->capacity;

                if ($capacity < $students) {
                    $undersizedCount++;
                } elseif (($capacity - $students) > 20) {
                    $excessWasteCount++;
                }
            }

            foreach ($session->participants as $participant) {
                $entityDaySessions['c:'.$participant->id.':'.$session->day_of_week][] = $session;
            }

            foreach ($session->lecturers as $lecturer) {
                $entityDaySessions['l:'.$lecturer->id.':'.$session->day_of_week][] = $session;
            }
        }

        $scheduledCount = $totalSessions - $unscheduledCount;
        $scheduledRatio = $totalSessions === 0 ? 100 : (int) round(($scheduledCount / $totalSessions) * 100);

        $capacityDeduction = 0;
        $capacityFit = 100;

        if ($measurableCapacityCount > 0) {
            $rawCapacityPenalty = (($undersizedCount / $measurableCapacityCount) * 15) + (($excessWasteCount / $measurableCapacityCount) * 10);
            $capacityDeduction = (int) min(15, round($rawCapacityPenalty));
            $capacityFit = (int) max(0, min(100, round(100 - ($capacityDeduction / 15) * 100)));
        }

        $totalEntityDays = count($entityDaySessions);
        $overloadedEntityDays = 0;
        $totalGaps = 0;
        $longGaps = 0;

        foreach ($entityDaySessions as $bucket) {
            $count = count($bucket);

            if ($count > 3) {
                $overloadedEntityDays++;
            }

            if ($count < 2) {
                continue;
            }

            usort($bucket, static fn (ScheduleSession $a, ScheduleSession $b): int => [$a->start_minute, $a->end_minute] <=> [$b->start_minute, $b->end_minute]);

            for ($i = 1; $i < $count; $i++) {
                $totalGaps++;

                if (($bucket[$i]->start_minute - $bucket[$i - 1]->end_minute) > 150) {
                    $longGaps++;
                }
            }
        }

        $balanceDeduction = 0;
        $dayBalance = 100;

        if ($totalEntityDays > 0) {
            $overloadRatio = $overloadedEntityDays / $totalEntityDays;
            $balanceDeduction = (int) min(15, round($overloadRatio * 15));
            $dayBalance = (int) max(0, min(100, round((1 - $overloadRatio) * 100)));
        }

        $gapDeduction = 0;
        $gapScore = 100;

        if ($totalGaps > 0) {
            $gapRatio = $longGaps / $totalGaps;
            $gapDeduction = (int) min(15, round($gapRatio * 15));
            $gapScore = (int) max(0, min(100, round((1 - $gapRatio) * 100)));
        }

        $errorDeduction = min(40, $errorCount * 8 + $unscheduledCount * 6);
        $warningDeduction = min(15, $warningCount * 2);
        $score = max(0, min(100, 100 - $errorDeduction - $warningDeduction - $capacityDeduction - $balanceDeduction - $gapDeduction));

        [$grade, $variant] = match (true) {
            $score >= 85 => ['Sangat Baik', 'success'],
            $score >= 70 => ['Baik', 'primary'],
            $score >= 50 => ['Cukup', 'warning'],
            default => ['Perlu Perbaikan', 'danger'],
        };

        $summaryTooltip = "Terjadwal {$scheduledRatio}%, {$errorCount} masalah wajib, {$warningCount} peringatan, kesesuaian ruang {$capacityFit}%, sebaran hari {$dayBalance}%";

        return [
            'score' => $score,
            'grade' => $grade,
            'variant' => $variant,
            'summary_tooltip' => $summaryTooltip,
            'metrics' => [
                'scheduled_ratio' => $scheduledRatio,
                'capacity_fit' => $capacityFit,
                'day_balance' => $dayBalance,
                'gap_score' => $gapScore,
                'error_count' => $errorCount,
                'warning_count' => $warningCount,
            ],
        ];
    }

    private function isTimed(ScheduleSession $session): bool
    {
        return $session->mode !== SessionMode::Unscheduled
            && $session->day_of_week !== null
            && $session->start_minute !== null
            && $session->end_minute !== null;
    }

    /**
     * @param  Collection<int, ScheduleSession>  $sessions
     * @return array<string, array{0: string, 1: int|null, 2: int|null, 3: int|null, 4: int|null}>
     */
    private function placementSignatureMap(Collection $sessions): array
    {
        $occurrences = [];
        $map = [];

        foreach ($sessions->sortBy('id') as $session) {
            $participantIds = $session->participants
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->sort()
                ->values()
                ->all();

            $baseKey = ($session->course_offering_id ? 'o:'.$session->course_offering_id : 't:'.mb_strtolower(trim($session->title)))
                .'|'.$session->phase->value
                .'|'.implode(',', $participantIds);

            $occurrenceIndex = ($occurrences[$baseKey] ?? 0) + 1;
            $occurrences[$baseKey] = $occurrenceIndex;

            $key = $baseKey.'#'.$occurrenceIndex;
            $isUnscheduled = $session->mode === SessionMode::Unscheduled;

            $map[$key] = [
                $session->mode->value,
                $isUnscheduled ? null : $session->day_of_week,
                $isUnscheduled ? null : $session->start_minute,
                $isUnscheduled ? null : $session->end_minute,
                $session->mode === SessionMode::Offline ? $session->room_id : null,
            ];
        }

        return $map;
    }

    /**
     * @param  array<string, array{0: string, 1: int|null, 2: int|null, 3: int|null, 4: int|null}>  $currentMap
     * @param  array<string, array{0: string, 1: int|null, 2: int|null, 3: int|null, 4: int|null}>  $baselineMap
     */
    private function countPlacementDifferences(array $currentMap, array $baselineMap): int
    {
        $changed = 0;
        $allKeys = array_unique(array_merge(array_keys($currentMap), array_keys($baselineMap)));

        foreach ($allKeys as $key) {
            if (! isset($currentMap[$key]) || ! isset($baselineMap[$key]) || $currentMap[$key] !== $baselineMap[$key]) {
                $changed++;
            }
        }

        return $changed;
    }
}
