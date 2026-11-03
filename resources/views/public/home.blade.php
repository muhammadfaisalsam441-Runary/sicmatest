@use('App\Support\Nav')
@extends('layouts.public')

@section('title', 'Beranda')

@section('content')
    <div class="flex flex-col gap-14">
        <section class="flex flex-col items-center gap-6 pt-10 text-center">
            <span class="rounded-full bg-primary-soft px-16 py-2 text-[11px] font-semibold tracking-wide text-primary uppercase">Layanan ruang kelas JTIK</span>
            <h1 class="max-w-3xl text-[56px] leading-[1.1] font-bold tracking-tight text-primary">Temukan ruangan yang tepat untuk kelasmu.</h1>
            <p class="max-w-xl text-lg leading-relaxed text-muted">Lihat ketersediaan ruang kelas JTIK tanpa akun. Masuk sebagai Keti untuk memesan dan mengelola penggunaan.</p>
            <div class="flex gap-3">
                <x-button :href="Nav::url('login')" class="w-52 py-4">Masuk ke SICMA</x-button>
                <x-button variant="secondary" :href="route('public.rooms', ['status' => 'tersedia'])" class="w-52 py-4">Lihat ruang tersedia</x-button>
            </div>
            <p class="text-xs text-muted">Terbuka untuk semua • Pemesanan khusus ketua tingkat yang terdaftar</p>
        </section>

        <section class="flex flex-col gap-4">
            <div class="flex items-start justify-between gap-6">
                <div class="flex flex-col gap-2">
                    <h2 class="text-3xl font-semibold text-ink">{{ $availableCount }} ruang tersedia sekarang</h2>
                    <p class="text-sm text-ink">Tersedia berarti ruangan dapat digunakan. Digunakan berarti ruangan sedang dipakai.</p>
                    <p class="text-xs text-ink">Pratinjau {{ count($preview) }} dari {{ $availableCount }} ruang tersedia</p>
                </div>
                @include('public.partials.sim-label-button')
            </div>

            @if ($preview === [])
                <x-empty-state title="Belum ada ruang tersedia saat ini" message="Semua ruangan sedang digunakan atau di luar jam operasional. Lihat daftar lengkap untuk memeriksa status lainnya." icon="clock" />
            @else
                <div class="grid grid-cols-3 gap-4">
                    @foreach ($preview as $room)
                        <x-room-card :name="$room['name']" :badge="$room['badge']" :location="$room['location']" :capacity="$room['capacity']"
                                     :time-label="$room['time_label']" :time="$room['time']" :note="$room['note']" />
                    @endforeach
                </div>
            @endif

            <div class="flex items-center justify-between gap-6 pt-2">
                <p class="text-sm text-ink">Lihat daftar lengkap untuk mencari ruangan, memilih gedung, dan memeriksa status lainnya.</p>
                <x-button variant="secondary" :href="route('public.rooms')" class="px-10">Lihat semua ruangan</x-button>
            </div>
        </section>

        <section class="flex items-center justify-between gap-6 rounded-card border border-border bg-surface px-6 py-6">
            <div class="flex flex-col gap-2">
                <h2 class="text-lg font-semibold text-ink">Mau menggunakan ruangan untuk kelasmu?</h2>
                <p class="text-sm text-ink">Masuk dengan akun Keti yang terdaftar untuk memesan dan mengelola penggunaan ruangan.</p>
            </div>
            <x-button :href="Nav::url('login')" class="shrink-0 px-9">Masuk sebagai Keti untuk memesan</x-button>
        </section>
    </div>
@endsection

@push('dialogs')
    @include('public.partials.dialogs')
@endpush
