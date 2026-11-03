@extends('layouts.auth')

@section('title', 'Periksa emailmu')

@section('content')
    <div class="flex flex-col gap-5">
        <x-auth-heading title="Periksa emailmu" subtitle="Jika alamat terdaftar, tautan atur ulang kata sandi akan dikirim." />
        <x-info-box title="Tautan pemulihan">
            <p>Periksa kotak masuk dan folder spam. Tautan hanya dapat digunakan untuk akun terkait.</p>
        </x-info-box>

        @if ($devResetUrl)
            <x-dev-hint>Email hanya dicatat di log, tautan dibuka langsung dari sini.</x-dev-hint>
            <x-button :href="$devResetUrl" class="w-full">Buka tautan atur ulang</x-button>
        @endif

        <form method="POST" action="{{ route('password.email') }}">
            @csrf
            <input type="hidden" name="identity" value="{{ $identity }}">
            <x-button type="submit" variant="secondary" class="w-full">Kirim ulang email</x-button>
        </form>
        <x-button variant="secondary" :href="route('login')" class="w-full">Kembali ke halaman masuk</x-button>
    </div>
@endsection
