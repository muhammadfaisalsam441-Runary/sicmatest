@extends('errors.layout')

@section('code', '419')
@section('icon', 'clock')
@section('title', 'Sesi sudah kedaluwarsa')
@section('message', 'Halaman terlalu lama dibiarkan terbuka sehingga formulir tidak bisa dikirim. Muat ulang halaman lalu coba lagi.')
@section('actions')
    <a href="{{ url()->previous() }}" class="inline-flex items-center justify-center rounded-control bg-primary px-5 py-3 text-sm font-semibold text-white hover:bg-primary/90">Muat ulang halaman</a>
@endsection
