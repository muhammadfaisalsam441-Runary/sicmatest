<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\ClassGroup;
use App\Models\Reservation;
use App\Models\Room;
use App\Support\Wita;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Log Pemakaian: aktivitas tercatat dari tombol Mulai gunakan dan Selesai menggunakan (rencana vs aktual).
 */
class UsageLogController extends Controller
{
    public function index(Request $request): View
    {
        $date = $this->date($request);
        $logs = $this->query($request, $date)->get();

        $lastUsed = Room::query()
            ->whereIn('id', $logs->pluck('room_id')->unique())
            ->get()
            ->map(fn (Room $room): array => [
                'room' => $room,
                'last' => $logs->where('room_id', $room->id)->sortByDesc('actual_start_at')->first(),
            ])
            ->take(3);

        return view('admin.usage-logs.index', [
            'date' => $date,
            'logs' => $logs,
            'lastUsed' => $lastUsed,
            'rooms' => Room::query()->orderBy('name')->pluck('name', 'id'),
            'classGroups' => ClassGroup::query()->orderBy('name')->pluck('name', 'id'),
            'roomId' => $request->integer('ruang') ?: null,
            'classGroupId' => $request->integer('kelas') ?: null,
        ]);
    }

    /**
     * Unduh log sebagai CSV (Excel dapat membukanya).
     */
    public function export(Request $request): StreamedResponse
    {
        $date = $this->date($request);
        $logs = $this->query($request, $date)->get();

        return response()->streamDownload(function () use ($logs): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Pengguna', 'Kelas', 'Ruang', 'Rencana (WITA)', 'Mulai (WITA)', 'Selesai (WITA)', 'Kegiatan', 'Status']);

            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->creator->name,
                    $log->classGroup->name,
                    $log->room->name,
                    Wita::range($log->start_at, $log->end_at),
                    Wita::clock($log->actual_start_at, false),
                    $log->actual_end_at ? Wita::clock($log->actual_end_at, false) : '—',
                    $log->purpose,
                    $log->status->label(),
                ]);
            }

            fclose($handle);
        }, 'log-pemakaian-'.$date->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @return Builder<Reservation>
     */
    private function query(Request $request, CarbonImmutable $date): Builder
    {
        return Reservation::query()
            ->with(['room', 'classGroup', 'creator'])
            ->whereNotNull('actual_start_at')
            ->whereIn('status', [ReservationStatus::InUse, ReservationStatus::Finished])
            ->whereBetween('actual_start_at', [$date->startOfDay(), $date->endOfDay()])
            ->when($request->integer('ruang'), fn (Builder $query, int $roomId) => $query->where('room_id', $roomId))
            ->when($request->integer('kelas'), fn (Builder $query, int $classGroupId) => $query->where('class_group_id', $classGroupId))
            ->orderByDesc('actual_start_at');
    }

    private function date(Request $request): CarbonImmutable
    {
        $value = (string) $request->query('tanggal');

        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::createFromFormat('Y-m-d', $value) : false;

        return $date === false ? CarbonImmutable::today() : $date->startOfDay();
    }
}
