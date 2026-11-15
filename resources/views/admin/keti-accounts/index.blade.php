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

    <div class="flex flex-col gap-4 admin-page-stack"
         x-data="{
             selectedIds: [],
             pageIds: @js($tab === 'pending' ? $applications->pluck('id')->all() : []),
             isAllSelected() {
                 const curr = this.selectedIds.map(Number);
                 return this.pageIds.length > 0 && this.pageIds.every(id => curr.includes(Number(id)));
             },
             toggleSelectAll(checked) {
                 const page = this.pageIds.map(Number);
                 const curr = this.selectedIds.map(Number);
                 this.selectedIds = checked ? [...new Set([...curr, ...page])] : curr.filter(id => !page.includes(id));
             },
         }"
         :data-editing="selectedIds.length > 0 ? 'true' : null">
        <x-page-header title="Akun Keti" />

        <x-card padding="p-4" class="admin-table-card flex flex-col gap-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-1.5">
                    @foreach ([
                        ['key' => 'pending', 'label' => 'Menunggu persetujuan', 'count' => $counts['pending']],
                        ['key' => 'approved', 'label' => 'Aktif', 'count' => $counts['approved']],
                        ['key' => 'rejected', 'label' => 'Ditolak', 'count' => $counts['rejected']],
                    ] as $pill)
                        <a href="{{ route('admin.keti-accounts.index', ['status' => $pill['key']]) }}"
                           @class([
                               'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition',
                               'bg-primary text-white shadow-2xs' => $tab === $pill['key'],
                               'bg-page text-muted hover:text-ink hover:bg-border' => $tab !== $pill['key'],
                           ])>
                            <span>{{ $pill['label'] }}</span>
                            @if ($pill['count'])
                                <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ $tab === $pill['key'] ? 'bg-white/20 text-white' : 'bg-surface text-muted' }}">{{ $pill['count'] }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>

                <span class="text-xs font-medium text-muted" title="{{ $summary[1] }}">{{ $summary[0] }}</span>
            </div>

            <form method="GET" action="{{ route('admin.keti-accounts.index') }}" class="flex flex-wrap items-center gap-2">
                <input type="hidden" name="status" value="{{ $tab }}">
                <div class="relative flex-1 min-w-[220px]">
                    <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true"></i>
                    <input id="keti-search" type="search" name="q" value="{{ $keyword }}" data-table-search
                           aria-label="Cari nama mahasiswa, NIM, email, atau kelas"
                           @input.debounce.400ms="$el.form.requestSubmit()"
                           placeholder="Cari nama mahasiswa, NIM, email, atau kelas (tekan /)..."
                           class="w-full rounded-control border border-border bg-surface pl-9 pr-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-2xs">
                </div>
                <select id="keti-class-filter" name="kelas" aria-label="Filter kelas" @change="$el.form.requestSubmit()"
                        class="w-full sm:w-52 rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-2xs">
                    <option value="">Semua Kelas</option>
                    @foreach ($classGroups as $cgId => $cgName)
                        <option value="{{ $cgId }}" @selected($classGroupFilter == $cgId)>{{ $cgName }}</option>
                    @endforeach
                </select>
                @if ($keyword !== '' || $classGroupFilter !== null)
                    <a href="{{ route('admin.keti-accounts.index', ['status' => $tab]) }}"
                       class="inline-flex h-[38px] items-center gap-1.5 rounded-control border border-border bg-surface px-3 text-xs font-medium text-muted hover:text-ink hover:bg-page transition shadow-2xs"
                       title="Reset filter" aria-label="Reset filter">
                        <i data-lucide="rotate-ccw" class="size-3.5" aria-hidden="true"></i>
                        <span>Reset</span>
                    </a>
                @endif
            </form>

            @if ($applications->isEmpty())
                <x-empty-state :title="$keyword !== '' || $classGroupFilter !== null ? 'Tidak ada pengajuan yang cocok' : ($tab === 'pending' ? 'Tidak ada pengajuan baru' : 'Belum ada data')"
                               message="{{ $keyword !== '' || $classGroupFilter !== null ? 'Coba kata kunci atau kelas lain.' : 'Pengajuan Keti yang sudah memverifikasi email akan muncul di sini.' }}"
                               icon="users">
                    @if ($keyword !== '' || $classGroupFilter !== null)
                        <x-button variant="soft" :href="route('admin.keti-accounts.index', ['status' => $tab])">Hapus filter</x-button>
                    @endif
                </x-empty-state>
            @else
                <x-table-scroll>
                    <x-slot:title>{{ $applications->count() }} akun ditampilkan</x-slot:title>
                    <x-slot:headerActions>
                        <x-table-density-toggle />
                    </x-slot:headerActions>
                    <table class="w-full text-left text-sm">
                        <thead class="sticky top-0 z-10 border-b border-border bg-page text-xs font-semibold text-muted">
                            <tr>
                                @if ($tab === 'pending')
                                    <th scope="col" class="w-10 px-4 py-3 text-center">
                                        <input type="checkbox"
                                               @change="toggleSelectAll($event.target.checked)"
                                               :checked="isAllSelected()"
                                               aria-label="Pilih semua pengajuan yang tampil"
                                               title="Pilih semua yang tampil (mendukung Shift+Klik)"
                                               class="size-4 rounded border-border text-primary focus:ring-primary cursor-pointer">
                                    </th>
                                @endif
                                <th scope="col" class="px-4 py-3 font-medium">Nama / NIM</th>
                                <th scope="col" class="px-4 py-3 font-medium">Kelas</th>
                                <th scope="col" class="px-4 py-3 font-medium">Email</th>
                                <th scope="col" class="px-4 py-3 font-medium">Status</th>
                                <th scope="col" class="px-4 py-3 font-medium"><span class="sr-only">Aksi</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($applications as $application)
                                @php
                                    $applicant = $application->user;
                                    $inactive = $application->status === KetiApplicationStatus::Approved && ! $applicant->is_active;
                                    $appPayload = [
                                        'id' => $application->id,
                                        'name' => $applicant->name,
                                        'nim' => $applicant->nim ?? '—',
                                        'email' => $applicant->email,
                                        'emailVerified' => $applicant->hasVerifiedEmail(),
                                        'classGroupName' => $application->classGroup->name,
                                        'classGroupId' => (string) $application->class_group_id,
                                        'createdStamp' => Wita::stamp($application->created_at),
                                        'status' => $application->status->value,
                                        'isActive' => (bool) $applicant->is_active,
                                        'reviewerName' => $application->reviewer?->name,
                                        'reviewedStamp' => $application->reviewed_at ? Wita::stamp($application->reviewed_at) : null,
                                        'reviewNote' => $application->review_note ?? '—',
                                        'approveUrl' => route('admin.keti-accounts.approve', $application),
                                        'deactivateUrl' => route('admin.keti-accounts.deactivate', $applicant),
                                        'rejectUrl' => route('admin.keti-accounts.reject', $application),
                                        'reviseUrl' => route('admin.keti-accounts.revise', $application),
                                    ];
                                @endphp
                                <tr class="transition hover:bg-primary-soft/20" :class="{ 'bg-primary-soft/30': selectedIds.map(Number).includes({{ $application->id }}) }">
                                    @if ($tab === 'pending')
                                        <td class="px-4 py-3 text-center">
                                            <input type="checkbox"
                                                   value="{{ $application->id }}"
                                                   x-model.number="selectedIds"
                                                   aria-label="Pilih {{ $applicant->name }}"
                                                   class="size-4 rounded border-border text-primary focus:ring-primary cursor-pointer">
                                        </td>
                                    @endif
                                    <td class="px-4 py-3">
                                        <span class="block font-semibold">{{ $applicant->name }}</span>
                                        <span class="text-xs text-muted">{{ $applicant->nim ?? '—' }}</span>
                                    </td>
                                    <td class="px-4 py-3">{{ $application->classGroup->name }}</td>
                                    <td class="px-4 py-3 text-muted">{{ $applicant->email }}</td>
                                    <td class="px-4 py-3">
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
                                    <td class="px-4 py-3 text-right">
                                        <x-button size="sm" :variant="$tab === 'pending' ? 'primary' : 'soft'" x-data x-on:click="$dispatch('keti-open-detail', @js($appPayload))">
                                            {{ $tab === 'pending' ? 'Periksa' : 'Detail' }}
                                        </x-button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-table-scroll>
            @endif
        </x-card>

        <p class="text-sm text-muted">Pendaftaran publik hanya untuk Keti. Akun admin diberikan oleh pengelola yang berwenang.</p>

        @if ($tab === 'pending')
            <div x-show="selectedIds.length > 0"
                 x-cloak
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 translate-y-4"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100 translate-y-0"
                 x-transition:leave-end="opacity-0 translate-y-4"
                 class="fixed bottom-6 left-1/2 z-40 flex w-max max-w-[calc(100vw-2rem)] -translate-x-1/2 flex-wrap items-center gap-3 rounded-xl border border-primary/30 bg-ink px-6 py-3.5 text-surface shadow-2xl">
                <div class="flex items-center gap-2">
                    <i data-lucide="circle-check" class="size-5 text-success" aria-hidden="true"></i>
                    <span class="text-sm font-semibold"><span x-text="selectedIds.length"></span> pengajuan dipilih</span>
                </div>
                <div class="h-4 w-px bg-surface/20"></div>
                <form method="POST" action="{{ route('admin.keti-accounts.batch-approve') }}" class="inline-flex items-center">
                    @csrf
                    <template x-for="id in selectedIds" :key="id">
                        <input type="hidden" name="application_ids[]" :value="id">
                    </template>
                    <button type="submit" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-success px-3.5 py-1.5 text-xs font-semibold text-white shadow-xs transition hover:bg-success/90">
                        <i data-lucide="user-check" class="size-3.5" aria-hidden="true"></i>
                        Setujui Sekaligus
                    </button>
                </form>
                <button type="button" @click="selectedIds = []" class="ml-1 cursor-pointer text-xs text-surface/70 transition hover:text-white">
                    Batalkan Pilihan
                </button>
            </div>
        @endif
    </div>
