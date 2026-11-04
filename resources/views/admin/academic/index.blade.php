@use('App\Http\Controllers\Admin\AcademicController')
@use('App\Support\Wita')
@extends('layouts.admin')

@section('title', 'Data Akademik')
@section('breadcrumb', 'Data Akademik|'.AcademicController::TABS[$tab])

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.dashboard')">Dashboard</x-button>
@endsection

@section('content')
    @php($clock = fn (int $minutes) => sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60))

    <div class="flex flex-col gap-6">
        <x-page-header :title="AcademicController::TABS[$tab]" :subtitle="$period ? 'Semester aktif: '.$period->name.'. Data yang belum diketahui dibiarkan kosong dan ditandai Perlu dilengkapi.' : 'Belum ada semester aktif.'">
            <x-slot:actions>
                @if ($tab === 'kelas')
                    <x-button icon="plus" x-data x-on:click="$dispatch('open-modal', 'class-new')">Tambah kelas</x-button>
                @elseif ($tab === 'mata-kuliah')
                    <x-button icon="plus" x-data x-on:click="$dispatch('open-modal', 'course-new')">Tambah mata kuliah</x-button>
                @elseif ($tab === 'penawaran')
                    <x-button icon="plus" x-data x-on:click="$dispatch('open-modal', 'offering-new')">Tambah penawaran</x-button>
                @endif
            </x-slot:actions>
        </x-page-header>

        <x-tabs :items="collect(AcademicController::TABS)->map(fn ($label, $key) => ['label' => $label, 'href' => route('admin.academic.index', ['tab' => $key]), 'active' => $tab === $key])->values()->all()" />

        @if ($tab === 'semester')
            @if ($period)
                <div class="grid grid-cols-[1fr_420px] gap-6">
                    <x-card class="flex flex-col gap-4">
                        <h2 class="text-lg font-semibold">Periode akademik</h2>
                        <form method="POST" action="{{ route('admin.academic.periods.update', $period) }}" class="flex flex-col gap-4">
                            @csrf
                            @method('PUT')
                            <x-field label="Semester & tahun akademik" name="name"><x-input name="name" :value="$period->name" required /></x-field>
                            <div class="grid grid-cols-2 gap-3">
                                <x-field label="Mulai kuliah" name="start_date"><x-input type="date" name="start_date" :value="$period->start_date->toDateString()" required /></x-field>
                                <x-field label="Akhir kuliah" name="end_date"><x-input type="date" name="end_date" :value="$period->end_date->toDateString()" required /></x-field>
                                <x-field label="Mulai UTS" name="uts_start"><x-input type="date" name="uts_start" :value="$period->uts_start?->toDateString()" /></x-field>
                                <x-field label="Selesai UTS" name="uts_end"><x-input type="date" name="uts_end" :value="$period->uts_end?->toDateString()" /></x-field>
                                <x-field label="Mulai UAS" name="uas_start"><x-input type="date" name="uas_start" :value="$period->uas_start?->toDateString()" /></x-field>
                                <x-field label="Selesai UAS" name="uas_end"><x-input type="date" name="uas_end" :value="$period->uas_end?->toDateString()" /></x-field>
                            </div>
                            <p class="text-xs text-muted">Zona waktu WITA. Tanggal UTS/UAS menentukan sesi "sebelum UTS" dan "setelah UTS". Perubahan berlaku pada draf berikutnya yang ditetapkan.</p>
                            <div class="flex justify-end"><x-button type="submit">Simpan kalender</x-button></div>
                        </form>
                    </x-card>

                    <x-card class="flex flex-col gap-4">
                        <h2 class="text-lg font-semibold">Hari libur & pengecualian tanggal</h2>
                        <form method="POST" action="{{ route('admin.academic.exceptions.store', $period) }}" class="flex flex-col gap-3">
                            @csrf
                            <div class="grid grid-cols-2 gap-3">
                                <x-field label="Tanggal" name="date"><x-input type="date" name="date" required /></x-field>
                                <x-field label="Sampai (opsional)" name="until"><x-input type="date" name="until" /></x-field>
                            </div>
                            <x-field label="Jenis" name="type"><x-select name="type" :options="['holiday' => 'Hari libur', 'no_class' => 'Tidak ada perkuliahan']" /></x-field>
                            <x-field label="Keterangan" name="note"><x-input name="note" placeholder="Contoh: Dies Natalis" /></x-field>
                            <x-button type="submit" variant="soft" icon="plus">Tambah hari libur</x-button>
                        </form>
                        <div class="flex flex-col divide-y divide-border">
                            @forelse ($period->calendarExceptions as $exception)
                                <div class="flex items-center justify-between py-2 text-sm">
                                    <span><span class="font-medium">{{ Wita::dayName($exception->date) }}, {{ Wita::date($exception->date) }}</span> <span class="text-muted">• {{ $exception->note ?? $exception->type->label() }}</span></span>
                                    <form method="POST" action="{{ route('admin.academic.exceptions.destroy', $exception) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-danger hover:underline" aria-label="Hapus {{ Wita::date($exception->date) }}">Hapus</button>
                                    </form>
                                </div>
                            @empty
                                <p class="py-3 text-sm text-muted">Belum ada hari libur.</p>
                            @endforelse
                        </div>
                    </x-card>
                </div>
            @else
                <x-empty-state title="Belum ada semester aktif" message="Tambahkan semester lewat seeder atau hubungi pengelola sistem." icon="calendar" />
            @endif
        @elseif ($tab === 'aturan' && $period)
            <x-card class="flex flex-col gap-4">
                <div class="flex flex-col gap-1">
                    <h2 class="text-lg font-semibold">Hari &amp; jam kuliah (slot)</h2>
                    <p class="text-sm text-muted">Slot adalah acuan pemeriksaan impor dan susun otomatis. Jam pada jadwal tidak dikoreksi otomatis bila berbeda (mis. 15.40 vs 15.45). Nilai slot adalah data contoh, BELUM DITETAPKAN jurusan.</p>
                </div>
                <form method="POST" action="{{ route('admin.academic.slots.update', $period) }}" class="flex flex-col gap-4">
                    @csrf
                    @method('PUT')
                    <div class="grid grid-cols-4 gap-3">
                        @foreach ($period->timeSlots as $slot)
                            <div class="flex items-center gap-2 rounded-control border border-border px-3 py-2">
                                <span class="w-10 text-sm font-semibold">{{ $slot->label }}</span>
                                <input type="time" name="slots[{{ $slot->id }}][start]" value="{{ $clock($slot->start_minute) }}" class="w-full rounded border border-border px-2 py-1 text-sm" aria-label="Mulai slot {{ $slot->label }}">
                                <span class="text-muted">–</span>
                                <input type="time" name="slots[{{ $slot->id }}][end]" value="{{ $clock($slot->end_minute) }}" class="w-full rounded border border-border px-2 py-1 text-sm" aria-label="Selesai slot {{ $slot->label }}">
                            </div>
                        @endforeach
                    </div>
                    <div class="flex justify-end"><x-button type="submit">Simpan slot</x-button></div>
                </form>
            </x-card>
        @elseif ($tab === 'kelas')
            <x-card class="flex flex-col gap-4">
                <p class="text-sm text-muted">Jumlah peserta yang belum tersedia ditandai "Belum diisi". Lengkapi sebelum memeriksa kapasitas dan bentrok kelas.</p>
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs text-muted">
                        <tr><th class="py-3 pr-4 font-medium">Kelas</th><th class="py-3 pr-4 font-medium">Prodi</th><th class="py-3 pr-4 font-medium">Angkatan</th><th class="py-3 pr-4 font-medium">Jadwal</th><th class="py-3 pr-4 font-medium">Peserta</th><th class="py-3 pr-4 font-medium">Data</th><th class="py-3 font-medium">Aksi</th></tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($classGroups as $classGroup)
                            <tr>
                                <td class="py-3 pr-4 font-semibold">{{ $classGroup->name }}</td>
                                <td class="py-3 pr-4 text-muted">{{ $classGroup->study_program }}</td>
                                <td class="py-3 pr-4">{{ $classGroup->cohort_year }}</td>
                                <td class="py-3 pr-4">{{ $classGroup->schedule_sessions_count }} sesi</td>
                                <td class="py-3 pr-4">{{ $classGroup->student_count ? $classGroup->student_count.' mahasiswa' : 'Belum diisi' }}</td>
                                <td class="py-3 pr-4">
                                    @if (! $classGroup->is_active)<x-status-badge>Arsip</x-status-badge>
                                    @elseif ($classGroup->student_count === null)<x-status-badge variant="warning">Perlu dilengkapi</x-status-badge>
                                    @else<x-status-badge variant="success">Lengkap</x-status-badge>@endif
                                </td>
                                <td class="py-3">
                                    <button type="button" class="font-semibold text-primary hover:underline" x-data x-on:click="$dispatch('open-modal', 'class-{{ $classGroup->id }}')">Edit</button>
                                    <form method="POST" action="{{ route('admin.academic.classes.destroy', $classGroup) }}" class="ml-3 inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-danger hover:underline">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        @elseif ($tab === 'mata-kuliah')
            <x-card class="flex flex-col gap-4">
                <p class="text-sm text-muted">Kode kurikulum dipertahankan. Nama mirip dengan kode berbeda tidak digabung otomatis.</p>
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs text-muted">
                        <tr><th class="py-3 pr-4 font-medium">Kode MK</th><th class="py-3 pr-4 font-medium">Mata kuliah</th><th class="py-3 pr-4 font-medium">SKS</th><th class="py-3 pr-4 font-medium">Kurikulum</th><th class="py-3 pr-4 font-medium">Penawaran</th><th class="py-3 font-medium">Aksi</th></tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($courses as $course)
                            <tr>
                                <td class="py-3 pr-4 font-mono text-xs">{{ $course->code }}</td>
                                <td class="py-3 pr-4 font-semibold">{{ $course->name }}</td>
                                <td class="py-3 pr-4">{{ $course->sks }}</td>
                                <td class="py-3 pr-4 text-muted">{{ $course->curriculum ?? '—' }}</td>
                                <td class="py-3 pr-4">{{ $course->offerings_count }} kelas</td>
                                <td class="py-3">
                                    <button type="button" class="font-semibold text-primary hover:underline" x-data x-on:click="$dispatch('open-modal', 'course-{{ $course->id }}')">Edit</button>
                                    <form method="POST" action="{{ route('admin.academic.courses.destroy', $course) }}" class="ml-3 inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-danger hover:underline">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        @elseif ($tab === 'penawaran')
            <x-card class="flex flex-col gap-4">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs text-muted">
                        <tr><th class="py-3 pr-4 font-medium">Kelas</th><th class="py-3 pr-4 font-medium">Mata kuliah</th><th class="py-3 pr-4 font-medium">SKS</th><th class="py-3 pr-4 font-medium">Tim pengajar</th><th class="py-3 font-medium">Aksi</th></tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($offerings as $offering)
                            <tr>
                                <td class="py-3 pr-4 font-semibold">{{ $offering->classGroup->name }}</td>
                                <td class="py-3 pr-4">{{ $offering->course->name }}</td>
                                <td class="py-3 pr-4">{{ $offering->course->sks }}</td>
                                <td class="py-3 pr-4 text-muted">{{ $offering->teachingAssignments->map(fn ($assignment) => $assignment->lecturer->name)->implode(', ') ?: 'Belum ada pengampu' }}</td>
                                <td class="py-3">
                                    <a href="{{ route('admin.teaching.index', ['penawaran' => $offering->id]) }}" class="font-semibold text-primary hover:underline">Atur tim</a>
                                    <form method="POST" action="{{ route('admin.academic.offerings.destroy', $offering) }}" class="ml-3 inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-danger hover:underline">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-10 text-center text-muted">Belum ada penawaran perkuliahan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-card>
        @endif
    </div>
