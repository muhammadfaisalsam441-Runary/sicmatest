@use('App\Enums\OptimizationJobStatus')
@use('App\Enums\SolverStatus')
@use('App\Services\Scheduling\SchedulerService')
@use('App\Support\Wita')
@extends('layouts.admin')

@section('title', 'Susun Jadwal')

@php
    $code = 'J-'.$job->created_at->format('Ymd').'-'.str_pad((string) $job->id, 2, '0', STR_PAD_LEFT);
    $scopeLabel = SchedulerService::SCOPES[$job->scope['type'] ?? 'semester'] ?? 'Seluruh semester';
    $running = in_array($job->status, [OptimizationJobStatus::Queued, OptimizationJobStatus::Running], true);
    $draftId = $job->result['draft_version_id'] ?? null;
    $state = match (true) {
        $running => 'running',
        $job->status === OptimizationJobStatus::Cancelled => 'cancelled',
        $job->status === OptimizationJobStatus::Failed => 'failed',
        $draftId !== null => 'draft',
        $job->solver_status === SolverStatus::Infeasible => 'infeasible',
        $job->solver_status === SolverStatus::ModelInvalid => 'invalid',
        default => 'timeout',
    };
    [$title, $crumb] = [
        'running' => ['Sedang menyusun jadwal', 'Proses Penyusunan'],
        'cancelled' => ['Penyusunan dibatalkan', 'Proses Dibatalkan'],
        'failed' => ['Penyusunan gagal dijalankan', 'Proses Gagal'],
        'draft' => ['Draf hasil penyusunan', 'Hasil Draf'],
        'infeasible' => ['Belum ada susunan yang memenuhi aturan', 'Kendala Penyusunan'],
        'invalid' => ['Data penyusunan perlu diperiksa', 'Data Tidak Valid'],
        'timeout' => ['Batas waktu pencarian tercapai', 'Batas Waktu'],
    ][$state];
@endphp

@section('breadcrumb', 'Susun Jadwal|'.$crumb)

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.scheduler.history')">Riwayat proses</x-button>
@endsection

