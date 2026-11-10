<?php

namespace App\Services\Room;

use Carbon\CarbonImmutable;

/**
 * Kriteria pencarian ruang untuk Portal Publik, Portal Keti, dan dashboard admin.
 */
final class SearchCriteria
{
    public const SORT_LONGEST = 'longest';

    public const SORT_NAME = 'name';

    public const SORT_CAPACITY = 'capacity';

    /**
     * @param  int|string|null  $building  ID gedung, "none" untuk ruang tanpa gedung, atau null untuk semua.
     * @param  string|null  $status  "available", "busy", atau null untuk semua.
     */
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly int $durationMinutes = 60,
        public readonly ?string $keyword = null,
        public readonly int|string|null $building = null,
        public readonly ?int $floor = null,
        public readonly ?int $minCapacity = null,
        public readonly ?string $status = null,
        public readonly string $sort = self::SORT_LONGEST,
        public readonly ?int $roomId = null,
    ) {}

    public function end(): CarbonImmutable
    {
        return $this->start->addMinutes($this->durationMinutes);
    }
}
