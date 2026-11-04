<?php

namespace App\Console\Commands;

use App\Services\OverdueReservationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sicma:flag-overdue')]
#[Description('Tandai reservasi yang tidak dimulai atau melewati batas penggunaan dan kirim pemberitahuan')]
class FlagOverdueReservations extends Command
{
    public function handle(OverdueReservationService $overdue): int
    {
        $counts = $overdue->flag();

        $this->info("Belum dimulai: {$counts['not_started']} • Melewati batas: {$counts['overrunning']} • Dibatalkan otomatis: {$counts['expired']}");

        return self::SUCCESS;
    }
}
