@use('App\Enums\OptimizationJobStatus')
@use('App\Enums\SolverStatus')
@use('App\Services\SchedulerService')
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
        default => 'timeout',
    };
    [$title, $crumb] = [
        'running' => ['Sedang menyusun jadwal', 'Proses Penyusunan'],
        'cancelled' => ['Penyusunan dibatalkan', 'Proses Dibatalkan'],
        'failed' => ['Penyusunan gagal dijalankan', 'Proses Gagal'],
        'draft' => ['Draf hasil penyusunan', 'Hasil Draf'],
        'infeasible' => ['Belum ada susunan yang memenuhi aturan', 'Kendala Penyusunan'],
        'timeout' => ['Batas waktu pencarian tercapai', 'Batas Waktu'],
    ][$state];
@endphp

@section('breadcrumb', 'Susun Jadwal|'.$crumb)

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.scheduler.history')">Riwayat proses</x-button>
@endsection

@section('content')
    <div class="flex flex-col gap-6"
         @if ($running) x-data="{ elapsed: 0 }" x-init="setInterval(async () => { const r = await fetch(@js(route('admin.scheduler.status', $job)), { headers: { Accept: 'application/json' } }); const d = await r.json(); elapsed = d.elapsed; if (! ['queued', 'running'].includes(d.status)) { window.location.reload(); } }, 3000)" @endif>
        <x-page-header :title="$title" :subtitle="'Pekerjaan '.$code.' • '.$scopeLabel.' • dasar v'.($job->scope['base_version_no'] ?? '?').' • '.Wita::stamp($job->created_at)" />

        @if ($state === 'running')
            <x-card class="flex flex-col gap-4">
                <h2 class="text-lg font-semibold">Mencari penempatan yang memenuhi aturan</h2>
                <p class="text-sm text-muted">{{ $job->scope['in_scope'] ?? 0 }} sesi dalam cakupan • batas {{ $job->time_limit_seconds }} detik • berjalan <span x-text="elapsed">0</span> detik</p>
                <div class="h-2 overflow-hidden rounded-full bg-primary-soft"><div class="h-full bg-primary transition-all" :style="`width: ${Math.min(100, elapsed / {{ $job->time_limit_seconds }} * 100)}%`"></div></div>
                <x-info-box title="Satu permintaan, satu proses">
                    <p>Klik berulang tidak membuat pekerjaan ganda. Jadwal resmi dan sesi terkunci tetap dilindungi. Proses tetap berjalan saat kamu membuka menu lain.</p>
                </x-info-box>
                <form method="POST" action="{{ route('admin.scheduler.cancel', $job) }}">
                    @csrf
                    <x-button type="submit" variant="danger-outline">Batalkan proses</x-button>
                </form>
            </x-card>
        @elseif ($state === 'draft')
            <x-alert variant="success" :title="'Draf v'.($job->result['draft_version_no'] ?? '').' dibuat • jadwal resmi belum berubah'">
                {{ $job->result['placed'] ?? 0 }} sesi ditempatkan, {{ $job->result['changed'] ?? 0 }} berpindah dari penempatan sebelumnya.
            </x-alert>
            <x-card class="flex flex-col gap-3">
                <h2 class="text-lg font-semibold">Ringkasan hasil</h2>
                <table class="w-full text-left text-sm">
                    <tbody class="divide-y divide-border">
                        <tr><td class="py-3 font-medium">Status pencarian</td><td class="py-3">{{ $job->solver_status->label() }}</td><td class="py-3 text-muted">{{ $job->solver_status === SolverStatus::Optimal ? 'Susunan terbaik ditemukan dalam batas waktu.' : 'Seluruh aturan wajib terpenuhi; belum dinyatakan optimal.' }}</td></tr>
                        <tr><td class="py-3 font-medium">Ruang, dosen, dan peserta</td><td class="py-3">Lolos</td><td class="py-3 text-muted">Tidak ada waktu bertabrakan di dalam cakupan; sesi di luar cakupan diperhitungkan</td></tr>
                        <tr><td class="py-3 font-medium">Waktu proses</td><td class="py-3">{{ $job->result['wall_time'] ?? '—' }} detik</td><td class="py-3 text-muted">Batas {{ $job->time_limit_seconds }} detik</td></tr>
                    </tbody>
                </table>
                <p class="text-xs text-muted">Bentrok dengan reservasi pada tanggal terkait diperiksa ulang di draf dan saat penetapan.</p>
            </x-card>
            <div class="flex gap-3">
                <x-button :href="route('admin.drafts.show', $draftId)">Tinjau & tetapkan draf</x-button>
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
        @elseif ($state === 'timeout')
            <x-alert variant="danger" title="Hasil belum dapat dipublikasikan">
                Waktu pencarian habis sebelum solusi lengkap ditemukan ({{ $job->solver_status?->label() ?? 'tidak diketahui' }}). Ini belum membuktikan bahwa jadwal mustahil disusun.
            </x-alert>
            <x-button :href="route('admin.scheduler.create')">Ubah waktu pencarian</x-button>
        @elseif ($state === 'cancelled')
            <x-info-box title="Proses dihentikan oleh admin"><p>Belum ada draf yang dihasilkan. Tidak ada perubahan pada jadwal resmi. Riwayat proses tetap tercatat.</p></x-info-box>
            <x-button :href="route('admin.scheduler.create')">Susun jadwal baru</x-button>
        @else
            <x-alert variant="danger" title="Worker penyusun jadwal tidak berjalan">{{ $job->result['message'] ?? 'Terjadi galat.' }}</x-alert>
            <x-button :href="route('admin.scheduler.create')">Coba lagi</x-button>
        @endif
    </div>
@endsection
