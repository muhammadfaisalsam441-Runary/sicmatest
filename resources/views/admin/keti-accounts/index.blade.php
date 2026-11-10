@use('App\Enums\KetiApplicationStatus')
@use('App\Support\Wita')
@extends('layouts.admin')

@section('title', 'Akun Keti')
@section('breadcrumb', 'Akun Keti|'.$tab)

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.dashboard')">Dashboard</x-button>
@endsection

@section('content')
    @php
        $summary = [
            'pending' => [$counts['pending'].' pengajuan menunggu pemeriksaan', 'Email telah diverifikasi. Hak pemesanan belum diberikan.'],
            'approved' => [$counts['approved'].' akun Keti aktif', 'Akses Keti diberikan untuk kelas yang sudah diverifikasi.'],
            'rejected' => [$counts['rejected'].' pengajuan ditolak atau perlu diperbaiki', 'Alasan penolakan dapat dibaca dan pengajuan bisa diperbaiki.'],
        ][$tab];
    @endphp

    <div class="flex flex-col gap-6">
        <x-page-header title="Akun Keti" subtitle="Periksa identitas dan kelas sebelum memberi akses pemesanan." />

        <x-tabs :items="[
            ['label' => 'Menunggu persetujuan', 'href' => route('admin.keti-accounts.index', ['status' => 'pending']), 'active' => $tab === 'pending', 'count' => $counts['pending'] ?: null],
            ['label' => 'Aktif', 'href' => route('admin.keti-accounts.index', ['status' => 'approved']), 'active' => $tab === 'approved'],
            ['label' => 'Ditolak', 'href' => route('admin.keti-accounts.index', ['status' => 'rejected']), 'active' => $tab === 'rejected'],
        ]" />

        <x-card class="flex flex-col gap-4">
            <div class="flex flex-col gap-1">
                <h2 class="text-lg font-semibold">{{ $summary[0] }}</h2>
                <p class="text-sm text-muted">{{ $summary[1] }}</p>
            </div>

            @if ($applications->isEmpty())
                <x-empty-state :title="$tab === 'pending' ? 'Tidak ada pengajuan baru' : 'Belum ada data'" message="Pengajuan Keti yang sudah memverifikasi email akan muncul di sini." icon="users" />
            @else
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs text-muted">
                        <tr>
                            <th scope="col" class="py-3 pr-4 font-medium">Nama / NIM</th>
                            <th scope="col" class="py-3 pr-4 font-medium">Kelas</th>
                            <th scope="col" class="py-3 pr-4 font-medium">Email</th>
                            <th scope="col" class="py-3 pr-4 font-medium">Status</th>
                            <th scope="col" class="py-3 font-medium"><span class="sr-only">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($applications as $application)
                            <tr>
                                <td class="py-3 pr-4">
                                    <span class="block font-semibold">{{ $application->user->name }}</span>
                                    <span class="text-xs text-muted">{{ $application->user->nim ?? '—' }}</span>
                                </td>
                                <td class="py-3 pr-4">{{ $application->classGroup->name }}</td>
                                <td class="py-3 pr-4 text-muted">{{ $application->user->email }}</td>
                                <td class="py-3 pr-4">
                                    @php($inactive = $application->status === KetiApplicationStatus::Approved && ! $application->user->is_active)
                                    <x-status-badge :variant="match (true) {
                                        $inactive => 'neutral',
                                        $application->status === KetiApplicationStatus::Approved => 'success',
                                        $application->status === KetiApplicationStatus::Pending => 'primary',
                                        $application->status === KetiApplicationStatus::NeedsRevision => 'warning',
                                        default => 'danger',
                                    }">{{ $inactive ? 'Nonaktif' : match ($application->status) {
                                        KetiApplicationStatus::Pending => 'Menunggu',
                                        KetiApplicationStatus::Approved => 'Aktif',
                                        default => $application->status->label(),
                                    } }}</x-status-badge>
                                </td>
                                <td class="py-3 text-right">
                                    <x-button :variant="$tab === 'pending' ? 'primary' : 'soft'" class="px-4! py-2!" x-data x-on:click="$dispatch('open-modal', 'application-{{ $application->id }}')">
                                        {{ $tab === 'pending' ? 'Periksa' : 'Detail' }}
                                    </x-button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-card>

        <p class="text-sm text-muted">Pendaftaran publik hanya untuk Keti. Akun admin diberikan oleh pengelola yang berwenang.</p>
    </div>
@endsection

