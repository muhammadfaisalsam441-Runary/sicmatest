@use('App\Support\Wita')
@extends('layouts.admin')

@section('title', 'Jadwal resmi')
@section('breadcrumb', 'Jadwal Resmi|Penetapan Berhasil')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Jadwal resmi berhasil diperbarui"
                       :subtitle="'Resmi v'.$version->version_no.' • Berlaku '.Wita::longDate($version->effective_from).' • ditetapkan '.($version->publisher?->name ?? 'admin').' '.Wita::stamp($version->published_at)" />

        <x-alert variant="success" title="Resmi v{{ $version->version_no }} aktif">
            {{ $sessionCount }} sesi ditetapkan dan {{ $blockCount }} blok ruang dibuat.{{ $version->basedOn ? ' Versi sebelumnya (v'.$version->basedOn->version_no.') tetap tersimpan di riwayat revisi.' : '' }}
        </x-alert>

        <x-card class="flex flex-col gap-3">
            <h2 class="text-lg font-semibold">Dampak penetapan</h2>
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border text-xs text-muted"><tr><th class="py-2 font-medium">Layanan</th><th class="py-2 font-medium">Hasil</th></tr></thead>
                <tbody class="divide-y divide-border">
                    <tr><td class="py-3 font-medium">Keti • My Class</td><td class="py-3 text-muted">Menggunakan jadwal resmi dan pengajar sesuai periode mulai {{ Wita::date($version->effective_from) }}</td></tr>
                    <tr><td class="py-3 font-medium">Ketersediaan ruang • Keti dan publik</td><td class="py-3 text-muted">Mengikuti jadwal dan pemakaian pada waktu terkait; reservasi yang ada tidak diubah</td></tr>
                    <tr><td class="py-3 font-medium">Riwayat revisi</td><td class="py-3 text-muted">Versi sebelumnya, pelaku, waktu, dan alasan tersimpan di audit log</td></tr>
                </tbody>
            </table>
        </x-card>

        <div class="flex gap-3">
            <x-button :href="route('admin.schedules.index')">Lihat jadwal resmi v{{ $version->version_no }}</x-button>
            <form method="POST" action="{{ route('admin.drafts.store') }}">
                @csrf
                <x-button type="submit" variant="secondary">Susun revisi berikutnya</x-button>
            </form>
        </div>
    </div>
@endsection
