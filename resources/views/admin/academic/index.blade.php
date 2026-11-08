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

    <div class="flex flex-col gap-6">
        <x-page-header :title="AcademicController::TABS[$tab]" :subtitle="$period ? 'Semester aktif: '.$period->name.'. Data yang belum diketahui dibiarkan kosong dan ditandai Perlu dilengkapi.' : 'Belum ada semester aktif.'">
            <x-slot:actions>
                @if ($tab === 'kelas')
                    <x-button icon="plus" x-data x-on:click="$dispatch('open-modal', 'class-new')">Tambah kelas</x-button>
                @elseif ($tab === 'mata-kuliah')
                    <x-button icon="plus" x-data x-on:click="$dispatch('open-modal', 'course-new')">Tambah mata kuliah</x-button>
                @elseif ($tab === 'penawaran')
                    <x-button icon="plus" x-data x-on:click="$dispatch('open-modal', 'offering-new')">Tambah penawaran</x-button>
                @endif
            </x-slot:actions>
        </x-page-header>

        <x-tabs :items="collect(AcademicController::TABS)->map(fn ($label, $key) => ['label' => $label, 'href' => route('admin.academic.index', ['tab' => $key]), 'active' => $tab === $key])->values()->all()" />

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
    @elseif ($tab === 'mata-kuliah')
        @include('admin.academic.modals.course-modals')
    @elseif ($tab === 'penawaran' && $period)
        @include('admin.academic.modals.offering-modals')
    @endif
@endpush
