@extends('layouts.auth')

@section('title', 'Masuk')

@section('content')
    <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-5" novalidate>
        @csrf
        <x-auth-heading title="Masuk ke SICMA"
                        :subtitle="$errors->has('identity') ? $errors->first('identity') : 'Gunakan email atau NIM dan kata sandi yang terdaftar.'"
                        :danger="$errors->has('identity')" />

        <x-field label="Email atau NIM" name="identity">
            <x-input name="identity" autocomplete="username" autofocus required />
        </x-field>
        <x-field label="Kata sandi" name="password">
            <x-input name="password" type="password" autocomplete="current-password" required />
        </x-field>

        <x-button type="submit" class="w-full">Masuk</x-button>
        <x-button variant="secondary" :href="route('password.request')" class="w-full">Lupa kata sandi?</x-button>
        <x-button variant="secondary" :href="route('register')" class="w-full">Daftar sebagai Keti</x-button>

        <p class="text-xs text-muted">
            Belum punya akun? Daftar sebagai Keti. Akun admin diberikan oleh pengelola.
            <button type="button" x-data x-on:click="$dispatch('open-modal', 'bantuan-akun')" class="font-semibold text-primary hover:underline">Bantuan akun</button>
        </p>

        <x-button variant="secondary" :href="route('home')" class="w-full">Kembali ke beranda</x-button>
    </form>
@endsection

@push('dialogs')
    <x-modal name="bantuan-akun" width="max-w-md">
        <div class="flex flex-col gap-4">
            <h2 class="text-xl font-semibold text-ink">Bantuan akun</h2>
            <p class="text-sm leading-relaxed text-muted">Hubungi staf JTIK untuk memeriksa akun atau mengatur ulang kata sandi. Siapkan NIM dan kelas yang kamu wakili.</p>
            <x-button x-on:click="$dispatch('close-modal', 'bantuan-akun')" class="w-full">Mengerti</x-button>
        </div>
    </x-modal>
@endpush
