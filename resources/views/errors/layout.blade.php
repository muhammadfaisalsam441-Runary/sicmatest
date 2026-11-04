{{-- Halaman galat bergaya SICMA. Sengaja tanpa query database agar tetap tampil saat server bermasalah. --}}
<!DOCTYPE html>
<html lang="id">
    <head>
        @include('layouts.partials.head', ['title' => trim($__env->yieldContent('title'))])
    </head>
    <body class="flex min-h-screen flex-col">
        <header class="border-b border-border bg-surface">
            <div class="mx-auto flex h-[88px] w-full max-w-[1168px] items-center px-8">
                <a href="{{ url('/') }}" class="text-[28px] font-bold tracking-tight text-primary">SICMA</a>
            </div>
        </header>

        <main class="mx-auto flex w-full max-w-[1168px] flex-1 items-center justify-center px-8 py-16">
            <section class="flex w-full max-w-xl flex-col items-center gap-5 rounded-card border border-border bg-surface px-10 py-14 text-center">
                <span class="flex size-14 items-center justify-center rounded-full bg-primary-soft text-primary">
                    <i data-lucide="@yield('icon', 'circle-alert')" class="size-6"></i>
                </span>
                <span class="text-sm font-semibold text-muted">Kode @yield('code')</span>
                <h1 class="text-3xl font-semibold text-ink">@yield('title')</h1>
                <p class="max-w-md text-sm leading-relaxed text-muted">@yield('message')</p>
                <div class="mt-2 flex flex-wrap items-center justify-center gap-3">
                    @yield('actions')
                    <a href="{{ url('/') }}" class="inline-flex items-center justify-center rounded-control border border-border bg-surface px-5 py-3 text-sm font-semibold text-primary hover:bg-primary-soft/60">Kembali ke beranda</a>
                </div>
            </section>
        </main>

        <footer class="mx-auto w-full max-w-[1168px] px-8 pt-4 pb-10 text-xs text-muted">SICMA • Jurusan Teknik Informatika dan Komputer</footer>
    </body>
</html>
