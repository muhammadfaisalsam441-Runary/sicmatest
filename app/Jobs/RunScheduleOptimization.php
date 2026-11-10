<?php

namespace App\Jobs;

use App\Models\Scheduling\OptimizationJob;
use App\Services\Scheduling\SchedulerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Menjalankan generator PHP di antrean agar request web tetap singkat.
 */
class RunScheduleOptimization implements ShouldQueue
{
    use Queueable;

    /**
     * Penyusunan tidak diulang otomatis: hasil yang sama akan diperoleh dari masukan yang sama.
     */
    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public OptimizationJob $optimizationJob)
    {
        $this->timeout = $optimizationJob->time_limit_seconds + (int) config('sicma.scheduler.grace_seconds') + 30;
    }

    public function handle(SchedulerService $scheduler): void
    {
        $scheduler->runNow($this->optimizationJob->fresh());
    }

    public function failed(?Throwable $exception): void
    {
        app(SchedulerService::class)->fail($this->optimizationJob, 'Proses antrean generator PHP gagal atau melewati batas waktu. Periksa log queue lalu coba lagi.');
    }
}
