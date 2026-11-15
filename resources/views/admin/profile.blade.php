@use('App\Support\Wita')
@extends('layouts.admin')

@section('title', 'Profil')

@section('breadcrumb-actions')
    <x-button variant="secondary" icon="arrow-left" :href="route('admin.dashboard')">Kembali</x-button>
@endsection

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Profil admin" subtitle="Kelola informasi akun dan keamanan masuk." />

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_400px]">
            <x-card class="flex flex-col gap-5">
                <div class="flex items-center gap-4">
                    <span class="flex size-14 items-center justify-center rounded-full bg-primary-soft text-lg font-semibold text-primary">{{ $user->initials() }}</span>
                    <div class="flex flex-col">
                        <h2 class="text-lg font-semibold">Informasi akun</h2>
                        <span class="text-sm text-muted">{{ $user->role->label() }}{{ $user->can_publish ? ' • Boleh menetapkan jadwal' : '' }}</span>
                    </div>
                </div>
                <form method="POST" action="{{ route('admin.profile.update') }}" class="flex flex-col gap-4">
                    @csrf
                    @method('PUT')
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-field label="Nama lengkap" name="name">
                            <x-input name="name" :value="$user->name" autocomplete="name" required />
                        </x-field>
                        <x-field label="Email" name="email">
                            <x-input type="email" name="email" :value="$user->email" autocomplete="email" required />
                        </x-field>
                    </div>
                    <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                        <div><dt class="text-muted">Unit</dt><dd class="font-medium">Jurusan Teknik Informatika dan Komputer</dd></div>
                        <div><dt class="text-muted">Institusi</dt><dd class="font-medium">Universitas Negeri Makassar</dd></div>
                        <div><dt class="text-muted">Masuk terakhir</dt><dd class="font-medium">{{ $user->last_login_at ? Wita::stamp($user->last_login_at) : '—' }}</dd></div>
                    </dl>
                    <div class="flex justify-end gap-3">
                        <x-button variant="secondary" type="reset">Reset</x-button>
                        <x-button type="submit">Simpan perubahan</x-button>
                    </div>
                </form>
            </x-card>

            <div class="flex flex-col gap-6">
                <x-card class="flex flex-col gap-4">
                    <h2 class="text-lg font-semibold">Ubah kata sandi</h2>
                    <form method="POST" action="{{ route('admin.profile.password') }}" class="flex flex-col gap-4" x-data="{ showCurrent: false, showNew: false, showConfirm: false }">
                        @csrf
                        @method('PUT')
                        <x-field label="Kata sandi saat ini" name="current_password">
                            <div class="relative">
                                <x-input ::type="showCurrent ? 'text' : 'password'" name="current_password" autocomplete="current-password" placeholder="Masukkan kata sandi saat ini" class="pr-16" required />
                                <button type="button" @click="showCurrent = !showCurrent" :aria-label="showCurrent ? 'Sembunyikan kata sandi saat ini' : 'Tampilkan kata sandi saat ini'" class="absolute right-2.5 top-1/2 -translate-y-1/2 cursor-pointer rounded px-1.5 py-0.5 text-xs font-semibold text-muted hover:text-ink" x-text="showCurrent ? 'Tutup' : 'Lihat'"></button>
                            </div>
                        </x-field>
                        <x-field label="Kata sandi baru" name="password" hint="Gunakan kombinasi huruf dan angka agar sulit ditebak.">
                            <div class="relative">
                                <x-input ::type="showNew ? 'text' : 'password'" name="password" autocomplete="new-password" placeholder="Minimal 8 karakter" class="pr-16" required />
                                <button type="button" @click="showNew = !showNew" :aria-label="showNew ? 'Sembunyikan kata sandi baru' : 'Tampilkan kata sandi baru'" class="absolute right-2.5 top-1/2 -translate-y-1/2 cursor-pointer rounded px-1.5 py-0.5 text-xs font-semibold text-muted hover:text-ink" x-text="showNew ? 'Tutup' : 'Lihat'"></button>
                            </div>
                        </x-field>
                        <x-field label="Ulangi kata sandi baru" name="password_confirmation">
                            <div class="relative">
                                <x-input ::type="showConfirm ? 'text' : 'password'" name="password_confirmation" autocomplete="new-password" placeholder="Ketik ulang kata sandi baru" class="pr-16" required />
                                <button type="button" @click="showConfirm = !showConfirm" :aria-label="showConfirm ? 'Sembunyikan konfirmasi kata sandi' : 'Tampilkan konfirmasi kata sandi'" class="absolute right-2.5 top-1/2 -translate-y-1/2 cursor-pointer rounded px-1.5 py-0.5 text-xs font-semibold text-muted hover:text-ink" x-text="showConfirm ? 'Tutup' : 'Lihat'"></button>
                            </div>
                        </x-field>
                        <x-button type="submit">Perbarui kata sandi</x-button>
                    </form>
                </x-card>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-button type="submit" variant="danger-outline" icon="log-out" class="w-full">Keluar dari akun</x-button>
                </form>
            </div>
        </div>
    </div>
@endsection
