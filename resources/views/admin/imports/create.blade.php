@use('App\Support\Wita')
@extends('layouts.admin')

@section('title', 'Impor Jadwal')
@section('breadcrumb', 'Impor Jadwal|1 Unggah file')

@section('breadcrumb-actions')
    <x-button variant="secondary" icon="arrow-left" :href="route('admin.schedules.index')">Kembali</x-button>
@endsection

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Impor jadwal perkuliahan" subtitle="Unggah jadwal dalam bentuk Excel atau CSV. Hasilnya diperiksa dulu sebelum disimpan." />

        <x-card>
            <x-stepper :steps="['Unggah file', 'Periksa hasil', 'Masuk draf']" :current="1" />
        </x-card>

        <div class="grid grid-cols-[1fr_380px] gap-6">
            <x-card>
                <form method="POST" action="{{ route('admin.imports.store') }}" enctype="multipart/form-data" class="flex flex-col gap-5">
                    @csrf
                    <x-field label="Semester" name="period_id">
                        <x-select name="period_id" :options="$periods" :value="$activePeriodId" required />
                    </x-field>
                    <x-field label="File jadwal" name="file" hint="Format yang didukung: .xlsx, .xls, .csv (maks. 5 MB). PDF belum dapat dibaca otomatis; simpan sebagai Excel dulu.">
                        <label class="flex cursor-pointer flex-col items-center gap-2 rounded-card border-2 border-dashed border-border bg-page px-6 py-10 text-center hover:border-primary">
                            <i data-lucide="file-spreadsheet" class="size-8 text-primary"></i>
                            <span class="font-semibold">Pilih file jadwal</span>
                            <span class="text-xs text-muted">Kolom: Kelas, Kode MK, Mata Kuliah, Hari, Jam, Ruang, Dosen, Periode</span>
                            <input type="file" name="file" accept=".xlsx,.xls,.csv" class="mt-2 text-sm" required>
                        </label>
                    </x-field>
                    <x-info-box title="Tidak ada yang tersimpan sebelum kamu memeriksa hasilnya">
                        <p>Hasil impor masuk sebagai draf baru. Jadwal resmi dan riwayat versi tetap disimpan.</p>
                    </x-info-box>
                    <div class="flex justify-end gap-3">
                        <x-button variant="secondary" :href="route('admin.schedules.index')">Batal</x-button>
                        <x-button type="submit">Lanjut periksa hasil</x-button>
                    </div>
                </form>
            </x-card>

            <div class="flex flex-col gap-6">
                <x-card class="flex flex-col gap-3">
                    <h2 class="font-semibold">Belum punya templat?</h2>
                    <p class="text-sm text-muted">Dosen lebih dari satu dipisah titik koma: pengampu lebih dulu, lalu mitra. Tulis "Hubungi Dosen" bila jam belum ditentukan.</p>
                    <x-button variant="soft" icon="download" :href="route('admin.schedules.template')">Unduh templat</x-button>
                </x-card>
                <x-card class="flex flex-col gap-2">
                    <h2 class="font-semibold">Impor terakhir</h2>
                    @forelse ($recentImports as $recent)
                        <a href="{{ route('admin.imports.show', $recent) }}" class="flex flex-col rounded-control px-2 py-2 text-sm hover:bg-primary-soft/50">
                            <span class="font-medium">{{ $recent->original_name }}</span>
                            <span class="text-xs text-muted">{{ $recent->status->label() }} • {{ Wita::stamp($recent->created_at) }}{{ $recent->scheduleVersion ? ' • draf v'.$recent->scheduleVersion->version_no : '' }}</span>
                        </a>
                    @empty
                        <p class="text-sm text-muted">Belum ada impor.</p>
                    @endforelse
                </x-card>
            </div>
        </div>
    </div>
@endsection
