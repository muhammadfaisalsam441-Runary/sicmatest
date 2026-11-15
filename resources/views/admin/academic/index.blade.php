@use('App\Http\Controllers\Admin\AcademicController')
@use('App\Support\Wita')
@extends('layouts.admin')

@section('title', 'Data Akademik')
@section('breadcrumb', 'Data Akademik|'.AcademicController::TABS[$tab])

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.dashboard')">Dashboard</x-button>
@endsection

@section('content')
    @php
        $clock = fn (int $minutes) => sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    @endphp

    <div class="flex flex-col gap-4 admin-page-stack">
        <x-page-header :title="AcademicController::TABS[$tab]" :subtitle="$period ? 'Periode akademik: '.$period->name : 'Periode akademik: belum ada semester aktif'">
            <x-slot:actions>
                @if ($tab === 'kelas')
                    <div class="flex flex-wrap items-center gap-2" x-data>
                        <x-button variant="secondary" size="sm" icon="graduation-cap" x-on:click="$dispatch('open-modal', 'modal-graduate-cohort')">Luluskan & Arsipkan</x-button>
                        <x-button variant="secondary" size="sm" icon="copy-plus" x-on:click="$dispatch('open-modal', 'modal-generate-cohort')">Buka Angkatan Baru</x-button>
                        <x-button size="sm" icon="plus" x-on:click="$dispatch('class-open-form', null)">Tambah kelas</x-button>
                    </div>
                @elseif ($tab === 'mata-kuliah')
                    <x-button size="sm" icon="plus" x-data x-on:click="$dispatch('course-open-form', null)">Tambah mata kuliah</x-button>
                @elseif ($tab === 'penawaran')
                    <x-button size="sm" icon="plus" x-data x-on:click="$dispatch('open-modal', 'offering-new')">Tambah penawaran</x-button>
                @endif
            </x-slot:actions>
        </x-page-header>

        <x-tabs :items="collect(AcademicController::TABS)->map(fn ($label, $key) => ['label' => $label, 'href' => route('admin.academic.index', ['tab' => $key]), 'active' => $tab === $key])->values()->all()" size="sm" />

        {{-- Konten Tab Modular --}}
        @switch($tab)
            @case('semester')
                @include('admin.academic.tabs.tab-semester')
                @break

            @case('aturan')
                @include('admin.academic.tabs.tab-rules')
                @break

            @case('kelas')
                @include('admin.academic.tabs.tab-classes')
                @break

            @case('mata-kuliah')
                @include('admin.academic.tabs.tab-courses')
                @break

            @case('penawaran')
                @include('admin.academic.tabs.tab-offerings')
                @break
        @endswitch
    </div>
@endsection

@push('dialogs')
    @if ($tab === 'kelas')
        @include('admin.academic.modals.class-modals')
        @include('admin.academic.modals.cohort-modals')
    @elseif ($tab === 'mata-kuliah')
        @include('admin.academic.modals.course-modals')
    @elseif ($tab === 'penawaran' && $period)
        @include('admin.academic.modals.offering-modals')
    @endif
@endpush
