<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name') }}</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen">
        {{-- Halaman awal Fase 0 (cek token & font). Layout publik dibuat di Fase 2, isi Beranda di Fase 5. --}}
        <header class="border-b border-border bg-surface">
            <div class="mx-auto flex h-16 max-w-[1100px] items-center justify-between px-8">
                <span class="text-xl font-extrabold tracking-tight text-primary">SICMA</span>
                <nav class="flex gap-8 text-sm font-medium text-muted">
                    <span class="border-b-2 border-primary pb-1 text-primary">Beranda</span>
                    <span>Ruangan</span>
                    <span>Panduan</span>
                    <span>Bantuan</span>
                </nav>
                <span class="rounded-control bg-primary px-5 py-2 text-sm font-semibold text-white">Masuk</span>
            </div>
        </header>

        <main class="mx-auto flex max-w-[1100px] flex-col gap-10 px-8 py-16">
            <section class="flex flex-col items-center gap-5 text-center">
                <span class="rounded-full bg-primary-soft px-4 py-1 text-xs font-semibold text-primary">Layanan ruang kelas JTIK</span>
                <h1 class="max-w-xl text-5xl leading-tight font-extrabold text-ink">Temukan ruangan yang tepat untuk kelasmu.</h1>
                <p class="max-w-lg text-muted">Lihat ketersediaan ruang kelas JTIK tanpa akun. Masuk sebagai Keti untuk memesan dan mengelola penggunaan.</p>
            </section>

            <section class="flex flex-col gap-4 rounded-card border border-border bg-surface p-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-bold">Pemeriksaan setup (Fase 0)</h2>
                    <span class="flex items-center gap-2 text-xs text-muted">
                        <i data-lucide="clock" class="size-4"></i>
                        {{ now()->translatedFormat('j M Y') }} • {{ now()->format('H.i') }} {{ config('sicma.timezone_label') }}
                    </span>
                </div>
                <div class="grid grid-cols-6 gap-3 text-xs">
                    @foreach ([
                        'primary' => 'bg-primary',
                        'primary-soft' => 'bg-primary-soft',
                        'page' => 'bg-page',
                        'surface' => 'bg-surface',
                        'ink' => 'bg-ink',
                        'muted' => 'bg-muted',
                        'border' => 'bg-border',
                        'success' => 'bg-success',
                        'success-soft' => 'bg-success-soft',
                        'danger' => 'bg-danger',
                        'danger-soft' => 'bg-danger-soft',
                    ] as $token => $swatchClass)
                        <div class="flex flex-col gap-2">
                            <div class="h-12 rounded-control border border-border {{ $swatchClass }}"></div>
                            <span class="font-medium text-muted">{{ $token }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="flex items-center gap-3">
                    <span class="rounded-full bg-success-soft px-3 py-1 text-xs font-semibold text-success">Tersedia</span>
                    <span class="rounded-full bg-danger-soft px-3 py-1 text-xs font-semibold text-danger">Digunakan</span>
                    <span class="rounded-full bg-primary-soft px-3 py-1 text-xs font-semibold text-primary">Data simulasi</span>
                </div>
            </section>
        </main>

        <footer class="py-8 text-center text-xs text-muted">SICMA • Jurusan Teknik Informatika dan Komputer</footer>
    </body>
</html>
