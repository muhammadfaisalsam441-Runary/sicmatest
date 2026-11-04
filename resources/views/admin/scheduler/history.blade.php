@use('App\Enums\OptimizationJobStatus')
@use('App\Services\SchedulerService')
@use('App\Support\Wita')
@extends('layouts.admin')

@section('title', 'Susun Jadwal')
@section('breadcrumb', 'Susun Jadwal|Riwayat Proses')

@section('breadcrumb-actions')
    <x-button :href="route('admin.scheduler.create')">Susun jadwal baru</x-button>
@endsection

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Riwayat penyusunan otomatis" subtitle="Status dan hasil setiap proses dapat dibuka kembali. Status solver disimpan terpisah dari status proses." />

        <x-card class="flex flex-col gap-3">
            @forelse ($jobs as $job)
                <a href="{{ route('admin.scheduler.show', $job) }}" class="flex items-center justify-between rounded-control border border-border px-4 py-3 text-sm hover:bg-primary-soft/40">
                    <div class="flex flex-col">
                        <span class="font-semibold">J-{{ $job->created_at->format('Ymd') }}-{{ str_pad((string) $job->id, 2, '0', STR_PAD_LEFT) }} • {{ $job->status->label() }}{{ $job->solver_status ? ' • '.$job->solver_status->label() : '' }}</span>
                        <span class="text-muted">{{ SchedulerService::SCOPES[$job->scope['type'] ?? 'semester'] ?? '' }} • {{ $job->time_limit_seconds }} detik • {{ $job->creator?->name }} • {{ Wita::stamp($job->created_at) }}</span>
                    </div>
                    <x-status-badge :variant="match ($job->status) { OptimizationJobStatus::Finished => isset($job->result['draft_version_id']) ? 'success' : 'danger', OptimizationJobStatus::Failed => 'danger', OptimizationJobStatus::Cancelled => 'neutral', default => 'primary' }">
                        {{ isset($job->result['draft_version_id']) ? 'Draf v'.$job->result['draft_version_no'] : $job->status->label() }}
                    </x-status-badge>
                </a>
            @empty
                <x-empty-state title="Belum ada proses" message="Proses susun jadwal otomatis akan tercatat di sini." icon="history" />
            @endforelse
            {{ $jobs->links() }}
        </x-card>
    </div>
@endsection
