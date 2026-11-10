@extends('layouts.admin')

@use('App\Services\Academic\TeachingLoadService')

@section('title', 'Bagi Pertemuan Otomatis')
@section('breadcrumb', 'Pembagian Mengajar / Bagi Pertemuan Otomatis')

@section('content')
    <div class="flex flex-col gap-5">
        <x-page-header title="Pratinjau Pembagian Pertemuan" :subtitle="$period->name">
            <x-slot:actions>
                <x-button variant="secondary" :href="route('admin.teaching.index')">Kembali</x-button>
                <x-button variant="secondary" icon="refresh-cw" :href="route('admin.teaching.phases.preview')" data-native>Hitung Ulang</x-button>
            </x-slot:actions>
        </x-page-header>

        <x-card>
            <x-schedule-steps :current="2" />
        </x-card>

        <x-tabs :items="[
            ['label' => 'Penugasan Dosen & SKS', 'href' => route('admin.teaching.index'), 'active' => false],
            ['label' => 'Bagi Beban Otomatis', 'href' => route('admin.teaching.allocation.preview'), 'active' => false],
            ['label' => 'Atur Fase Pertemuan (UTS/UAS)', 'href' => route('admin.teaching.phases.preview'), 'active' => true],
        ]" />

        <x-card>
            <h2 class="font-semibold text-ink">Aturan 14 pertemuan</h2>
            <ul class="mt-2 space-y-1 text-sm text-muted">
                <li>Pengampu tunggal menangani seluruh pertemuan 1–14.</li>
                <li>Jika ada mitra, pengampu menangani pertemuan 1–7, dengan UTS pada pertemuan ke-7.</li>
                <li>Mitra menangani pertemuan 8–14, dengan UAS pada pertemuan ke-14.</li>
                <li>Jika ada dua mitra, keduanya mengajar pada pertemuan 8–14.</li>
                <li>Setiap pertemuan memakai SKS penuh sesuai mata kuliah.</li>
            </ul>
            <p class="mt-2 text-sm text-muted">Rekap menampilkan hasil perkalian ini di kolom SKS × Pertemuan. Target dan maksimum memakai SKS Semester, yaitu bobot mata kuliah untuk setiap kelas yang diajar.</p>
        </x-card>

        <x-card>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="font-semibold text-ink">{{ count($plan['rows']) }} penawaran perlu diperbarui</h2>
                    <p class="text-sm text-muted">{{ $plan['already_set'] }} sudah sesuai · {{ count($plan['skipped']) }} perlu ditinjau.</p>
                </div>
                @if ($plan['rows'] !== [])
                    <form method="POST" action="{{ route('admin.teaching.phases.apply') }}">
                        @csrf
                        <input type="hidden" name="fingerprint" value="{{ $plan['fingerprint'] }}">
                        <x-button type="submit" icon="check">Simpan Pembagian Pertemuan</x-button>
                    </form>
                @endif
            </div>
            @error('fingerprint')
                <p class="mt-3 text-sm text-danger" role="alert">{{ $message }}</p>
            @enderror

            @if ($plan['rows'] !== [] || $plan['unchanged'] !== [])
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-border text-muted">
                            <tr>
                                <th class="px-3 py-2">Mata Kuliah dan Kelas</th>
                                <th class="px-3 py-2">SKS per Pertemuan</th>
                                <th class="px-3 py-2">Pengampu</th>
                                <th class="px-3 py-2">Mitra · 8–14 (UAS)</th>
                                <th class="px-3 py-2">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ([...$plan['rows'], ...$plan['unchanged']] as $row)
                                <tr>
                                    <td class="px-3 py-2">{{ $row['label'] }}</td>
                                    <td class="px-3 py-2 font-medium">{{ $row['sks'] }} SKS</td>
                                    <td class="px-3 py-2">{{ $row['lead'] }} · {{ $row['sks'] }} SKS × {{ $row['lead_meetings'] }} = {{ $row['sks'] * $row['lead_meetings'] }} SKS</td>
                                    <td class="px-3 py-2">
                                        @forelse ($row['partners'] as $partner)
                                            <div>{{ $partner }} · {{ $row['sks'] }} SKS × {{ TeachingLoadService::MEETINGS_PER_PHASE }} = {{ $row['sks'] * TeachingLoadService::MEETINGS_PER_PHASE }} SKS</div>
                                        @empty
                                            —
                                        @endforelse
                                    </td>
                                    <td class="px-3 py-2">{{ $row['changes'] === [] ? 'Sudah sesuai' : 'Akan diperbarui' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if ($plan['rows'] === [])
                <p class="mt-3 text-sm text-muted">Belum ada perubahan fase yang perlu disimpan.</p>
            @endif
        </x-card>

        @if ($plan['skipped'] !== [])
            <x-card>
                <details>
                    <summary class="cursor-pointer font-semibold text-ink">Penawaran yang perlu ditinjau</summary>
                    <ul class="mt-3 divide-y divide-border text-sm">
                        @foreach ($plan['skipped'] as $item)
                            <li class="py-2">
                                <span class="font-medium text-ink">{{ $item['label'] }}</span>
                                <p class="text-muted">{{ $item['reason'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                </details>
            </x-card>
        @endif
    </div>
@endsection
