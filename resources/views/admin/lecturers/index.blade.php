@use('App\Enums\LecturerHomebase')
@use('App\Enums\LecturerProfileStatus')
@use('App\Http\Controllers\Admin\LecturerController')
@extends('layouts.admin')

@section('title', 'Data Dosen')

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.dashboard')">Dashboard</x-button>
@endsection

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Data dosen dari jadwal" subtitle="Nama dan mata kuliah mengikuti sumber jadwal. Asal, keahlian, ketersediaan, dan target beban tetap perlu verifikasi.">
            <x-slot:actions>
                <x-button variant="soft" icon="upload" x-data x-on:click="$dispatch('open-modal', 'lecturer-import')">Impor dari spreadsheet</x-button>
                <x-button icon="plus" x-data x-on:click="$dispatch('open-modal', 'lecturer-new')">Tambah dosen</x-button>
            </x-slot:actions>
        </x-page-header>

        <x-card>
            <x-stepper :steps="['Data', 'Tim mengajar', 'Draf jadwal', 'Periksa', 'Tetapkan']" :current="1" />
        </x-card>

        <div class="grid grid-cols-2 gap-5">
            <x-stat-card label="Nama pada sumber" :value="$total" hint="Dosen yang tercatat dari jadwal dan impor" />
            <x-stat-card label="Perlu verifikasi" :value="$unverified" tone="danger" hint="Belum bisa dipilih sebagai pengajar" />
        </div>

        <x-card class="flex flex-col gap-4">
            <form method="GET" action="{{ route('admin.lecturers.index') }}" class="flex gap-3">
                <x-input name="q" :value="$keyword" placeholder="Cari nama dosen" aria-label="Cari nama dosen" />
                @if ($filter !== 'semua')<input type="hidden" name="filter" value="{{ $filter }}">@endif
                <x-button type="submit">Cari</x-button>
                <x-button variant="secondary" :href="route('admin.lecturers.index')">Reset</x-button>
            </form>
            <x-tabs :items="collect(LecturerController::FILTERS)->map(fn ($label, $key) => ['label' => $label, 'href' => route('admin.lecturers.index', array_filter(['filter' => $key === 'semua' ? null : $key, 'q' => $keyword])), 'active' => $filter === $key])->values()->all()" />

            @if ($lecturers->isEmpty())
                <x-empty-state title="Dosen tidak ditemukan" message="Coba kata kunci lain atau hapus filter." />
            @else
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs text-muted">
                        <tr><th class="py-3 pr-4 font-medium">Dosen</th><th class="py-3 pr-4 font-medium">Jumlah MK</th><th class="py-3 pr-4 font-medium">Homebase</th><th class="py-3 pr-4 font-medium">Jabatan fungsional</th><th class="py-3 pr-4 font-medium">Target / maks. SKS</th><th class="py-3 pr-4 font-medium">Status profil</th><th class="py-3 font-medium">Aksi</th></tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($lecturers as $lecturer)
                            @php($term = $lecturer->leadershipTerms->first(fn ($term) => $term->isActiveOn(now())))
                            @php($target = $lecturer->loadTargets->first())
                            <tr>
                                <td class="py-3 pr-4">
                                    <span class="block font-semibold">{{ $lecturer->name }}</span>
                                    @if ($lecturer->nip)<span class="block text-xs text-muted">NIP {{ $lecturer->nip }}</span>@endif
                                    @if ($term)<span class="text-xs text-danger">{{ $term->position }} • tidak dijadwalkan Kamis</span>@endif
                                    @if ($lecturer->source_name && $lecturer->source_name !== $lecturer->name)<span class="block text-xs text-muted">Nama pada sumber: {{ $lecturer->source_name }}</span>@endif
                                </td>
                                <td class="py-3 pr-4">{{ $lecturer->course_count }} MK</td>
                                <td class="py-3 pr-4 text-muted">{{ $lecturer->homebase?->label() ?? 'Asal: belum diverifikasi' }}</td>
                                <td class="py-3 pr-4">
                                    <span class="block">{{ $lecturer->functional_position ?? '—' }}</span>
                                    @if ($lecturer->rank || $lecturer->grade)<span class="block text-xs text-muted">{{ collect([$lecturer->rank, $lecturer->grade])->filter()->implode(' • ') }}</span>@endif
                                </td>
                                <td class="py-3 pr-4 text-muted">{{ $target?->target_sks !== null ? (float) $target->target_sks : '—' }} / {{ $target?->max_sks !== null ? (float) $target->max_sks : '—' }}</td>
                                <td class="py-3 pr-4"><x-status-badge :variant="$lecturer->profile_status === LecturerProfileStatus::Verified ? 'success' : 'warning'">{{ $lecturer->profile_status->label() }}</x-status-badge></td>
                                <td class="py-3"><button type="button" class="font-semibold text-primary hover:underline" x-data x-on:click="$dispatch('open-modal', 'lecturer-{{ $lecturer->id }}')">Detail</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-card>
    </div>
