<?php

namespace App\Jobs;

use App\Models\OptimizationJob;
use App\Services\SchedulerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Menjalankan worker Python penyusun jadwal lewat antrean (config sicma.scheduler.run_mode = queue).
 */
class RunScheduleOptimization implements ShouldQueue
{
    use Queueable;

    /**
     * Penyusunan tidak diulang otomatis: hasil yang sama akan diperoleh dari masukan yang sama.
     */
    public int $tries = 1;

    public function __construct(public OptimizationJob $optimizationJob)
    {
        $this->timeout = $optimizationJob->time_limit_seconds + (int) config('sicma.scheduler.grace_seconds') + 30;
    }

    public function handle(SchedulerService $scheduler): void
    {
        $scheduler->runNow($this->optimizationJob->fresh());
    }
}
