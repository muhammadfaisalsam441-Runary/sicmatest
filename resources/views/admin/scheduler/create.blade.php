@use('App\Services\Scheduling\SchedulerService')
@extends('layouts.admin')

@section('title', 'Susun Jadwal')
@section('breadcrumb', 'Susun Jadwal|Pengaturan Generate')

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.scheduler.history')">Riwayat proses</x-button>
@endsection

@section('content')
    @php
        $scope = old('scope', 'semester');
    @endphp

    <div class="flex flex-col gap-6">
        <x-page-header title="Susun jadwal otomatis"
                        :subtitle="($period?->name ?? 'Semester aktif').' • Pilih bagian yang boleh berubah.'.($official ? ' Jadwal resmi v'.$official->version_no.' tetap dilindungi.' : '')" />

        <x-card>
            <x-schedule-steps :current="3" />
        </x-card>

        @include('admin.partials.scheduling-tabs')

        @if ($base && in_array($base->status->value, ['draft', 'ready'], true))
            <x-card class="flex flex-wrap items-center justify-between gap-4 border-primary/30 bg-primary-soft/30">
                <div class="flex flex-col gap-1">
                    <div class="flex items-center gap-2">
                        <span class="rounded-md bg-primary px-2 py-0.5 text-xs font-semibold text-white">Draf Aktif</span>
                        <h2 class="font-semibold text-ink">Draf v{{ $base->version_no }} sedang dikerjakan</h2>
                    </div>
                    <p class="text-sm text-muted">Kamu bisa langsung mengedit sesi atau memeriksa bentrok pada draf ini tanpa perlu menyusun ulang dari awal.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-button variant="secondary" icon="calendar-cog" :href="route('admin.drafts.show', $base)">Edit Sesi Draf</x-button>
                    <x-button icon="list-checks" :href="route('admin.drafts.inspect', $base)">Buka Pemeriksaan</x-button>
                </div>
            </x-card>
        @endif

        @if (! $period)
            <x-empty-state title="Belum ada semester aktif" message="Atur semester di Data Akademik lebih dulu." icon="calendar" />
        @else
            <form method="POST" action="{{ route('admin.scheduler.store') }}" class="grid gap-6 xl:grid-cols-[1fr_380px]" x-data="{ scope: @js($scope) }">
                @csrf
                <div class="flex flex-col gap-6">
                    <x-card class="flex flex-col gap-4">
                        <h2 class="text-lg font-semibold">1. Tentukan cakupan</h2>
                        <div class="grid grid-cols-4 gap-3">
                            @foreach (SchedulerService::SCOPES as $value => $label)
                                <label class="flex cursor-pointer items-center gap-2 rounded-control border px-4 py-3 text-sm font-medium" :class="scope === @js($value) ? 'border-primary bg-primary-soft text-primary' : 'border-border'">
                                    <input type="radio" name="scope" value="{{ $value }}" x-model="scope" class="accent-primary"> {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        <div x-show="scope === 'kelas'" class="flex flex-col gap-2">
                            <span class="text-sm font-medium">Kelas untuk cakupan kelas</span>
                            <div class="grid grid-cols-4 gap-2">
                                @foreach ($classGroups as $id => $name)
                                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="class_ids[]" value="{{ $id }}" class="accent-primary" @checked(in_array($id, old('class_ids', [])))> {{ $name }}</label>
                                @endforeach
                            </div>
                            @error('class_ids')<p class="text-xs font-medium text-danger">{{ $message }}</p>@enderror
                        </div>
                        <p class="text-sm text-muted">Cakupan menentukan bagian yang boleh diubah. Dasar penyusunan: {{ $base ? ($base->status->value === 'official' ? 'jadwal resmi' : 'draf').' v'.$base->version_no : 'belum ada' }}. Sesi yang dikunci di draf tidak dipindahkan.</p>
                    </x-card>

                    <x-info-box title="Pencarian otomatis maksimal 60 detik">
                        <p>Proses selesai lebih cepat jika susunan lengkap ditemukan. Jika hasil belum lengkap, kamu dapat mencoba pencarian 180 detik dari halaman hasil. Semua aturan wajib tetap dipenuhi.</p>
                    </x-info-box>

                    @if ($readiness)
                        <x-card class="flex flex-col gap-3">
                            <h2 class="text-lg font-semibold">{{ $readiness['errors'] === [] ? 'Siap disusun sebagai draf' : 'Data belum siap disusun' }}</h2>
                            <p class="text-sm text-muted">{{ $readiness['in_scope'] }} sesi dalam cakupan • {{ $readiness['fixed'] }} sesi tetap diperhitungkan</p>
                            @foreach ($readiness['errors'] as $error)
                                <x-alert variant="danger">{{ $error }}</x-alert>
                            @endforeach
                            @foreach ($readiness['warnings'] as $warning)
                                <div class="rounded-control bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ $warning }}</div>
                            @endforeach
                        </x-card>
                    @endif
                </div>

                <div class="flex flex-col gap-6">
                    <x-info-box title="Jadwal di luar pilihan tetap diperhitungkan">
                        <p>Sesi di luar cakupan, pimpinan yang tidak boleh hari Kamis, ketersediaan dosen, dan kapasitas ruang tetap diperiksa. Hasil selalu masuk sebagai draf baru; jadwal resmi tidak berubah sampai ditetapkan.</p>
                    </x-info-box>
                    <x-button type="submit" variant="secondary" formaction="{{ route('admin.scheduler.check') }}">Periksa kesiapan</x-button>
                    <x-button type="submit" icon="sparkles">Mulai susun</x-button>
                    <x-button variant="ghost" :href="route('admin.academic.index', ['tab' => 'aturan'])">Atur aturan</x-button>
                    @if ($lastJob)
                        <a href="{{ route('admin.scheduler.show', $lastJob) }}" class="text-sm text-primary hover:underline">Proses terakhir: {{ $lastJob->status->label() }}{{ $lastJob->solver_status ? ' • '.$lastJob->solver_status->label() : '' }}</a>
                    @endif
                </div>
            </form>
        @endif
    </div>
@endsection