@endsection

@push('dialogs')
    @foreach ([null, ...$lecturers] as $lecturer)
        @php($key = $lecturer?->id ?? 'new')
        @php($term = $lecturer?->leadershipTerms->sortByDesc('valid_from')->first())
        @php($target = $lecturer?->loadTargets->first())
        <x-modal :name="'lecturer-'.$key" :title="$lecturer ? 'Ubah data dosen' : 'Tambah dosen'" width="max-w-[520px]">
            <form method="POST" action="{{ $lecturer ? route('admin.lecturers.update', $lecturer) : route('admin.lecturers.store') }}" class="flex flex-col gap-4">
                @csrf
                @if ($lecturer) @method('PUT') @endif
                <x-field label="Nama dosen" name="name"><x-input name="name" :id="'lname-'.$key" :value="$lecturer?->name" required /></x-field>
                <div class="grid grid-cols-2 gap-3">
                    <x-field label="NIP" name="nip"><x-input name="nip" :id="'nip-'.$key" :value="$lecturer?->nip" placeholder="18 angka" inputmode="numeric" /></x-field>
                    <x-field label="Jabatan fungsional" name="functional_position"><x-input name="functional_position" :id="'fpos-'.$key" :value="$lecturer?->functional_position" placeholder="Contoh: Lektor" /></x-field>
                    <x-field label="Pangkat" name="rank"><x-input name="rank" :id="'rank-'.$key" :value="$lecturer?->rank" placeholder="Contoh: Penata Tingkat I" /></x-field>
                    <x-field label="Golongan" name="grade"><x-input name="grade" :id="'grade-'.$key" :value="$lecturer?->grade" placeholder="Contoh: III/d" /></x-field>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <x-field label="NIDN" name="nidn"><x-input name="nidn" :id="'nidn-'.$key" :value="$lecturer?->nidn" placeholder="Belum diisi" /></x-field>
                    <x-field label="Asal dosen" name="homebase"><x-select name="homebase" :id="'homebase-'.$key" :options="collect(LecturerHomebase::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" :value="$lecturer?->homebase?->value" placeholder="Belum diverifikasi" /></x-field>
                </div>
                <x-field label="Status profil" name="profile_status" hint="Dosen yang belum diverifikasi tidak muncul sebagai calon pengajar.">
                    <x-select name="profile_status" :id="'status-'.$key" :options="collect(LecturerProfileStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" :value="$lecturer?->profile_status->value ?? 'unverified'" />
                </x-field>
                <div class="grid grid-cols-3 gap-3">
                    <x-field label="Jabatan pimpinan (opsional)" name="leadership_position" class="col-span-3"><x-input name="leadership_position" :id="'pos-'.$key" :value="$term?->position" placeholder="Contoh: Ketua Jurusan" /></x-field>
                    <x-field label="Mulai menjabat" name="leadership_from"><x-input type="date" name="leadership_from" :id="'from-'.$key" :value="$term?->valid_from?->toDateString()" /></x-field>
                    <x-field label="Akhir menjabat" name="leadership_to"><x-input type="date" name="leadership_to" :id="'to-'.$key" :value="$term?->valid_to?->toDateString()" /></x-field>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <x-field label="Target SKS" name="target_sks" hint="BELUM DITETAPKAN jurusan bila kosong."><x-input type="number" step="0.5" name="target_sks" :id="'target-'.$key" :value="$target?->target_sks" /></x-field>
                    <x-field label="Maksimum SKS" name="max_sks"><x-input type="number" step="0.5" name="max_sks" :id="'max-'.$key" :value="$target?->max_sks" /></x-field>
                </div>
                <div class="flex justify-end gap-3">
                    <x-button variant="secondary" x-on:click="$dispatch('close-modal', 'lecturer-{{ $key }}')">Batal</x-button>
                    <x-button type="submit">Simpan</x-button>
                </div>
            </form>
        </x-modal>
    @endforeach

    <x-modal name="lecturer-import" title="Impor dosen dari spreadsheet" width="max-w-[480px]">
        <form method="POST" action="{{ route('admin.lecturers.import') }}" enctype="multipart/form-data" class="flex flex-col gap-4">
            @csrf
            <p class="text-sm text-muted">Kolom: Nama, NIP, NIDN, Homebase (JTIK / Luar JTIK), Pangkat, Gol., Jabatan. Baris dicocokkan lewat NIP, NIDN, lalu nama; dosen dengan NIP atau NIDN dianggap terverifikasi.</p>
            <input type="file" name="file" accept=".xlsx,.xls,.csv" required class="text-sm">
            <div class="flex justify-end gap-3">
                <x-button variant="secondary" x-on:click="$dispatch('close-modal', 'lecturer-import')">Batal</x-button>
                <x-button type="submit">Impor</x-button>
            </div>
        </form>
    </x-modal>
@endpush
