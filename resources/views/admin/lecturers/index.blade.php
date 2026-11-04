@use('App\Enums\LecturerHomebase')
@use('App\Enums\LecturerProfileStatus')
@use('App\Http\Controllers\Admin\LecturerController')
@extends('layouts.admin')

@section('title', 'Data Dosen')
@section('breadcrumb', 'Data Dosen')

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.dashboard')">Dashboard</x-button>
@endsection

@section('content')
    <div class="flex flex-col gap-6"
         x-data="lecturerManager(@js($lecturers->pluck('id')->all()))"
         @sicma:navigated.document="formModalOpen = false; batchTargetModalOpen = false; importModalOpen = false">
        <x-page-header title="Data dosen dari jadwal" subtitle="Kelola profil dosen, status verifikasi, penugasan pimpinan, serta target beban SKS untuk penjadwalan.">
            <x-slot:actions>
                <x-button variant="secondary" icon="upload" @click="importModalOpen = true">Impor spreadsheet</x-button>
                <x-button icon="plus" @click="openCreateModal()">Tambah dosen</x-button>
            </x-slot:actions>
        </x-page-header>

        <x-card>
            <x-stepper :steps="['Data', 'Tim mengajar', 'Draf jadwal', 'Periksa', 'Tetapkan']" :current="1" />
        </x-card>

        {{-- Ringkasan Metrik Cepat --}}
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <x-card class="flex flex-col gap-1 p-4">
                <span class="text-xs font-medium text-muted">Total Dosen Terdaftar</span>
                <span class="text-2xl font-bold text-ink">{{ number_format($total) }}</span>
                <span class="text-xs text-muted">Dari jadwal & impor</span>
            </x-card>
            <a href="{{ route('admin.lecturers.index', ['filter' => 'verifikasi']) }}"
               @class([
                   'rounded-card border p-4 transition flex flex-col gap-1',
                   'border-danger/40 bg-danger-soft/20 hover:bg-danger-soft/30' => $unverified > 0,
                   'border-border bg-surface hover:bg-page' => $unverified === 0,
               ])>
                <span class="text-xs font-medium text-danger">Perlu Verifikasi</span>
                <span class="text-2xl font-bold text-danger">{{ number_format($unverified) }}</span>
                <span class="text-xs text-danger/80">Belum bisa dipilih sebagai pengajar</span>
            </a>
            <x-card class="flex flex-col gap-1 p-4">
                <span class="text-xs font-medium text-muted">Homebase JTIK</span>
                <span class="text-2xl font-bold text-ink">{{ number_format($jtikCount) }}</span>
                <span class="text-xs text-muted">Dosen internal</span>
            </x-card>
            <x-card class="flex flex-col gap-1 p-4">
                <span class="text-xs font-medium text-muted">Pimpinan Jurusan</span>
                <span class="text-2xl font-bold text-ink">{{ number_format($leaderCount) }}</span>
                <span class="text-xs text-muted">Khusus: Bebas jadwal Kamis</span>
            </x-card>
        </div>

        {{-- Toolbar Filter & Pencarian Cepat --}}
        <x-card class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-border pb-4">
                {{-- Tabs Filter Status dengan Angka Real-time --}}
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('admin.lecturers.index', array_filter(['filter' => null, 'q' => $keyword, 'per_page' => $perPage])) }}"
                       @class([
                           'rounded-full px-3.5 py-1.5 text-xs font-semibold transition',
                           'bg-primary text-white' => $filter === 'semua',
                           'bg-page text-muted hover:text-ink hover:bg-border' => $filter !== 'semua',
                       ])>
                        Semua ({{ $total }})
                    </a>
                    <a href="{{ route('admin.lecturers.index', array_filter(['filter' => 'verifikasi', 'q' => $keyword, 'per_page' => $perPage])) }}"
                       @class([
                           'rounded-full px-3.5 py-1.5 text-xs font-semibold transition',
                           'bg-danger text-white' => $filter === 'verifikasi',
                           'bg-danger-soft/60 text-danger hover:bg-danger-soft' => $filter !== 'verifikasi',
                       ])>
                        ⚠️ Perlu Verifikasi ({{ $unverified }})
                    </a>
                    <a href="{{ route('admin.lecturers.index', array_filter(['filter' => 'jtik', 'q' => $keyword, 'per_page' => $perPage])) }}"
                       @class([
                           'rounded-full px-3.5 py-1.5 text-xs font-semibold transition',
                           'bg-primary text-white' => $filter === 'jtik',
                           'bg-page text-muted hover:text-ink hover:bg-border' => $filter !== 'jtik',
                       ])>
                        Homebase JTIK ({{ $jtikCount }})
                    </a>
                    <a href="{{ route('admin.lecturers.index', array_filter(['filter' => 'luar', 'q' => $keyword, 'per_page' => $perPage])) }}"
                       @class([
                           'rounded-full px-3.5 py-1.5 text-xs font-semibold transition',
                           'bg-primary text-white' => $filter === 'luar',
                           'bg-page text-muted hover:text-ink hover:bg-border' => $filter !== 'luar',
                       ])>
                        Luar JTIK ({{ $externalCount }})
                    </a>
                    <a href="{{ route('admin.lecturers.index', array_filter(['filter' => 'pimpinan', 'q' => $keyword, 'per_page' => $perPage])) }}"
                       @class([
                           'rounded-full px-3.5 py-1.5 text-xs font-semibold transition',
                           'bg-primary text-white' => $filter === 'pimpinan',
                           'bg-page text-muted hover:text-ink hover:bg-border' => $filter !== 'pimpinan',
                       ])>
                        Pimpinan ({{ $leaderCount }})
                    </a>
                </div>
            </div>

            {{-- Form Pencarian & Pilihan Jumlah Baris --}}
            <form method="GET" action="{{ route('admin.lecturers.index') }}" class="grid grid-cols-1 items-end gap-3 sm:grid-cols-12">
                @if ($filter !== 'semua')
                    <input type="hidden" name="filter" value="{{ $filter }}">
                @endif

                <div class="sm:col-span-8">
                    <label class="mb-1 block text-xs font-medium text-muted">Cari Nama Dosen / NIDN</label>
                    <div class="relative">
                        <i data-lucide="search" class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted"></i>
                        <input type="text" name="q" value="{{ $keyword }}" placeholder="Ketik nama lengkap dosen, gelar, atau nomor NIDN..."
                               class="w-full rounded-control border border-border bg-surface pl-9 pr-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    </div>
                </div>

                <div class="sm:col-span-3">
                    <label class="mb-1 block text-xs font-medium text-muted">Tampilkan Per Halaman</label>
                    <select name="per_page" @change="$el.form.requestSubmit()" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        @foreach ([25, 50, 100] as $n)
                            <option value="{{ $n }}" @selected($perPage == $n)>{{ $n }} baris</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex gap-2 sm:col-span-1">
                    <button type="submit" class="flex h-[38px] w-full items-center justify-center rounded-control bg-primary text-white hover:bg-primary/90" title="Terapkan Filter">
                        <i data-lucide="arrow-right" class="size-4"></i>
                    </button>
                    @if ($keyword !== '' || $filter !== 'semua')
                        <a href="{{ route('admin.lecturers.index') }}" class="flex h-[38px] w-[38px] shrink-0 items-center justify-center rounded-control border border-border text-muted hover:text-ink" title="Reset Pencarian">
                            <i data-lucide="rotate-ccw" class="size-4"></i>
                        </a>
                    @endif
                </div>
            </form>
        </x-card>

        {{-- TABEL DATA DOSEN --}}
        <div class="overflow-hidden rounded-card border border-border bg-surface shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border bg-page text-xs font-medium text-muted">
                        <tr>
                            <th class="w-10 px-4 py-3 text-center">
                                <input type="checkbox"
                                       @change="toggleSelectAll($event.target.checked)"
                                       :checked="isAllSelected()"
                                       class="size-4 rounded border-border text-primary focus:ring-primary cursor-pointer"
                                       title="Pilih semua di halaman ini">
                            </th>
                            <th class="px-4 py-3 font-semibold">Nama Dosen & NIDN</th>
                            <th class="px-3 py-3 text-center font-semibold">Jumlah MK</th>
                            <th class="px-4 py-3 font-semibold">Homebase</th>
                            <th class="px-4 py-3 font-semibold">Target / Maks SKS</th>
                            <th class="px-4 py-3 font-semibold">Status Profil</th>
                            <th class="px-4 py-3 text-right font-semibold">Aksi Cepat</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($lecturers as $lecturer)
                            @php
                                $term = $lecturer->leadershipTerms->first(fn ($term) => $term->isActiveOn(now()));
                                $target = $lecturer->loadTargets->first();
                                $isVerified = $lecturer->profile_status === LecturerProfileStatus::Verified;
                                $selection = $courseSelections[$lecturer->id];
                                $editData = [
                                    'id' => $lecturer->id,
                                    'updateUrl' => route('admin.lecturers.update', $lecturer),
                                    'name' => $lecturer->name,
                                    'nidn' => $lecturer->nidn,
                                    'homebase' => $lecturer->homebase?->value,
                                    'profile_status' => $lecturer->profile_status->value,
                                    'leadership_position' => $term?->position,
                                    'leadership_from' => $term?->valid_from?->toDateString(),
                                    'leadership_to' => $term?->valid_to?->toDateString(),
                                    'target_sks' => $target?->target_sks,
                                    'max_sks' => $target?->max_sks,
                                    'course_ids' => array_map('strval', $selection['manual']),
                                    'source_course_ids' => array_map('strval', $selection['source']),
                                ];
                            @endphp

                            <tr class="transition hover:bg-primary-soft/20" :class="{ 'bg-primary-soft/30': selectedIds.includes({{ $lecturer->id }}) }">
                                {{-- Checkbox --}}
                                <td class="px-4 py-3 text-center">
                                    <input type="checkbox"
                                           value="{{ $lecturer->id }}"
                                           x-model.number="selectedIds"
                                           class="size-4 rounded border-border text-primary focus:ring-primary cursor-pointer">
                                </td>

                                {{-- Nama Dosen & NIDN --}}
                                <td class="px-4 py-3">
                                    <div class="flex flex-col">
                                        <div class="flex items-center gap-2">
                                            <span class="font-semibold text-ink">{{ $lecturer->name }}</span>
                                            @if ($term)
                                                <span class="inline-flex items-center gap-1 rounded bg-danger-soft px-1.5 py-0.5 text-[10px] font-semibold text-danger">
                                                    <i data-lucide="shield-check" class="size-3"></i>
                                                    {{ $term->position }} • tidak dijadwalkan Kamis
                                                </span>
                                            @endif
                                        </div>
                                        <div class="mt-0.5 flex items-center gap-2 text-xs text-muted">
                                            @if ($lecturer->nidn)
                                                <span class="font-mono text-[11px] text-ink/80">NIDN: {{ $lecturer->nidn }}</span>
                                            @else
                                                <span class="text-danger text-[11px]">NIDN belum diisi</span>
                                            @endif

                                            @if ($lecturer->source_name && $lecturer->source_name !== $lecturer->name)
                                                <span>• Sumber: <em>{{ $lecturer->source_name }}</em></span>
                                            @endif
                                        </div>
                                    </div>
                                </td>

                                {{-- Beban MK --}}
                                <td class="px-3 py-3 text-center">
                                    <span class="inline-flex items-center rounded-md bg-page px-2.5 py-0.5 text-xs font-medium text-ink ring-1 ring-border">
                                        {{ $selection['count'] }} MK
                                    </span>
                                </td>

                                {{-- Homebase --}}
                                <td class="px-4 py-3">
                                    @if ($lecturer->homebase === LecturerHomebase::Jtik)
                                        <span class="inline-flex items-center gap-1 text-xs font-semibold text-primary">
                                            <i data-lucide="building-2" class="size-3.5"></i> JTIK
                                        </span>
                                    @elseif ($lecturer->homebase === LecturerHomebase::External)
                                        <span class="inline-flex items-center gap-1 text-xs font-medium text-muted">
                                            <i data-lucide="globe" class="size-3.5"></i> Luar JTIK
                                        </span>
                                    @else
                                        <span class="text-xs text-muted/70 italic">Belum diatur</span>
                                    @endif
                                </td>

                                {{-- Target / Maks SKS --}}
                                <td class="px-4 py-3 text-xs">
                                    @if ($target && ($target->target_sks !== null || $target->max_sks !== null))
                                        <span class="font-semibold text-ink">{{ (float) ($target->target_sks ?? 0) }} SKS</span>
                                        <span class="text-muted">/ maks {{ (float) ($target->max_sks ?? 16) }} SKS</span>
                                    @else
                                        <span class="text-muted/70 italic">Belum ditetapkan</span>
                                    @endif
                                </td>

                                {{-- Status Profil --}}
                                <td class="px-4 py-3">
                                    <x-status-badge :variant="$isVerified ? 'success' : 'warning'">
                                        {{ $lecturer->profile_status->label() }}
                                    </x-status-badge>
                                </td>

                                {{-- Aksi Cepat --}}
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        {{-- Tombol Verifikasi Cepat (Jika belum verified) --}}
                                        @unless ($isVerified)
                                            <form method="POST" action="{{ route('admin.lecturers.update', $lecturer) }}" class="inline">
                                                @csrf
                                                @method('PUT')
                                                <input type="hidden" name="name" value="{{ $lecturer->name }}">
                                                <input type="hidden" name="nidn" value="{{ $lecturer->nidn }}">
                                                <input type="hidden" name="homebase" value="{{ $lecturer->homebase?->value }}">
                                                <input type="hidden" name="profile_status" value="verified">
                                                <button type="submit" class="inline-flex items-center gap-1 rounded-md bg-success-soft px-2 py-1 text-xs font-semibold text-success hover:bg-success hover:text-white transition" title="Verifikasi dosen ini sekarang">
                                                    <i data-lucide="check" class="size-3.5"></i>
                                                    <span>Verifikasi</span>
                                                </button>
                                            </form>
                                        @endunless

                                        <button type="button" @click="openEditModal(@js($editData))"
                                                class="inline-flex items-center gap-1 rounded-control border border-border px-3 py-1.5 text-xs font-medium text-primary hover:bg-primary-soft">
                                            <i data-lucide="edit-3" class="size-4"></i>
                                            Edit
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-12 text-center text-muted">
                                    <i data-lucide="user-x" class="mx-auto size-8 text-muted/60 mb-2"></i>
                                    <p class="font-medium text-ink">Tidak ada data dosen yang sesuai dengan filter.</p>
                                    <p class="text-xs text-muted mt-1">Coba gunakan kata kunci pencarian lain.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination & Data Summary Footer --}}
            @if ($lecturers->total() > 0)
                <div class="border-t border-border bg-page/50 px-4 py-3">
                    @if ($lecturers->hasPages())
                        {{ $lecturers->links() }}
                    @else
                        <div class="flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-muted py-1">
                            <div>
                                Menampilkan <span class="font-semibold text-ink">1</span> sampai <span class="font-semibold text-ink">{{ $lecturers->total() }}</span> dari <span class="font-semibold text-ink">{{ number_format($lecturers->total()) }}</span> data dosen
                            </div>
                            <span class="inline-flex items-center justify-center min-w-[32px] h-7 px-2.5 rounded-control border border-border bg-surface text-ink text-xs font-semibold">
                                Halaman 1 dari 1
                            </span>
                        </div>
                    @endif
                </div>
            @endif
        </div>

        {{-- FLOATING BATCH ACTION BAR --}}
        <div x-show="selectedIds.length > 0"
             x-cloak
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-4"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 translate-y-4"
             class="fixed bottom-6 left-1/2 z-40 flex -translate-x-1/2 flex-wrap items-center gap-3 rounded-xl border border-primary/30 bg-ink px-6 py-3.5 text-surface shadow-2xl">
            <div class="flex items-center gap-2">
                <i data-lucide="check-circle" class="size-5 text-success"></i>
                <span class="text-sm font-semibold"><span x-text="selectedIds.length"></span> dosen dipilih</span>
            </div>
            <div class="h-4 w-px bg-surface/20"></div>

            {{-- 1. Verifikasi Massal --}}
            <button type="button" @click="submitBatch('verify')" class="inline-flex items-center gap-1.5 rounded-lg bg-success px-3 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-success/90 transition">
                <i data-lucide="user-check" class="size-3.5"></i>
                Verifikasi Sekaligus
            </button>

            {{-- 2. Set Homebase JTIK --}}
            <button type="button" @click="submitBatch('homebase_jtik')" class="inline-flex items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface hover:bg-surface/20 transition">
                <i data-lucide="building-2" class="size-3.5"></i>
                Set Homebase JTIK
            </button>

            {{-- 3. Set Homebase Luar JTIK --}}
            <button type="button" @click="submitBatch('homebase_external')" class="inline-flex items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface hover:bg-surface/20 transition">
                <i data-lucide="globe" class="size-3.5"></i>
                Set Luar JTIK
            </button>

            {{-- 4. Set Target SKS Modal --}}
            <button type="button" @click="batchTargetModalOpen = true" class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-primary/90 transition">
                <i data-lucide="target" class="size-3.5"></i>
                Atur Target SKS
            </button>

            <button type="button" @click="selectedIds = []" class="text-xs text-surface/70 hover:text-white transition ml-2">
                Batal
            </button>
        </div>

        {{-- FORM TERSEMBUNYI UNTUK BATCH ACTION STANDARD --}}
        <form x-ref="batchForm" method="POST" action="{{ route('admin.lecturers.batch') }}" class="hidden">
            @csrf
            <input type="hidden" name="action" x-model="batchAction">
            <template x-for="id in selectedIds" :key="id">
                <input type="hidden" name="lecturer_ids[]" :value="id">
            </template>
        </form>

        {{-- MODAL 1: EDIT / CREATE DINAMIS TUNGGAL --}}
        <div x-show="formModalOpen" x-cloak data-modal-name="lecturer-profile" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="formModalOpen = false"></div>
            <div class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-surface p-6 shadow-2xl">
                <div class="flex items-start justify-between border-b border-border pb-3">
                    <div>
                        <span class="text-xs font-semibold text-primary" x-text="formLecturer.id ? 'Perbarui Profil Dosen' : 'Dosen Baru'"></span>
                        <h3 class="text-lg font-bold text-ink" x-text="formLecturer.id ? formLecturer.name : 'Tambah Data Dosen'"></h3>
                    </div>
                    <button type="button" @click="formModalOpen = false" class="text-muted hover:text-ink">
                        <i data-lucide="x" class="size-5"></i>
                    </button>
                </div>

                <form method="POST" action="{{ route('admin.lecturers.store') }}" :action="formLecturer.updateUrl || @js(route('admin.lecturers.store'))" class="mt-4 flex flex-col gap-4">
                    @csrf
                    <template x-if="formLecturer.id">
                        <input type="hidden" name="_method" value="PUT">
                    </template>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-muted">Nama Lengkap & Gelar <span class="text-danger">*</span></label>
                        <input type="text" name="name" x-model="formLecturer.name" required class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-muted">NIDN</label>
                            <input type="text" name="nidn" x-model="formLecturer.nidn" placeholder="Nomor Induk Dosen Nasional" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-muted">Asal / Homebase</label>
                            <select name="homebase" x-model="formLecturer.homebase" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                                <option value="">Belum diverifikasi</option>
                                <option value="jtik">Homebase JTIK</option>
                                <option value="luar">Luar JTIK</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-muted">Status Profil <span class="text-danger">*</span></label>
                        <select name="profile_status" x-model="formLecturer.profile_status" required class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                            <option value="verified">Terverifikasi (Dapat ditugaskan mengajar)</option>
                            <option value="unverified">Perlu verifikasi (Belum dapat mengajar)</option>
                        </select>
                    </div>

                    {{-- Jabatan Pimpinan --}}
                    <div class="rounded-lg border border-border p-3 bg-page/50 flex flex-col gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-ink">Jabatan Pimpinan (Opsional)</label>
                            <input type="text" name="leadership_position" x-model="formLecturer.leadership_position" placeholder="Contoh: Ketua Jurusan / Sekretaris Jurusan" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                            <p class="mt-1 text-[11px] text-muted">Dosen pimpinan otomatis tidak dijadwalkan pada hari Kamis.</p>
                        </div>
                        <div class="grid grid-cols-2 gap-3" x-show="formLecturer.leadership_position">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-muted">Mulai Menjabat</label>
                                <input type="date" name="leadership_from" x-model="formLecturer.leadership_from" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-muted">Akhir Menjabat</label>
                                <input type="date" name="leadership_to" x-model="formLecturer.leadership_to" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                            </div>
                        </div>
                    </div>

                    {{-- Target & Maks SKS --}}
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-muted">Target SKS (Beban)</label>
                            <input type="number" step="0.5" name="target_sks" x-model="formLecturer.target_sks" placeholder="Contoh: 12" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-muted">Maksimum SKS</label>
                            <input type="number" step="0.5" name="max_sks" x-model="formLecturer.max_sks" placeholder="Contoh: 16" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        </div>
                    </div>

                    <fieldset x-show="formLecturer.id" :disabled="!formLecturer.id" class="rounded-control border border-border p-3">
                        <legend class="px-1 text-sm font-semibold text-ink">Mata kuliah yang bisa diampu</legend>
                        <input type="hidden" name="course_selection_present" value="1">
                        <p class="mb-3 text-xs text-muted">Mata kuliah dari spreadsheet dan jadwal semester aktif sudah tercentang. Centang pilihan tambahan agar dosen tersedia di Pembagian Mengajar.</p>
                        <p class="mb-2 text-sm font-medium text-ink"><span x-text="selectedCourseIds.length"></span> MK dipilih</p>
                        <template x-for="id in formLecturer.course_ids" :key="id">
                            <input type="hidden" name="course_ids[]" :value="id">
                        </template>
                        <label for="lecturer-course-search" class="sr-only">Cari mata kuliah</label>
                        <input id="lecturer-course-search" type="search" x-model="courseQuery" placeholder="Cari nama atau kode mata kuliah"
                               class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        <div class="mt-2 max-h-48 overflow-y-auto rounded-control border border-border">
                            @forelse ($courses as $course)
                                <label x-show="@js(mb_strtolower($course->code.' '.$course->name)).includes(courseQuery.trim().toLowerCase())" class="flex items-start gap-2 border-b border-border px-3 py-2 text-sm last:border-b-0">
                                    <input type="checkbox" value="{{ $course->id }}" :checked="selectedCourseIds.includes($el.value)"
                                           :disabled="formLecturer.source_course_ids?.includes($el.value)"
                                           @change="toggleCourse($el.value, $event.target.checked)" class="mt-0.5">
                                    <span>{{ $course->name }} <span class="text-xs text-muted">{{ $course->code }}</span>
                                        <span x-show="formLecturer.source_course_ids?.includes('{{ $course->id }}')" class="text-xs text-muted">· Sudah tercatat</span>
                                    </span>
                                </label>
                            @empty
                                <p class="p-3 text-sm text-muted">Belum ada mata kuliah di katalog.</p>
                            @endforelse
                        </div>
                    </fieldset>

                    <div class="mt-2 flex items-center justify-end gap-2 border-t border-border pt-4">
                        <x-button variant="secondary" type="button" @click="formModalOpen = false">Batal</x-button>
                        <x-button variant="primary" type="submit" icon="check">Simpan Data Dosen</x-button>
                    </div>
                </form>
            </div>
        </div>

        {{-- MODAL 2: BATCH ATUR TARGET SKS --}}
        <div x-show="batchTargetModalOpen" x-cloak data-modal-name="lecturer-batch-target" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="batchTargetModalOpen = false"></div>
            <div class="relative w-full max-w-md rounded-2xl bg-surface p-6 shadow-2xl">
                <div class="flex items-start justify-between border-b border-border pb-3">
                    <div>
                        <span class="text-xs font-semibold text-primary">Penyesuaian Massal</span>
                        <h3 class="text-lg font-bold text-ink">Atur Target SKS (<span x-text="selectedIds.length"></span> Dosen)</h3>
                    </div>
                    <button type="button" @click="batchTargetModalOpen = false" class="text-muted hover:text-ink">
                        <i data-lucide="x" class="size-5"></i>
                    </button>
                </div>

                <form method="POST" action="{{ route('admin.lecturers.batch') }}" class="mt-4 flex flex-col gap-4">
                    @csrf
                    <input type="hidden" name="action" value="set_target_sks">
                    <template x-for="id in selectedIds" :key="id">
                        <input type="hidden" name="lecturer_ids[]" :value="id">
                    </template>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-muted">Target SKS untuk Seluruh Dosen Terpilih</label>
                        <input type="number" step="0.5" name="target_sks" placeholder="Misal: 12" required class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-muted">Batas Maksimum SKS</label>
                        <input type="number" step="0.5" name="max_sks" placeholder="Misal: 16" required class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                    </div>

                    <div class="mt-2 flex items-center justify-end gap-2 border-t border-border pt-4">
                        <x-button variant="secondary" type="button" @click="batchTargetModalOpen = false">Batal</x-button>
                        <x-button variant="primary" type="submit" icon="check">Terapkan ke Dosen Terpilih</x-button>
                    </div>
                </form>
            </div>
        </div>

        {{-- MODAL 3: IMPOR EXCEL / CSV --}}
        <div x-show="importModalOpen" x-cloak data-modal-name="lecturer-import" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="importModalOpen = false"></div>
            <div class="relative w-full max-w-md rounded-2xl bg-surface p-6 shadow-2xl">
                <div class="flex items-start justify-between border-b border-border pb-3">
                    <div>
                        <span class="text-xs font-semibold text-primary">Impor Data</span>
                        <h3 class="text-lg font-bold text-ink">Impor Dosen dari Spreadsheet</h3>
                    </div>
                    <button type="button" @click="importModalOpen = false" class="text-muted hover:text-ink">
                        <i data-lucide="x" class="size-5"></i>
                    </button>
                </div>

                <form method="POST" action="{{ route('admin.lecturers.import') }}" enctype="multipart/form-data" class="mt-4 flex flex-col gap-4">
                    @csrf
                    <p class="text-xs text-muted">
                        Format kolom file (Excel / CSV): <strong class="text-ink">Nama, NIDN, Homebase</strong> (JTIK / Luar JTIK). Dosen dengan NIDN otomatis berstatus terverifikasi.
                    </p>
                    <input type="file" name="file" accept=".xlsx,.xls,.csv" required class="text-sm">

                    <div class="mt-2 flex items-center justify-end gap-2 border-t border-border pt-4">
                        <x-button variant="secondary" type="button" @click="importModalOpen = false">Batal</x-button>
                        <x-button variant="primary" type="submit" icon="upload">Mulai Impor</x-button>
                    </div>
                </form>
            </div>
        </div>
    </div>

@endsection
