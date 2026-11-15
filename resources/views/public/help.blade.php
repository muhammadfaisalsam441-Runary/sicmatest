@extends('layouts.public')

@section('title', 'Bantuan')

@section('content')
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-3">
            <h1 class="text-2xl font-semibold text-ink sm:text-[32px]">Bantuan akun dan penggunaan</h1>
            <p class="text-sm text-muted">Temukan jawaban tentang akses akun Keti dan informasi ketersediaan ruangan.</p>
        </div>

        @include('public.partials.faq')
        @include('public.partials.register-card')
    </div>
@endsection