@push('dialogs')
    @foreach ($applications as $application)
        @php($applicant = $application->user)
        <x-modal name="application-{{ $application->id }}" :title="$application->status === KetiApplicationStatus::Pending ? $applicant->name : match ($application->status) {
            KetiApplicationStatus::Approved => 'Akun Keti aktif',
            KetiApplicationStatus::NeedsRevision => 'Pengajuan perlu diperbaiki',
            default => 'Pengajuan ditolak',
        }" width="max-w-[480px]">
            <div class="flex flex-col gap-4">
                <div class="flex flex-col gap-1 text-sm">
                    @unless ($application->status === KetiApplicationStatus::Pending)
                        <span class="text-base font-semibold text-ink">{{ $applicant->name }}</span>
                    @endunless
                    <span class="text-muted">NIM {{ $applicant->nim ?? '—' }} • {{ $applicant->email }}</span>
                    <span class="text-muted">Kelas yang diajukan: <span class="font-medium text-ink">{{ $application->classGroup->name }}</span></span>
                    <span class="text-xs text-muted">Diajukan {{ Wita::stamp($application->created_at) }}</span>
                </div>

                @if ($application->status === KetiApplicationStatus::Pending)
                    <x-info-box title="Pemeriksaan admin">
                        <p>{{ $applicant->hasVerifiedEmail() ? 'Email sudah terverifikasi.' : 'Email belum terverifikasi.' }} Cocokkan identitas dan kelas dengan data akademik sebelum menyetujui.</p>
                    </x-info-box>
                    <form method="POST" action="{{ route('admin.keti-accounts.approve', $application) }}" class="flex flex-col gap-4">
                        @csrf
                        <x-field label="Kelas yang ditetapkan" name="class_group_id-{{ $application->id }}" hint="Persetujuan mengaktifkan akses Keti untuk kelas ini.">
                            <x-select name="class_group_id" :id="'class_group_id-'.$application->id" :options="$classGroups" :value="$application->class_group_id" />
                        </x-field>
                        <x-button type="submit" icon="check">Setujui dan aktifkan akun</x-button>
                    </form>
                    <div class="flex gap-3">
                        <x-button variant="danger-outline" class="flex-1" x-on:click="$dispatch('close-modal', 'application-{{ $application->id }}'); $dispatch('open-modal', 'decline-{{ $application->id }}')">Tolak dengan alasan</x-button>
                        <x-button variant="secondary" class="flex-1" x-on:click="$dispatch('close-modal', 'application-{{ $application->id }}')">Batal</x-button>
                    </div>
                @elseif ($application->status === KetiApplicationStatus::Approved)
                    <x-info-box title="{{ $applicant->is_active ? 'Akun dapat menggunakan fitur Keti' : 'Akun dinonaktifkan' }}" :variant="$applicant->is_active ? 'success' : 'danger'">
                        <p>{{ $applicant->is_active ? 'Email dan kelas sudah diverifikasi.' : 'Hak pemesanan sudah dicabut. Riwayat tetap tersimpan.' }}</p>
                        @if ($application->reviewer)
                            <p class="text-xs">Disetujui {{ $application->reviewer->name }} • {{ Wita::stamp($application->reviewed_at) }}</p>
                        @endif
                    </x-info-box>
                    @if ($applicant->is_active)
                        <form method="POST" action="{{ route('admin.keti-accounts.deactivate', $applicant) }}" class="flex flex-col gap-3">
                            @csrf
                            <x-field label="Alasan menonaktifkan (wajib)" name="reason">
                                <x-input name="reason" :id="'reason-deactivate-'.$applicant->id" placeholder="Contoh: masa jabatan Keti berakhir" required />
                            </x-field>
                            <x-button type="submit" variant="danger-outline" icon="user-x">Nonaktifkan akun</x-button>
                        </form>
                    @endif
                    <x-button variant="secondary" x-on:click="$dispatch('close-modal', 'application-{{ $application->id }}')">Tutup</x-button>
                @else
                    <x-info-box title="Alasan untuk pendaftar" variant="danger">
                        <p>{{ $application->review_note ?? '—' }}</p>
                    </x-info-box>
                    <x-button variant="secondary" x-on:click="$dispatch('close-modal', 'application-{{ $application->id }}')">Tutup</x-button>
                @endif
            </div>
        </x-modal>

        @if ($application->status === KetiApplicationStatus::Pending)
            <x-modal name="decline-{{ $application->id }}" title="Alasan untuk pendaftar" width="max-w-[480px]">
                <form method="POST" action="{{ route('admin.keti-accounts.reject', $application) }}" class="flex flex-col gap-4">
                    @csrf
                    <x-field label="Alasan" name="reason" hint="Alasan ini dapat dilihat oleh pendaftar untuk memperbaiki pengajuan.">
                        <x-textarea name="reason" :id="'reason-'.$application->id" rows="4" placeholder="Data kelas belum sesuai. Periksa kelas yang diwakili dan ajukan kembali." required />
                    </x-field>
                    <div class="flex flex-wrap justify-end gap-3">
                        <x-button variant="secondary" x-on:click="$dispatch('close-modal', 'decline-{{ $application->id }}')">Batal</x-button>
                        <x-button type="submit" variant="soft" formaction="{{ route('admin.keti-accounts.revise', $application) }}">Minta perbaikan</x-button>
                        <x-button type="submit" variant="danger" icon="x-circle">Kirim penolakan</x-button>
                    </div>
                </form>
            </x-modal>
        @endif
    @endforeach
@endpush
