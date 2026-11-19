@extends('layouts.auth')

@section('title', 'Masuk')

@section('content')
    <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-4" novalidate>
        @csrf
        <x-auth-heading title="Masuk ke SICMA"
                        :subtitle="$errors->has('identity') ? $errors->first('identity') : 'Gunakan email atau NIM dan kata sandi yang terdaftar.'"
                        :danger="$errors->has('identity')" />

        <x-field label="Email atau NIM" name="identity">
            <x-input name="identity" placeholder="Email atau NIM" autocomplete="username" autocorrect="off" autocapitalize="none" spellcheck="false" enterkeyhint="next" autofocus required />
        </x-field>
        <x-field label="Kata sandi" name="password">
            <x-input name="password" type="password" placeholder="Kata sandi" autocomplete="current-password" enterkeyhint="done" required />
        </x-field>

        <p class="-mt-1 text-right">
            <a href="{{ route('password.request') }}" class="inline-flex min-h-10 items-center py-1 text-xs font-bold text-muted underline decoration-muted/70 underline-offset-2 transition hover:text-ink hover:decoration-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                Lupa kata sandi?
            </a>
        </p>

        <x-button type="submit" class="sicma-auth-submit min-h-11 w-full !rounded-[20px]">Masuk</x-button>

        <p class="mt-2 text-center text-xs text-muted">
            Belum punya akun?
            <a href="{{ route('register') }}" class="ml-1 inline-flex min-h-10 items-center py-1 font-extrabold text-primary underline decoration-primary underline-offset-2 hover:text-primary/80 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">Daftar sebagai Keti</a>
        </p>

        <div class="mt-3 flex flex-col gap-3">
            <button type="button"
                    x-data
                    x-on:click="$dispatch('open-modal', 'bantuan-akun')"
                    class="sicma-auth-pill-dark min-h-11 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                <i data-lucide="circle-help" aria-hidden="true" class="size-4"></i>
                <span>Bantuan akun</span>
            </button>
            <a href="{{ route('home') }}"
               class="sicma-auth-pill-outline min-h-11 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                <i data-lucide="arrow-left" aria-hidden="true" class="size-4"></i>
                <span>Kembali ke beranda</span>
            </a>
        </div>
    </form>
@endsection

@push('dialogs')
    <x-modal name="bantuan-akun" width="max-w-md">
        <div class="flex flex-col gap-4">
            <h2 class="text-xl font-semibold text-ink">Bantuan akun</h2>
            <p class="text-sm leading-relaxed text-muted">Hubungi staf JTIK untuk memeriksa akun atau mengatur ulang kata sandi. Siapkan NIM dan kelas yang kamu wakili.</p>
            <x-button x-on:click="$dispatch('close-modal', 'bantuan-akun')" class="min-h-11 w-full">Mengerti</x-button>
        </div>
    </x-modal>
@endpush
