@use('App\Enums\SessionPhase')
@use('App\Enums\TeachingRole')
@extends('layouts.admin')

@section('title', 'Pembagian SKS')
@section('breadcrumb', 'Pembagian Mengajar')

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.dashboard')">Dashboard</x-button>
@endsection

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Pembagian mengajar dan beban SKS" subtitle="Bagi mata kuliah ke dosen yang layak. Beban dibandingkan dengan target tiap dosen, tidak harus sama persis." />

        <x-card>
            <x-stepper :steps="['Data', 'Tim mengajar', 'Draf jadwal', 'Periksa', 'Tetapkan']" :current="2" />
        </x-card>

        @if ($withoutLead > 0)
            <x-alert variant="danger">{{ $withoutLead }} mata kuliah belum punya pengampu. Target SKS di halaman ini belum merupakan penetapan resmi; nilai resmi, termasuk target pimpinan, ditetapkan jurusan.</x-alert>
        @endif

        <div class="grid grid-cols-[1fr_440px] gap-6">
            <x-card class="flex flex-col gap-4">
                <h2 class="text-lg font-semibold">Rekap beban dosen</h2>
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs text-muted">
                        <tr><th class="py-3 pr-4 font-medium">Dosen</th><th class="py-3 pr-4 font-medium">Target</th><th class="py-3 pr-4 font-medium">Maks.</th><th class="py-3 pr-4 font-medium">Terbagi</th><th class="py-3 pr-4 font-medium">Sisa</th><th class="py-3 font-medium">Status</th></tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($loads as $load)
                            <tr>
                                <td class="py-3 pr-4 font-medium">{{ $load['lecturer']->name }}</td>
                                <td class="py-3 pr-4">{{ $load['target'] ?? '—' }}</td>
                                <td class="py-3 pr-4">{{ $load['max'] ?? '—' }}</td>
                                <td class="py-3 pr-4">{{ $load['assigned'] }}@if ($load['pending'] > 0)<span class="block text-xs text-muted">+{{ $load['pending'] }} perlu ditetapkan</span>@endif</td>
                                <td class="py-3 pr-4">{{ $load['remaining'] ?? '—' }}</td>
                                <td class="py-3"><x-status-badge :variant="$load['tone']">{{ $load['status'] }}</x-status-badge></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-8 text-center text-muted">Belum ada pembagian mengajar.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <p class="text-xs text-muted">Sisa = maksimum dikurangi seluruh beban yang diakui. Dua pengajar tidak otomatis masing-masing menerima SKS penuh.</p>
            </x-card>

            <x-card class="flex flex-col gap-4 self-start">
                <h2 class="text-lg font-semibold">Tim pengajar</h2>
                <form method="GET" action="{{ route('admin.teaching.index') }}">
                    <x-select name="penawaran" :options="$offerings->mapWithKeys(fn ($offering) => [$offering->id => $offering->course->name.' • '.$offering->classGroup->name])->all()" :value="$selected?->id" onchange="this.form.submit()" aria-label="Pilih penawaran" />
                </form>

                @if ($selected)
                    @forelse ($selected->teachingAssignments as $assignment)
                        <div class="flex items-start justify-between gap-3 rounded-control border border-border px-4 py-3 text-sm">
                            <div class="flex flex-col">
                                <span class="font-semibold">{{ $assignment->lecturer->name }}</span>
                                <span class="text-muted">{{ $assignment->role->label() }} • {{ $assignment->phase->label() }} • {{ $credits[$assignment->id] !== null ? $credits[$assignment->id].' SKS diakui' : 'SKS diakui menunggu kebijakan' }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <form method="POST" action="{{ route('admin.teaching.lock', $assignment) }}">
                                    @csrf
                                    <button type="submit" class="text-primary" aria-label="{{ $assignment->is_locked ? 'Buka kunci' : 'Kunci' }} penugasan"><i data-lucide="{{ $assignment->is_locked ? 'lock' : 'lock-open' }}" class="size-4"></i></button>
                                </form>
                                @unless ($assignment->is_locked)
                                    <form method="POST" action="{{ route('admin.teaching.destroy', $assignment) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-danger" aria-label="Hapus {{ $assignment->lecturer->name }}"><i data-lucide="trash-2" class="size-4"></i></button>
                                    </form>
                                @endunless
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-muted">Belum ada pengajar untuk penawaran ini.</p>
                    @endforelse

                    <form method="POST" action="{{ route('admin.teaching.store') }}" class="flex flex-col gap-3 border-t border-border pt-4">
                        @csrf
                        <input type="hidden" name="course_offering_id" value="{{ $selected->id }}">
                        <x-field label="Dosen" name="lecturer_id"><x-select name="lecturer_id" :options="$lecturerOptions" placeholder="Pilih dosen terverifikasi" required /></x-field>
                        <div class="grid grid-cols-2 gap-3">
                            <x-field label="Peran" name="role"><x-select name="role" :options="collect(TeachingRole::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" /></x-field>
                            <x-field label="Periode mengajar" name="phase"><x-select name="phase" :options="collect(SessionPhase::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" /></x-field>
                        </div>
                        <x-field label="SKS diakui (opsional)" name="credited_sks" hint="Kosongkan bila pembagian kredit belum ditetapkan jurusan."><x-input type="number" step="0.5" name="credited_sks" /></x-field>
                        <x-button type="submit" variant="soft" icon="plus">Tambah ke tim</x-button>
                    </form>
                @else
                    <p class="text-sm text-muted">Belum ada penawaran perkuliahan. Tambahkan di Data Akademik.</p>
                @endif
            </x-card>
        </div>
    </div>
@endsection