@section('content')
    <div class="flex flex-col gap-6"
        @if ($running) x-data="statusPolling({ url: @js(route('admin.scheduler.status', $job)), interval: 3000, job: true, elapsed: {{ $job->started_at ? max(0, (int) $job->started_at->diffInSeconds(now())) : 0 }}, progress: @js($job->result['progress'] ?? ['stage' => $job->status === OptimizationJobStatus::Queued ? 'Menunggu antrean' : 'Menyiapkan kandidat', 'total' => $job->scope['in_scope'] ?? 0, 'placed' => 0]) })" @endif>
        <x-page-header :title="$title" :subtitle="'Pekerjaan '.$code.' • '.$scopeLabel.' • dasar v'.($job->scope['base_version_no'] ?? '?').' • '.Wita::stamp($job->created_at)" />
        <x-card><x-schedule-steps :current="3" /></x-card>
        @include('admin.partials.scheduling-tabs')

        @if ($state === 'running')
            <x-card class="flex flex-col gap-4">
                <h2 class="text-lg font-semibold" x-text="progress.stage">{{ $job->status === OptimizationJobStatus::Queued ? 'Menunggu antrean' : 'Menyiapkan kandidat' }}</h2>
                <p class="text-sm text-muted">{{ $job->scope['in_scope'] ?? 0 }} sesi dalam cakupan • batas {{ $job->time_limit_seconds }} detik • berjalan <span x-text="elapsed">0</span> detik</p>
                <p class="text-sm" aria-live="polite"><strong x-text="progress.placed">0</strong> dari <strong x-text="progress.total">{{ $job->scope['in_scope'] ?? 0 }}</strong> sesi berhasil ditempatkan pada kandidat terbaik sejauh ini.</p>
                <p class="text-xs text-muted">Jumlah ini menunjukkan hasil pencarian, bukan persentase waktu. Semua hasil diperiksa sebelum disimpan sebagai draf.</p>
                <x-info-box title="Satu permintaan, satu proses">
                    <p>Klik berulang tidak membuat pekerjaan ganda. Jadwal resmi dan sesi terkunci tetap dilindungi. Proses tetap berjalan saat kamu membuka menu lain.</p>
                </x-info-box>
                <form method="POST" action="{{ route('admin.scheduler.cancel', $job) }}">
                    @csrf
                    <x-button type="submit" variant="danger-outline">Batalkan proses</x-button>
                </form>
            </x-card>
        @elseif ($state === 'draft')
            <x-alert :variant="($job->result['publishable'] ?? false) ? 'success' : 'info'" :title="'Draf v'.($job->result['draft_version_no'] ?? '').' dibuat • jadwal resmi belum berubah'">
                {{ $job->result['placed'] ?? 0 }} sesi ditempatkan, {{ $job->result['changed'] ?? 0 }} berpindah dari penempatan sebelumnya.
                {{ count($job->result['unplaced'] ?? []) }} sesi belum ditempatkan.
            </x-alert>
            <x-card class="flex flex-col gap-3">
                <h2 class="text-lg font-semibold">Ringkasan hasil</h2>
                <table class="w-full text-left text-sm">
                    <tbody class="divide-y divide-border">
                        <tr><td class="py-3 font-medium">Status pencarian</td><td class="py-3">{{ $job->solver_status->label() }}</td><td class="py-3 text-muted">{{ ($job->result['publishable'] ?? false) ? 'Susunan lengkap lolos validasi wajib; belum dinyatakan optimal.' : 'Draf masih perlu diperbaiki sebelum ditetapkan.' }}</td></tr>
                        <tr><td class="py-3 font-medium">Validasi seluruh draf</td><td class="py-3">{{ $job->result['validation_errors'] ?? '—' }} masalah wajib</td><td class="py-3 text-muted">{{ $job->result['validation_warnings'] ?? '—' }} peringatan. Termasuk sesi di luar cakupan.</td></tr>
                        <tr><td class="py-3 font-medium">Waktu proses</td><td class="py-3">{{ $job->result['wall_time'] ?? '—' }} detik</td><td class="py-3 text-muted">Batas {{ $job->time_limit_seconds }} detik</td></tr>
                    </tbody>
                </table>
                <p class="text-xs text-muted">Bentrok dengan reservasi pada tanggal terkait diperiksa ulang di draf dan saat penetapan.</p>
            </x-card>
            @if (! empty($job->result['unplaced_details']))
                <x-card class="flex flex-col gap-3">
                    <h2 class="text-lg font-semibold">Sesi yang belum ditempatkan</h2>
                    @foreach ($job->result['unplaced_details'] as $detail)
                        <p class="text-sm">{{ $detail['label'] ?? 'Sesi #'.$detail['id'] }}: {{ $detail['reason'] }}</p>
                    @endforeach
                </x-card>
            @endif
            <div class="flex gap-3">
                <x-button :href="route('admin.drafts.inspect', $draftId)">Buka Pemeriksaan</x-button>
                <x-button variant="secondary" :href="route('admin.drafts.show', $draftId)">Edit Draf</x-button>
                <x-button variant="secondary" :href="route('admin.drafts.show', [$draftId, 'tinjau' => 'dosen'])">Tinjau per dosen</x-button>
            </div>
        @elseif ($state === 'infeasible')
            <x-alert variant="danger" title="Tidak ada solusi untuk pilihan dan kunci saat ini">
                {{ $job->result['message'] ?? '' }} Kegagalan pada cakupan ini tidak berarti seluruh jadwal semester mustahil disusun.
            </x-alert>
            <x-card class="flex flex-col gap-2 text-sm">
                <h2 class="text-lg font-semibold">Pilihan perbaikan</h2>
                <p>• Buka kunci sesi draf yang membatasi kandidat, atau perluas cakupan.</p>
                <p>• Periksa kapasitas ruang dan jumlah mahasiswa kelas.</p>
                <p>• Periksa ketersediaan dosen dan aturan pimpinan hari Kamis.</p>
                <p class="text-muted">Aturan wajib tidak dilonggarkan otomatis. Jadwal resmi tetap aktif.</p>
            </x-card>
            <x-button :href="route('admin.scheduler.create')">Atur cakupan & kunci</x-button>
        @elseif ($state === 'invalid')
            <x-alert variant="danger" title="Periksa data dasar penyusunan">{{ $job->result['message'] ?? 'Data sesi belum valid.' }}</x-alert>
            <x-button :href="route('admin.scheduler.create')">Periksa pengaturan</x-button>
        @elseif ($state === 'timeout')
            <x-alert variant="danger" title="Hasil belum dapat dipublikasikan">
                {{ $job->result['message'] ?? 'Belum ditemukan susunan lengkap dalam batas pencarian.' }} Ini belum membuktikan bahwa jadwal mustahil disusun.
            </x-alert>
            <x-button :href="route('admin.scheduler.create')">Periksa kesiapan dan cakupan</x-button>
        @elseif ($state === 'cancelled')
            <x-info-box title="Proses dihentikan oleh admin"><p>Belum ada draf yang dihasilkan. Tidak ada perubahan pada jadwal resmi. Riwayat proses tetap tercatat.</p></x-info-box>
            <x-button :href="route('admin.scheduler.create')">Susun jadwal baru</x-button>
        @else
            <x-alert variant="danger" title="Worker penyusun jadwal tidak berjalan">{{ $job->result['message'] ?? 'Terjadi galat.' }}</x-alert>
            <x-button :href="route('admin.scheduler.create')">Coba lagi</x-button>
        @endif
        @if ($canRetry)
            <x-card class="flex flex-col gap-3">
                <h2 class="text-lg font-semibold">Susunan belum lengkap</h2>
                <p class="text-sm text-muted">Coba pekerjaan baru selama maksimal 180 detik dengan data dasar, cakupan, dan kunci yang sama. Draf sebelumnya tetap tersimpan. Jika data berubah, periksa kesiapan kembali.</p>
                <div class="flex flex-wrap gap-3">
                    <form method="POST" action="{{ route('admin.scheduler.retry', $job) }}">@csrf<x-button type="submit">Coba Pencarian 180 Detik</x-button></form>
                    <x-button variant="secondary" :href="$draftId ? route('admin.drafts.inspect', $draftId) : route('admin.scheduler.create')">Periksa Kendala</x-button>
                </div>
            </x-card>
        @elseif (! $running && $job->time_limit_seconds === 180 && ! ($job->result['publishable'] ?? false))
            <x-info-box title="Tinjau kendala sebelum mencoba lagi"><p>Waktu tambahan belum menghasilkan susunan lengkap. Periksa data, cakupan, sesi terkunci, dan temuan pemeriksaan.</p></x-info-box>
        @endif
    </div>
@endsection
