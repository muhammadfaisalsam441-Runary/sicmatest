<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ImportRowStatus;
use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportScheduleRequest;
use App\Models\Import;
use App\Models\Period;
use App\Services\ScheduleImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Impor jadwal: 1 Unggah file → 2 Periksa hasil (perbedaan sumber) → simpan ke draf.
 */
class ImportController extends Controller
{
    public function create(): View
    {
        return view('admin.imports.create', [
            'periods' => Period::query()->orderByDesc('start_date')->pluck('name', 'id'),
            'activePeriodId' => Period::query()->active()->value('id'),
            'recentImports' => Import::query()->with('scheduleVersion')->latest()->limit(5)->get(),
        ]);
    }

    public function store(ImportScheduleRequest $request, ScheduleImportService $imports): RedirectResponse
    {
        $import = $imports->store($request->file('file'), Period::query()->findOrFail($request->integer('period_id')), $request->user());

        if ($import->status === ImportStatus::Failed) {
            return back()->with('error', $import->summary['error'] ?? 'File tidak dapat dibaca.');
        }

        $message = $import->wasRecentlyCreated ? null : 'File ini sudah pernah diimpor. Hasil pemeriksaan sebelumnya ditampilkan tanpa menggandakan data.';

        return to_route('admin.imports.show', $import)->with('toast', $message ? ['type' => 'info', 'title' => 'File sudah pernah diimpor', 'message' => $message] : null);
    }

    public function show(Request $request, Import $import): View
    {
        $filter = (string) $request->query('filter', 'semua');

        $rows = $import->rows()
            ->when($filter === 'perlu-verifikasi', fn ($query) => $query->whereIn('status', [ImportRowStatus::Warning, ImportRowStatus::Error]))
            ->when($filter === 'online', fn ($query) => $query->where('parsed->mode', 'online'))
            ->when($filter === 'tanpa-waktu', fn ($query) => $query->where('parsed->mode', 'unscheduled'))
            ->orderBy('row_no')
            ->paginate(25)
            ->withQueryString();

        return view('admin.imports.show', [
            'import' => $import->load(['period', 'scheduleVersion']),
            'rows' => $rows,
            'filter' => $filter,
        ]);
    }

    public function commit(Request $request, Import $import, ScheduleImportService $imports): RedirectResponse
    {
        $draft = $imports->commit($import, $request->user());

        return to_route('admin.drafts.show', $draft)->with('toast', [
            'type' => 'success',
            'title' => "Hasil impor masuk ke draf v{$draft->version_no}",
            'message' => 'Jadwal resmi tidak berubah sampai draf ditetapkan.',
        ]);
    }
}
