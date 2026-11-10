@extends('layouts.admin')
@section('title', 'Pemeriksaan Jadwal')
@section('breadcrumb', 'Pemeriksaan Jadwal')
@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Daftar & Pemeriksaan Draf Jadwal" :subtitle="($period?->name ?? 'Belum ada semester aktif').' • Kelola penempatan sesi draf atau periksa kesiapan sebelum ditetapkan menjadi jadwal resmi.'">
            <x-slot:actions>
                <x-button variant="secondary" icon="sparkles" :href="route('admin.scheduler.create')">Susun Otomatis</x-button>
                @if ($period && $versions->isEmpty())
                    <form method="POST" action="{{ route('admin.drafts.store') }}">
                        @csrf
                        <x-button type="submit" icon="plus">Buat Draf Manual</x-button>
                    </form>
                @endif
            </x-slot:actions>
        </x-page-header>
        <x-card><x-schedule-steps :current="4" /></x-card>
        @include('admin.partials.scheduling-tabs')
        @forelse ($versions as $version)
            <x-card class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-semibold">Draf v{{ $version->version_no }}</h2>
                        <x-status-badge :variant="$version->issues_count === 0 ? 'success' : 'warning'">
                            {{ $version->issues_count === 0 ? '0 masalah wajib' : $version->issues_count.' temuan wajib' }}
                        </x-status-badge>
                    </div>
                    <p class="mt-1 text-sm text-muted">{{ $version->source->label() }} • {{ $version->sessions_count }} sesi • {{ $version->issues_count }} temuan wajib tersimpan</p>
                    <p class="text-xs text-muted">Kesiapan dinilai pada pemeriksaan revisi terbaru.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-button variant="secondary" icon="calendar-cog" :href="route('admin.drafts.show', $version)">Edit Sesi Draf</x-button>
                    <x-button icon="list-checks" :href="route('admin.drafts.inspect', $version)">Buka Pemeriksaan</x-button>
                </div>
            </x-card>
        @empty
            <x-empty-state title="Belum ada draf untuk diperiksa" message="Buat draf revisi atau susun jadwal terlebih dahulu.">
                <x-button :href="route('admin.scheduler.create')">Buka Susun Jadwal</x-button>
                <x-button variant="secondary" :href="route('admin.schedules.index')">Jadwal Resmi</x-button>
            </x-empty-state>
        @endforelse
    </div>
@endsection
