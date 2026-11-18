@extends('layouts.auth')

@section('title', 'Daftar sebagai Keti')

@php
    $isDuplicate = in_array('NIM sudah terdaftar.', $errors->get('nim'), true) || in_array('Email sudah terdaftar.', $errors->get('email'), true);
@endphp

@section('content')
    <div class="flex flex-col gap-5">
        @if ($isDuplicate)
            <x-auth-heading title="Periksa data pendaftaran" subtitle="NIM atau email sudah terdaftar. Gunakan akun yang ada atau perbaiki isian." />
            <x-info-box title="Akun mungkin sudah ada" variant="danger">
                <p>Gunakan pemulihan kata sandi jika kamu sudah memiliki akun.</p>
            </x-info-box>
            <div class="grid grid-cols-2 gap-3">
                <x-button variant="secondary" href="#form-daftar">Perbaiki data</x-button>
                <x-button variant="secondary" :href="route('password.request')">Lupa kata sandi</x-button>
            </div>
        @else
            <x-auth-heading title="Daftar sebagai Keti" subtitle="Lengkapi data diri dan kelas yang kamu wakili." />
        @endif

        <form id="form-daftar" method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-4" novalidate>
            @csrf
            <x-field label="Nama lengkap" name="name">
                <x-input name="name" placeholder="Nama lengkap" autocomplete="name" required />
            </x-field>
            <x-field label="NIM" name="nim">
                <x-input name="nim" placeholder="Nomor Induk Mahasiswa" inputmode="numeric" autocomplete="username" required />
            </x-field>
            <x-field label="Email" name="email">
                <x-input name="email" type="email" placeholder="nama@email.com" autocomplete="email" required />
            </x-field>
            <x-field label="Kelas yang diwakili" name="class_group_id">
                <x-select name="class_group_id" :options="$classGroups" placeholder="Pilih kelas" required />
            </x-field>
            <x-field label="Kata sandi" name="password" hint="Minimal 8 karakter.">
                <x-input name="password" type="password" placeholder="Minimal 8 karakter" autocomplete="new-password" required />
            </x-field>

            <p class="text-xs text-muted">Kelas yang dipilih akan diverifikasi admin sebelum akun dapat memesan ruang.</p>

            <x-button type="submit" class="sicma-auth-submit w-full !rounded-[20px]">Daftar dan verifikasi email</x-button>

            <p class="mt-1 text-center text-xs text-muted">
                Sudah punya akun?
                <a href="{{ route('login') }}" class="ml-1 font-extrabold text-primary underline decoration-primary underline-offset-2 hover:text-primary/80 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">Masuk</a>
            </p>

            <div class="mt-2 flex flex-col gap-2.5">
                <a href="{{ route('home') }}"
                   class="sicma-auth-pill-outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                    <i data-lucide="arrow-left" aria-hidden="true" class="size-4"></i>
                    <span>Kembali ke beranda</span>
                </a>
            </div>
        </form>
    </div>
@endsection