@endsection

@push('dialogs')
    <div x-data="{
        activeApp: null,
        declineMode: 'reject',
        deactivateConfirmed: false,
        openDetail(payload) {
            this.activeApp = payload;
            this.deactivateConfirmed = false;
            this.$dispatch('open-modal', 'application-detail');
            this.$nextTick(() => window.renderIcons());
        },
        openDecline() {
            this.declineMode = 'reject';
            this.$dispatch('close-modal', 'application-detail');
            this.$dispatch('open-modal', 'application-decline');
            this.$nextTick(() => window.renderIcons());
        }
    }"
    @keti-open-detail.window="openDetail($event.detail)">
        <x-modal name="application-detail" aria-label="Detail pengajuan akun Keti" width="max-w-[480px]">
            <template x-if="activeApp">
                <div class="flex flex-col gap-4">
                    <div class="flex items-start justify-between gap-4">
                        <h2 class="text-xl font-semibold text-ink" x-text="activeApp.status === 'pending' ? activeApp.name : (activeApp.status === 'approved' ? 'Akun Keti aktif' : (activeApp.status === 'needs_revision' ? 'Pengajuan perlu diperbaiki' : 'Pengajuan ditolak'))"></h2>
                        <button type="button" x-on:click="$dispatch('close-modal', 'application-detail')" aria-label="Tutup dialog" class="text-muted hover:text-ink cursor-pointer">
                            <i data-lucide="x" class="size-5" aria-hidden="true"></i>
                        </button>
                    </div>

                    <div class="flex flex-col gap-1 text-sm">
                        <span x-show="activeApp.status !== 'pending'" class="text-base font-semibold text-ink" x-text="activeApp.name"></span>
                        <span class="text-muted">NIM <span x-text="activeApp.nim"></span> • <span x-text="activeApp.email"></span></span>
                        <span class="text-muted">Kelas yang diajukan: <span class="font-medium text-ink" x-text="activeApp.classGroupName"></span></span>
                        <span class="text-xs text-muted">Diajukan <span x-text="activeApp.createdStamp"></span></span>
                    </div>

                    <template x-if="activeApp.status === 'pending'">
                        <div class="flex flex-col gap-4">
                            <x-info-box title="Pemeriksaan admin">
                                <p><span x-text="activeApp.emailVerified ? 'Email sudah terverifikasi.' : 'Email belum terverifikasi.'"></span> Cocokkan identitas dan kelas dengan data akademik sebelum menyetujui.</p>
                            </x-info-box>
                            <form method="POST" :action="activeApp.approveUrl" class="flex flex-col gap-4">
                                @csrf
                                <x-field label="Kelas yang ditetapkan" name="class_group_id" hint="Persetujuan mengaktifkan akses Keti untuk kelas ini.">
                                    <x-select name="class_group_id" :options="$classGroups" x-model="activeApp.classGroupId" />
                                </x-field>
                                <x-button type="submit" icon="check">Setujui dan aktifkan akun</x-button>
                            </form>
                            <div class="flex gap-3">
                                <x-button variant="danger-outline" class="flex-1" x-on:click="openDecline()">Tolak / Minta Perbaikan</x-button>
                                <x-button variant="secondary" class="flex-1" x-on:click="$dispatch('close-modal', 'application-detail')">Batal</x-button>
                            </div>
                        </div>
                    </template>

                    <template x-if="activeApp.status === 'approved'">
                        <div class="flex flex-col gap-4">
                            <div class="rounded-card border p-4 text-sm" :class="activeApp.isActive ? 'border-success/30 bg-success-soft/50 text-ink' : 'border-danger/30 bg-danger-soft/50 text-ink'">
                                <p class="font-semibold" x-text="activeApp.isActive ? 'Akun dapat menggunakan fitur Keti' : 'Akun dinonaktifkan'"></p>
                                <p class="mt-1 text-muted" x-text="activeApp.isActive ? 'Email dan kelas sudah diverifikasi.' : 'Hak pemesanan sudah dicabut. Riwayat tetap tersimpan.'"></p>
                                <p x-show="activeApp.reviewerName" class="mt-1 text-xs text-muted">Disetujui <span x-text="activeApp.reviewerName"></span> • <span x-text="activeApp.reviewedStamp"></span></p>
                            </div>
                            <template x-if="activeApp.isActive">
                                <form method="POST" :action="activeApp.deactivateUrl" class="flex flex-col gap-3">
                                    @csrf
                                    <x-field label="Alasan menonaktifkan (wajib)" name="reason" for="deactivate-reason" :required="true">
                                        <x-input name="reason" id="deactivate-reason" placeholder="Contoh: masa jabatan Keti berakhir" required />
                                    </x-field>
                                    <label class="flex items-center gap-2 text-xs font-medium text-ink cursor-pointer">
                                        <input type="checkbox" x-model="deactivateConfirmed" required class="size-4 accent-danger rounded cursor-pointer">
                                        <span>Saya yakin ingin mencabut akses akun Keti ini.</span>
                                    </label>
                                    <x-button type="submit" variant="danger-outline" icon="user-x" x-bind:disabled="!deactivateConfirmed">Nonaktifkan akun</x-button>
                                </form>
                            </template>
                            <x-button variant="secondary" x-on:click="$dispatch('close-modal', 'application-detail')">Tutup</x-button>
                        </div>
                    </template>

                    <template x-if="activeApp.status !== 'pending' && activeApp.status !== 'approved'">
                        <div class="flex flex-col gap-4">
                            <div class="rounded-card border border-danger/30 bg-danger-soft/50 p-4 text-sm">
                                <p class="font-semibold text-danger">Alasan untuk pendaftar</p>
                                <p class="mt-1 text-ink" x-text="activeApp.reviewNote"></p>
                            </div>
                            <x-button variant="secondary" x-on:click="$dispatch('close-modal', 'application-detail')">Tutup</x-button>
                        </div>
                    </template>
                </div>
            </template>
        </x-modal>

        <x-modal name="application-decline" title="Tindak lanjut pengajuan" width="max-w-[480px]">
            <template x-if="activeApp">
                <form method="POST" :action="declineMode === 'revise' ? activeApp.reviseUrl : activeApp.rejectUrl" class="flex flex-col gap-4">
                    @csrf
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-start gap-2 rounded-control border p-3 text-xs transition"
                               :class="declineMode === 'reject' ? 'border-danger bg-danger-soft/40 text-ink' : 'border-border bg-surface text-muted'">
                            <input type="radio" x-model="declineMode" value="reject" class="mt-0.5 accent-danger">
                            <span><strong class="block text-ink">Tolak permanen</strong>Pendaftar ditolak karena tidak memenuhi syarat.</span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-2 rounded-control border p-3 text-xs transition"
                               :class="declineMode === 'revise' ? 'border-primary bg-primary-soft/40 text-ink' : 'border-border bg-surface text-muted'">
                            <input type="radio" x-model="declineMode" value="revise" class="mt-0.5 accent-primary">
                            <span><strong class="block text-ink">Minta perbaikan</strong>Pendaftar diminta memperbaiki data dan ajukan ulang.</span>
                        </label>
                    </div>

                    <x-field label="Alasan untuk pendaftar" name="reason" for="decline-reason" :required="true" hint="Alasan ini dapat dilihat langsung oleh pendaftar.">
                        <x-textarea name="reason" id="decline-reason" rows="4" placeholder="Data kelas belum sesuai. Periksa kelas yang diwakili dan ajukan kembali." required />
                    </x-field>

                    <div class="flex flex-wrap justify-end gap-3">
                        <x-button variant="secondary" x-on:click="$dispatch('close-modal', 'application-decline')">Batal</x-button>
                        <template x-if="declineMode === 'revise'">
                            <x-button type="submit" variant="primary" icon="rotate-ccw">Kirim permintaan perbaikan</x-button>
                        </template>
                        <template x-if="declineMode === 'reject'">
                            <x-button type="submit" variant="danger" icon="circle-alert">Kirim penolakan</x-button>
                        </template>
                    </div>
                </form>
            </template>
        </x-modal>
    </div>
@endpush
