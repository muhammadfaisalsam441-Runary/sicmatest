@use('App\Support\Nav')
@extends('layouts.public')

@section('title', 'Panduan')

@section('content')
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-3">
            <h1 class="text-[32px] font-semibold text-ink">Panduan menggunakan SICMA</h1>
            <p class="text-sm text-muted">Langkah singkat untuk ketua tingkat yang ingin menggunakan ruang bagi kelasnya.</p>
        </div>

        <ol class="grid grid-cols-3 gap-5">
            @foreach ([
                ['01', '1. Masuk dengan akun Keti', 'Gunakan akun ketua tingkat yang terhubung dengan kelasmu.'],
                ['02', '2. Temukan ruangan', 'Cari ruang tersedia atau lihat jadwal kelas melalui My Class.'],
                ['03', '3. Pesan dan gunakan', 'Pesan ruang, mulai penggunaan, lalu tekan selesai setelah dipakai.'],
            ] as [$number, $heading, $text])
                <li class="flex flex-col gap-4 rounded-card border border-border bg-surface px-6 py-6">
                    <span class="self-start rounded-control bg-primary-soft px-3 py-1.5 text-xs font-semibold text-primary">{{ $number }}</span>
                    <h2 class="text-lg font-medium text-ink">{{ $heading }}</h2>
                    <p class="text-sm leading-relaxed text-muted">{{ $text }}</p>
                </li>
            @endforeach
        </ol>

        @include('public.partials.keti-cta')
        @include('public.partials.register-card')
    </div>
@endsection
