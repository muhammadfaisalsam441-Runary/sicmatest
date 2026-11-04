@use('App\Services\SchedulerService')
@extends('layouts.admin')

@section('title', 'Susun Jadwal')
@section('breadcrumb', 'Susun Jadwal|Pengaturan Generate')

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.scheduler.history')">Riwayat proses</x-button>
@endsection

@section('content')
    @php
        $scope = old('scope', 'semester');
        $limits = config('sicma.scheduler.time_limits');
        $limitLabels = ['cepat' => 'Cepat', 'seimbang' => 'Seimbang', 'mendalam' => 'Mendalam'];
    @endphp

    <div class="flex flex-col gap-6">
        <x-page-header title="Susun jadwal otomatis"
                        :subtitle="($period?->name ?? 'Semester aktif').' • Pilih bagian yang boleh berubah.'.($official ? ' Jadwal resmi v'.$official->version_no.' tetap dilindungi.' : '')" />

        <x-card>
            <x-stepper :steps="['Data', 'Tim mengajar', 'Draf jadwal', 'Periksa', 'Tetapkan']" :current="3" />
        </x-card>

        @if (! $period)
            <x-empty-state title="Belum ada semester aktif" message="Atur semester di Data Akademik lebih dulu." icon="calendar" />
        @else
            <form method="POST" action="{{ route('admin.scheduler.store') }}" class="grid grid-cols-[1fr_380px] gap-6" x-data="{ scope: @js($scope) }">
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

                    <x-card class="flex flex-col gap-4">
                        <h2 class="text-lg font-semibold">2. Pilih waktu pencarian</h2>
                        <div class="grid grid-cols-3 gap-3">
                            @foreach ($limits as $key => $seconds)
                                <label class="flex cursor-pointer items-center gap-2 rounded-control border border-border px-4 py-3 text-sm font-medium has-[:checked]:border-primary has-[:checked]:bg-primary-soft has-[:checked]:text-primary">
                                    <input type="radio" name="time_limit" value="{{ $seconds }}" class="accent-primary" @checked((int) old('time_limit', $limits['seimbang']) === $seconds)> {{ $limitLabels[$key] ?? ucfirst($key) }} • {{ $seconds }} detik
                                </label>
                            @endforeach
                        </div>
                        <p class="text-sm text-muted">Batas waktu pencarian, bukan jaminan selesai. Semua aturan wajib tetap dipenuhi pada setiap mode.</p>
                    </x-card>

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
