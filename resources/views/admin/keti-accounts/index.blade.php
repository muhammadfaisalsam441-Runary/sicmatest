@use('App\Enums\KetiApplicationStatus')
@use('App\Support\Wita')
@extends('layouts.admin')

@section('title', 'Akun Keti')
@section('breadcrumb', 'Akun Keti|'.match ($tab) {
    'pending' => 'Menunggu',
    'approved' => 'Aktif',
    'inactive' => 'Nonaktif',
    'rejected' => 'Ditolak',
    default => $tab,
})

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.dashboard')">Dashboard</x-button>
@endsection

@section('content')
    @php
        $maxPerClass = (int) ($maxKetiPerClass ?? 2);

        $summary = [
            'pending' => [$counts['pending'].' pengajuan menunggu pemeriksaan', 'Periksa status verifikasi email dan kecocokan kelas sebelum menyetujui.'],
            'approved' => [$counts['approved'].' akun Keti aktif', 'Akses Keti aktif diberikan untuk kelas yang sudah diverifikasi. Maksimal '.$maxPerClass.' Keti aktif per kelas.'],
            'inactive' => [$counts['inactive'].' akun Keti dinonaktifkan', 'Akun nonaktif dapat diaktifkan kembali jika kuota kelas masih tersedia.'],
            'rejected' => [$counts['rejected'].' pengajuan ditolak atau perlu diperbaiki', 'Alasan penolakan dapat dibaca dan pengajuan dapat disetujui langsung oleh admin jika memenuhi syarat.'],
        ][$tab];

        $unverifiedPendingCount = $tab === 'pending'
            ? $applications->getCollection()->filter(fn ($app): bool => ! $app->user->hasVerifiedEmail())->count()
            : 0;

        $approvablePageIds = $tab === 'pending'
            ? $applications->getCollection()->filter(fn ($app): bool => $app->user->hasVerifiedEmail())->pluck('id')->values()->all()
            : [];
    @endphp

    <div class="flex flex-col gap-4 admin-page-stack"
         x-data="{
             selectedIds: [],
             pageIds: @js($approvablePageIds),
             activeApp: null,
             declineMode: 'reject',
             deactivateConfirmed: false,
             isAllSelected() {
                 const curr = this.selectedIds.map(Number);
                 return this.pageIds.length > 0 && this.pageIds.every(id => curr.includes(Number(id)));
             },
             toggleSelectAll(checked) {
                 const page = this.pageIds.map(Number);
                 const curr = this.selectedIds.map(Number);
                 this.selectedIds = checked ? [...new Set([...curr, ...page])] : curr.filter(id => !page.includes(id));
             },
             openDetail(payload) {
                 this.activeApp = payload;
                 this.deactivateConfirmed = false;
                 this.$dispatch('open-modal', 'application-detail');
                 this.$nextTick(() => window.renderIcons?.());
             },
             openDecline() {
                 this.declineMode = 'reject';
                 this.$dispatch('close-modal', 'application-detail');
                 this.$dispatch('open-modal', 'application-decline');
                 this.$nextTick(() => window.renderIcons?.());
             },
         }"
         :data-editing="selectedIds.length > 0 ? 'true' : null"
         @keti-open-detail.window="openDetail($event.detail)">
        <x-page-header title="Akun Keti" />

        <x-card padding="p-4" class="admin-table-card flex flex-col gap-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-1.5">
                    @foreach ([
                        ['key' => 'pending', 'label' => 'Menunggu persetujuan', 'count' => $counts['pending']],
                        ['key' => 'approved', 'label' => 'Aktif', 'count' => $counts['approved']],
                        ['key' => 'inactive', 'label' => 'Nonaktif', 'count' => $counts['inactive']],
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
                <select name="per_page" aria-label="Baris per halaman" @change="$el.form.requestSubmit()" class="rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-2xs">
                    @foreach ([25, 50, 100] as $n)
                        <option value="{{ $n }}" @selected(($perPage ?? 25) == $n)>{{ $n }} baris</option>
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

            @if ($unverifiedPendingCount > 0)
                <x-info-box variant="warning" title="{{ $unverifiedPendingCount }} pengajuan belum memverifikasi email">
                    <p>Pendaftar wajib memasukkan kode verifikasi 6 digit dari emailnya terlebih dahulu sebelum dapat disetujui.</p>
                </x-info-box>
            @endif

            @if ($applications->isEmpty())
                <x-empty-state :title="$keyword !== '' || $classGroupFilter !== null ? 'Tidak ada pengajuan yang cocok' : ($tab === 'pending' ? 'Tidak ada pengajuan baru' : ($tab === 'inactive' ? 'Tidak ada akun nonaktif' : 'Belum ada data'))"
                               message="{{ $keyword !== '' || $classGroupFilter !== null ? 'Coba kata kunci atau kelas lain.' : ($tab === 'inactive' ? 'Akun Keti yang dinonaktifkan akan muncul di sini.' : 'Pengajuan Keti yang sudah memverifikasi email akan muncul di sini.') }}"
                               icon="users">
                    @if ($keyword !== '' || $classGroupFilter !== null)
                        <x-button variant="soft" :href="route('admin.keti-accounts.index', ['status' => $tab])">Hapus filter</x-button>
                    @endif
                </x-empty-state>
            @else
                <x-table-scroll>
                    <x-slot:title>{{ $applications->total() }} akun ditampilkan</x-slot:title>
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
                                <th scope="col" class="px-4 py-3 font-medium whitespace-nowrap">Nama / NIM</th>
                                <th scope="col" class="px-4 py-3 font-medium whitespace-nowrap">Kelas</th>
                                <th scope="col" class="px-4 py-3 font-medium whitespace-nowrap">Email</th>
                                <th scope="col" class="px-4 py-3 font-medium whitespace-nowrap">Status</th>
                                <th scope="col" class="px-4 py-3 text-right font-medium whitespace-nowrap">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($applications as $application)
                                @php
                                    $applicant = $application->user;
                                    $emailVerified = $applicant->hasVerifiedEmail();
                                    $unverifiedPending = $application->status === KetiApplicationStatus::Pending && ! $emailVerified;
                                    $inactive = $application->status === KetiApplicationStatus::Approved && ! $applicant->is_active;
                                    $classActiveCount = (int) ($classActiveKetiCounts[$application->class_group_id] ?? 0);
                                    $classQuotaFull = $classActiveCount >= $maxPerClass;
                                    $appPayload = [
                                        'id' => $application->id,
                                        'name' => $applicant->name,
                                        'nim' => $applicant->nim ?? '—',
                                        'email' => $applicant->email,
                                        'emailVerified' => $emailVerified,
                                        'classGroupName' => $application->classGroup->name,
                                        'classGroupId' => (string) $application->class_group_id,
                                        'classActiveCount' => $classActiveCount,
                                        'maxPerClass' => $maxPerClass,
                                        'classQuotaFull' => $classQuotaFull,
                                        'createdStamp' => Wita::stamp($application->created_at),
                                        'status' => $application->status->value,
                                        'isActive' => (bool) $applicant->is_active,
                                        'reviewerName' => $application->reviewer?->name,
                                        'reviewedStamp' => $application->reviewed_at ? Wita::stamp($application->reviewed_at) : null,
                                        'reviewNote' => $application->review_note ?? '—',
                                        'approveUrl' => route('admin.keti-accounts.approve', $application),
                                        'deactivateUrl' => route('admin.keti-accounts.deactivate', $applicant),
                                        'reactivateUrl' => route('admin.keti-accounts.reactivate', $applicant),
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
                                                   @disabled(! $emailVerified)
                                                   title="{{ $emailVerified ? 'Pilih '.$applicant->name : 'Tidak dapat disetujui sebelum email terverifikasi' }}"
                                                   aria-label="Pilih {{ $applicant->name }}"
                                                   class="size-4 rounded border-border text-primary focus:ring-primary cursor-pointer disabled:cursor-not-allowed disabled:opacity-40">
                                        </td>
                                    @endif
                                    <td class="px-4 py-3">
                                        <span class="block font-semibold">{{ $applicant->name }}</span>
                                        <span class="text-xs text-muted">{{ $applicant->nim ?? '—' }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <span>{{ $application->classGroup->name }}</span>
                                            <span @class([
                                                'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium whitespace-nowrap',
                                                'bg-warning-soft text-warning-ink' => $classQuotaFull,
                                                'bg-page text-muted ring-1 ring-border' => ! $classQuotaFull,
                                            ])>{{ $classQuotaFull ? "{$classActiveCount}/{$maxPerClass} penuh" : "{$classActiveCount}/{$maxPerClass} Keti" }}</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="block text-muted">{{ $applicant->email }}</span>
                                        @if ($emailVerified)
                                            <span class="mt-0.5 inline-flex items-center gap-1 text-[11px] font-medium whitespace-nowrap text-success">
                                                <i data-lucide="circle-check" class="size-3 shrink-0" aria-hidden="true"></i>
                                                <span>Email terverifikasi</span>
                                            </span>
                                        @else
                                            <span class="mt-0.5 inline-flex items-center gap-1 text-[11px] font-semibold whitespace-nowrap text-warning-ink">
                                                <i data-lucide="triangle-alert" class="size-3 shrink-0 text-warning" aria-hidden="true"></i>
                                                <span>Belum verifikasi email</span>
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <x-status-badge :variant="match (true) {
                                            $inactive => 'neutral',
                                            $unverifiedPending => 'warning',
                                            $application->status === KetiApplicationStatus::Approved => 'success',
                                            $application->status === KetiApplicationStatus::Pending => 'primary',
                                            $application->status === KetiApplicationStatus::NeedsRevision => 'warning',
                                            default => 'danger',
                                        }">{{ $inactive ? 'Nonaktif' : ($unverifiedPending ? 'Belum verifikasi' : match ($application->status) {
                                            KetiApplicationStatus::Pending => 'Menunggu',
                                            KetiApplicationStatus::Approved => 'Aktif',
                                            default => $application->status->label(),
                                        }) }}</x-status-badge>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="inline-flex items-center justify-end gap-2">
                                            @if ($tab === 'pending')
                                                <form method="POST" action="{{ route('admin.keti-accounts.approve', $application) }}" class="inline-flex">
                                                    @csrf
                                                    <input type="hidden" name="class_group_id" value="{{ $application->class_group_id }}">
                                                    <button type="submit"
                                                            @disabled(! $emailVerified)
                                                            title="{{ $emailVerified ? 'Setujui '.$applicant->name : 'Menunggu pendaftar memverifikasi email' }}"
                                                            aria-label="Setujui {{ $applicant->name }}"
                                                            class="inline-flex cursor-pointer items-center justify-center gap-1.5 rounded-control bg-success px-3 py-2 text-xs font-semibold text-white shadow-2xs transition hover:bg-success/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50">
                                                        <i data-lucide="user-check" class="size-3.5" aria-hidden="true"></i>
                                                        <span>Setujui</span>
                                                    </button>
                                                </form>
                                            @endif
                                            <x-button size="sm"
                                                      :variant="$tab === 'pending' ? 'primary' : 'soft'"
                                                      aria-label="{{ ($tab === 'pending' ? 'Periksa' : 'Detail').' '.$applicant->name }}"
                                                      x-on:click="openDetail({{ \Illuminate\Support\Js::from($appPayload) }})">
                                                {{ $tab === 'pending' ? 'Periksa' : 'Detail' }}
                                            </x-button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-table-scroll>
                @if ($applications->hasPages())
                    <div class="border-t border-border pt-3">
                        {{ $applications->links() }}
                    </div>
                @endif
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
                 class="fixed bottom-[calc(1rem+env(safe-area-inset-bottom,0px))] left-1/2 z-40 flex max-h-[45dvh] w-max max-w-[calc(100vw-2rem)] -translate-x-1/2 flex-wrap items-center gap-3 overflow-y-auto rounded-xl border border-primary/30 bg-ink px-4 py-3 text-surface shadow-2xl sm:px-6 sm:py-3.5">
                <div class="flex items-center gap-2">
                    <i data-lucide="circle-check" class="size-5 shrink-0 text-success" aria-hidden="true"></i>
                    <span class="text-sm font-semibold"><span x-text="selectedIds.length"></span> pengajuan dipilih</span>
                </div>
                <div class="hidden h-4 w-px bg-surface/20 sm:block" aria-hidden="true"></div>
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
                <button type="button" @click="selectedIds = []" class="cursor-pointer text-xs text-surface/70 transition hover:text-white sm:ml-1">
                    Batalkan Pilihan
                </button>
            </div>
        @endif

        <x-modal name="application-detail" aria-label="Detail pengajuan akun Keti" width="max-w-[480px]">
            <template x-if="activeApp">
                <div class="flex flex-col gap-4">
                    <div class="flex items-start justify-between gap-4">
                        <h2 class="text-xl font-semibold text-ink" x-text="activeApp.status === 'pending' ? activeApp.name : (activeApp.status === 'approved' ? (activeApp.isActive ? 'Akun Keti aktif' : 'Akun Keti nonaktif') : (activeApp.status === 'needs_revision' ? 'Pengajuan perlu diperbaiki' : 'Pengajuan ditolak'))"></h2>
                        <button type="button" x-on:click="$dispatch('close-modal', 'application-detail')" aria-label="Tutup dialog" class="inline-flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-control text-muted transition hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                            <i data-lucide="x" class="size-5" aria-hidden="true"></i>
                        </button>
                    </div>

                    <div class="flex flex-col gap-1 text-sm">
                        <span x-show="activeApp.status !== 'pending'" class="text-base font-semibold text-ink" x-text="activeApp.name"></span>
                        <span class="text-muted">NIM <span x-text="activeApp.nim"></span> • <span x-text="activeApp.email"></span></span>
                        <span class="text-muted">Kelas yang diajukan: <span class="font-medium text-ink" x-text="activeApp.classGroupName"></span></span>
                        <span class="text-muted">Kuota kelas saat ini: <span class="font-medium text-ink" x-text="`${activeApp.classActiveCount}/${activeApp.maxPerClass} Keti aktif`"></span></span>
                        <span class="text-xs text-muted">Diajukan <span x-text="activeApp.createdStamp"></span></span>
                    </div>

                    <template x-if="activeApp.status === 'pending'">
                        <div class="flex flex-col gap-4">
                            <template x-if="!activeApp.emailVerified">
                                <x-info-box variant="warning" title="Email pendaftar belum terverifikasi">
                                    <p>Pendaftar belum memasukkan kode verifikasi 6 digit dari emailnya. Akun belum dapat disetujui sampai email diverifikasi.</p>
                                </x-info-box>
                            </template>
                            <template x-if="activeApp.emailVerified">
                                <x-info-box variant="success" title="Email sudah terverifikasi">
                                    <p>Cocokkan identitas dan kelas dengan data akademik sebelum menyetujui.</p>
                                </x-info-box>
                            </template>
                            <form method="POST" :action="activeApp.approveUrl" class="flex flex-col gap-4">
                                @csrf
                                <x-field label="Kelas yang ditetapkan" name="class_group_id" hint="Persetujuan mengaktifkan akses Keti untuk kelas ini.">
                                    <x-select name="class_group_id" :options="$classGroups" x-model="activeApp.classGroupId" />
                                </x-field>
                                <x-button type="submit" icon="check" x-bind:disabled="!activeApp.emailVerified">Setujui dan aktifkan akun</x-button>
                            </form>
                            <div class="flex flex-col gap-3 sm:flex-row">
                                <x-button variant="danger-outline" class="w-full sm:flex-1" x-on:click="openDecline()">Tolak / Minta Perbaikan</x-button>
                                <x-button variant="secondary" class="w-full sm:flex-1" x-on:click="$dispatch('close-modal', 'application-detail')">Batal</x-button>
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
                            <template x-if="!activeApp.isActive">
                                <div class="flex flex-col gap-3">
                                    <template x-if="activeApp.classQuotaFull">
                                        <x-info-box variant="warning" title="Kuota kelas sudah penuh">
                                            <p><span x-text="`${activeApp.classActiveCount}/${activeApp.maxPerClass} Keti aktif.`"></span> Nonaktifkan salah satu Keti aktif kelas ini terlebih dahulu sebelum mengaktifkan kembali akun ini.</p>
                                        </x-info-box>
                                    </template>
                                    <form method="POST" :action="activeApp.reactivateUrl" class="flex flex-col gap-3">
                                        @csrf
                                        <x-button type="submit" icon="user-check" x-bind:disabled="activeApp.classQuotaFull">Aktifkan kembali</x-button>
                                    </form>
                                </div>
                            </template>
                            <x-button variant="secondary" x-on:click="$dispatch('close-modal', 'application-detail')">Tutup</x-button>
                        </div>
                    </template>

                    <template x-if="activeApp.status === 'rejected' || activeApp.status === 'needs_revision'">
                        <div class="flex flex-col gap-4">
                            <div class="rounded-card border border-danger/30 bg-danger-soft/50 p-4 text-sm">
                                <p class="font-semibold text-danger">Alasan untuk pendaftar</p>
                                <p class="mt-1 text-ink" x-text="activeApp.reviewNote"></p>
                                <p x-show="activeApp.reviewerName" class="mt-1 text-xs text-muted">Ditinjau <span x-text="activeApp.reviewerName"></span> • <span x-text="activeApp.reviewedStamp"></span></p>
                            </div>
                            <template x-if="!activeApp.emailVerified">
                                <x-info-box variant="warning" title="Email pendaftar belum terverifikasi">
                                    <p>Pendaftar belum memasukkan kode verifikasi 6 digit dari emailnya. Akun belum dapat disetujui sampai email diverifikasi.</p>
                                </x-info-box>
                            </template>
                            <form method="POST" :action="activeApp.approveUrl" class="flex flex-col gap-4">
                                @csrf
                                <x-field label="Kelas yang ditetapkan" name="class_group_id" for="reapprove-class-group-id" hint="Admin dapat menyetujui dan mengaktifkan akun ini secara langsung untuk kelas yang dipilih.">
                                    <x-select id="reapprove-class-group-id" name="class_group_id" :options="$classGroups" x-model="activeApp.classGroupId" />
                                </x-field>
                                <x-button type="submit" icon="check" x-bind:disabled="!activeApp.emailVerified">Setujui dan aktifkan akun</x-button>
                            </form>
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
                            <input type="radio" x-model="declineMode" value="reject" @change="$nextTick(() => window.renderIcons?.())" class="mt-0.5 accent-danger">
                            <span><strong class="block text-ink">Tolak permanen</strong>Pendaftar ditolak karena tidak memenuhi syarat.</span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-2 rounded-control border p-3 text-xs transition"
                               :class="declineMode === 'revise' ? 'border-primary bg-primary-soft/40 text-ink' : 'border-border bg-surface text-muted'">
                            <input type="radio" x-model="declineMode" value="revise" @change="$nextTick(() => window.renderIcons?.())" class="mt-0.5 accent-primary">
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
@endsection
