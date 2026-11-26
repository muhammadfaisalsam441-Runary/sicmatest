@use('App\Support\Nav')
@extends('layouts.public')

@section('title', 'Beranda')

@section('content')
    <div class="flex flex-col gap-10 sm:gap-14">
        <section class="flex flex-col items-center gap-5 pt-4 text-center sm:gap-6 sm:pt-10">
            <span class="max-w-full rounded-full bg-primary-soft px-4 py-1.5 text-center text-[11px] font-semibold tracking-wide text-primary uppercase sm:px-16 sm:py-2">Layanan ruang kelas JTIK</span>
            <h1 class="max-w-3xl text-[clamp(1.75rem,1.2rem+2.5vw,3.25rem)] leading-[1.15] font-bold tracking-tight text-primary">Temukan ruangan yang tepat untuk kelasmu.</h1>
            <p class="max-w-xl text-base leading-relaxed text-muted sm:text-lg">Lihat ketersediaan ruang kelas JTIK tanpa akun. Masuk sebagai Keti untuk memesan dan mengelola penggunaan.</p>
            <div class="flex w-full flex-col items-center justify-center gap-3 sm:w-auto sm:flex-row">
                <x-button :href="Nav::url('login')" animated class="w-full sm:w-auto sm:min-w-52">Masuk ke SICMA</x-button>
                <x-button variant="secondary" :href="route('public.rooms', ['status' => 'tersedia'])" animated data-prefetch class="w-full sm:w-auto sm:min-w-52">Lihat ruang tersedia</x-button>
            </div>
            <p class="text-xs text-muted">Terbuka untuk semua • Pemesanan khusus ketua tingkat yang terdaftar</p>
        </section>

        <section class="flex flex-col gap-4">
            <div class="flex flex-col gap-2">
                <h2 class="text-2xl font-semibold text-ink sm:text-3xl">{{ $availableCount }} ruang tersedia sekarang</h2>
                <p class="text-sm text-muted">Tersedia berarti ruangan dapat digunakan. Digunakan berarti ruangan sedang dipakai.</p>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 rounded-control border border-border/80 bg-surface/80 px-3.5 py-2 text-xs">
                <span class="inline-flex items-center gap-2 font-medium text-muted">
                    <span class="sicma-live-dot size-2 shrink-0 rounded-full bg-success" aria-hidden="true"></span>
                    <span>Pratinjau {{ count($preview) }} dari {{ $availableCount }} ruang tersedia</span>
                </span>
                @include('public.partials.sim-label-button')
            </div>

            @if ($preview === [])
                <x-empty-state title="Belum ada ruang tersedia saat ini" message="Semua ruangan sedang digunakan atau di luar jam operasional. Lihat daftar lengkap untuk memeriksa status lainnya." icon="clock" />
            @else
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($preview as $room)
                        <x-room-card :name="$room['name']" :badge="$room['badge']" :location="$room['location']" :capacity="$room['capacity']" :facilities="$room['facilities'] ?? []"
                                     :time-label="$room['time_label']" :time="$room['time']" :remaining="$room['remaining'] ?? null" :note="$room['note']"
                                     :agenda-title="$room['agenda_title'] ?? null" :agenda-class="$room['agenda_class'] ?? null" :agenda-range="$room['agenda_range'] ?? null" :agenda-is-upcoming="$room['agenda_is_upcoming'] ?? false"
                                     style="--stagger: {{ $loop->index }}" class="h-full" />
                    @endforeach
                </div>
            @endif

            <div class="flex flex-col items-start justify-between gap-4 pt-2 sm:flex-row sm:items-center sm:gap-6">
                <p class="text-sm text-ink">Lihat daftar lengkap untuk mencari ruangan, memilih gedung, dan memeriksa status lainnya.</p>
                <x-button variant="secondary" :href="route('public.rooms')" animated data-prefetch class="w-full shrink-0 sm:w-auto">Lihat semua ruangan</x-button>
            </div>
        </section>

        <section class="flex flex-col items-start justify-between gap-4 rounded-card border border-border bg-surface p-5 sm:flex-row sm:items-center sm:gap-6 sm:px-6 sm:py-6">
            <div class="flex min-w-0 flex-col gap-2">
                <h2 class="text-lg font-semibold text-ink">Mau menggunakan ruangan untuk kelasmu?</h2>
                <p class="text-sm text-ink">Masuk dengan akun Keti yang terdaftar untuk memesan dan mengelola penggunaan ruangan.</p>
            </div>
            <x-button :href="Nav::url('login')" animated class="w-full shrink-0 sm:w-auto">Masuk sebagai Keti untuk memesan</x-button>
        </section>
    </div>
@endsection

@push('dialogs')
    @include('public.partials.dialogs')
@endpush
