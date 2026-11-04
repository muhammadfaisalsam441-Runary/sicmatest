@use('App\Support\Wita')
@extends('layouts.admin')

@section('title', 'Profil')

@section('breadcrumb-actions')
    <x-button variant="secondary" icon="arrow-left" :href="route('admin.dashboard')">Kembali</x-button>
@endsection

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Profil admin" subtitle="Kelola informasi akun dan keamanan masuk." />

        <div class="grid grid-cols-[1fr_400px] gap-6">
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
                    <div class="grid grid-cols-2 gap-4">
                        <x-field label="Nama lengkap" name="name">
                            <x-input name="name" :value="$user->name" required />
                        </x-field>
                        <x-field label="Email" name="email">
                            <x-input type="email" name="email" :value="$user->email" required />
                        </x-field>
                    </div>
                    <dl class="grid grid-cols-2 gap-4 text-sm">
                        <div><dt class="text-muted">Unit</dt><dd class="font-medium">Jurusan Teknik Informatika dan Komputer</dd></div>
                        <div><dt class="text-muted">Institusi</dt><dd class="font-medium">Universitas Negeri Makassar</dd></div>
                        <div><dt class="text-muted">Masuk terakhir</dt><dd class="font-medium">{{ $user->last_login_at ? Wita::stamp($user->last_login_at) : '—' }}</dd></div>
                    </dl>
                    <div class="flex justify-end gap-3">
                        <x-button variant="secondary" type="reset">Batal</x-button>
                        <x-button type="submit">Simpan perubahan</x-button>
                    </div>
                </form>
            </x-card>

            <div class="flex flex-col gap-6">
                <x-card class="flex flex-col gap-4">
                    <h2 class="text-lg font-semibold">Ubah kata sandi</h2>
                    <form method="POST" action="{{ route('admin.profile.password') }}" class="flex flex-col gap-4">
                        @csrf
                        @method('PUT')
                        <x-field label="Kata sandi saat ini" name="current_password">
                            <x-input type="password" name="current_password" placeholder="Masukkan kata sandi saat ini" required />
                        </x-field>
                        <x-field label="Kata sandi baru" name="password" hint="Gunakan kombinasi huruf dan angka agar sulit ditebak.">
                            <x-input type="password" name="password" placeholder="Minimal 8 karakter" required />
                        </x-field>
                        <x-field label="Ulangi kata sandi baru" name="password_confirmation">
                            <x-input type="password" name="password_confirmation" placeholder="Ketik ulang kata sandi baru" required />
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
