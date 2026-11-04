@extends('errors.layout')

@section('code', '404')
@section('icon', 'search-x')
@section('title', 'Halaman tidak ditemukan')
@section('message', 'Alamat yang kamu buka tidak ada atau sudah dipindahkan. Periksa kembali tautannya atau lihat daftar ruangan.')
@section('actions')
    <a href="{{ url('/ruangan') }}" class="inline-flex items-center justify-center rounded-control bg-primary px-5 py-3 text-sm font-semibold text-white hover:bg-primary/90">Lihat ruangan</a>
@endsection
