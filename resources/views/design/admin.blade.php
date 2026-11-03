@extends('layouts.admin')

@section('title', 'Dashboard')

@section('breadcrumb-actions')
    <x-button variant="secondary">Susun Jadwal</x-button>
@endsection

@section('content')
    <div class="flex flex-col gap-8">
        <x-page-header title="Ruang JTIK hari ini" subtitle="Kamis, 29 Okt 2026 • Jadwal Ganjil 2026/2027 • Tampilan contoh pukul 13:30 WITA">
            <x-slot:actions>
                <x-button variant="soft">Impor jadwal</x-button>
                <x-button>Tambah ruang</x-button>
                <x-button>Susun jadwal</x-button>
            </x-slot:actions>
        </x-page-header>
        @include('design.components')
    </div>
@endsection