@endsection

@push('dialogs')
    @if ($tab === 'kelas')
        @foreach ([null, ...$classGroups] as $classGroup)
            <x-modal :name="$classGroup ? 'class-'.$classGroup->id : 'class-new'" :title="$classGroup ? 'Edit kelas' : 'Tambah kelas'" width="max-w-[480px]">
                <form method="POST" action="{{ $classGroup ? route('admin.academic.classes.update', $classGroup) : route('admin.academic.classes.store') }}" class="flex flex-col gap-4">
                    @csrf
                    @if ($classGroup) @method('PUT') @endif
                    <x-field label="Nama kelas" name="name"><x-input name="name" :id="'name-'.($classGroup?->id ?? 'new')" :value="$classGroup?->name" placeholder="Contoh: Tekom C" required /></x-field>
                    <x-field label="Prodi" name="study_program"><x-input name="study_program" :id="'program-'.($classGroup?->id ?? 'new')" :value="$classGroup?->study_program" placeholder="Teknik Komputer" required /></x-field>
                    <div class="grid grid-cols-2 gap-3">
                        <x-field label="Angkatan" name="cohort_year"><x-input type="number" name="cohort_year" :id="'cohort-'.($classGroup?->id ?? 'new')" :value="$classGroup?->cohort_year ?? now()->year" required /></x-field>
                        <x-field label="Jumlah mahasiswa" name="student_count"><x-input type="number" name="student_count" :id="'students-'.($classGroup?->id ?? 'new')" :value="$classGroup?->student_count" placeholder="Belum diisi" /></x-field>
                    </div>
                    @if ($classGroup)
                        <input type="hidden" name="code" value="{{ $classGroup->code }}">
                        <label class="flex items-center gap-2 text-sm"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" class="size-4 accent-primary" @checked($classGroup->is_active)> Kelas aktif</label>
                    @endif
                    <div class="flex justify-end gap-3">
                        <x-button variant="secondary" x-on:click="$dispatch('close-modal', '{{ $classGroup ? 'class-'.$classGroup->id : 'class-new' }}')">Batal</x-button>
                        <x-button type="submit">Simpan</x-button>
                    </div>
                </form>
            </x-modal>
        @endforeach
    @elseif ($tab === 'mata-kuliah')
        @foreach ([null, ...$courses] as $course)
            <x-modal :name="$course ? 'course-'.$course->id : 'course-new'" :title="$course ? 'Edit mata kuliah' : 'Tambah mata kuliah'" width="max-w-[480px]">
                <form method="POST" action="{{ $course ? route('admin.academic.courses.update', $course) : route('admin.academic.courses.store') }}" class="flex flex-col gap-4">
                    @csrf
                    @if ($course) @method('PUT') @endif
                    <div class="grid grid-cols-[1fr_100px] gap-3">
                        <x-field label="Kode MK" name="code"><x-input name="code" :id="'code-'.($course?->id ?? 'new')" :value="$course?->code" required /></x-field>
                        <x-field label="SKS" name="sks"><x-input type="number" name="sks" :id="'sks-'.($course?->id ?? 'new')" :value="$course?->sks" min="1" required /></x-field>
                    </div>
                    <x-field label="Nama mata kuliah" name="name"><x-input name="name" :id="'course-name-'.($course?->id ?? 'new')" :value="$course?->name" required /></x-field>
                    <x-field label="Kurikulum" name="curriculum"><x-input name="curriculum" :id="'curriculum-'.($course?->id ?? 'new')" :value="$course?->curriculum" placeholder="Contoh: Kurikulum 2025" /></x-field>
                    <div class="flex justify-end gap-3">
                        <x-button variant="secondary" x-on:click="$dispatch('close-modal', '{{ $course ? 'course-'.$course->id : 'course-new' }}')">Batal</x-button>
                        <x-button type="submit">Simpan</x-button>
                    </div>
                </form>
            </x-modal>
        @endforeach
    @elseif ($tab === 'penawaran' && $period)
        <x-modal name="offering-new" title="Tambah penawaran perkuliahan" width="max-w-[480px]">
            <form method="POST" action="{{ route('admin.academic.offerings.store') }}" class="flex flex-col gap-4">
                @csrf
                <input type="hidden" name="period_id" value="{{ $period->id }}">
                <x-field label="Mata kuliah" name="course_id"><x-select name="course_id" :options="$courseOptions" placeholder="Pilih mata kuliah" required /></x-field>
                <x-field label="Kelas" name="class_group_id"><x-select name="class_group_id" :options="$classOptions" placeholder="Pilih kelas" required /></x-field>
                <div class="flex justify-end gap-3">
                    <x-button variant="secondary" x-on:click="$dispatch('close-modal', 'offering-new')">Batal</x-button>
                    <x-button type="submit">Simpan</x-button>
                </div>
            </form>
        </x-modal>
    @endif
@endpush
