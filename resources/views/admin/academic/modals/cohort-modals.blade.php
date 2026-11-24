@php
    $latestCohort = $distinctCohorts->first() ?? now()->year;
    $targetDefault = $latestCohort + 1;
@endphp

{{-- ========================================================================= --}}
{{-- MODAL 1: LULUSKAN & ARSIPKAN ANGKATAN (COMPACT & SLEEK)                   --}}
{{-- ========================================================================= --}}
<x-modal name="modal-graduate-cohort" title="Luluskan & Arsipkan Angkatan" width="max-w-xl">
    <div x-data="cohortArchiveModal({
        initialCohort: '{{ $distinctCohorts->first() ?? '' }}',
        previewUrl: '{{ route('admin.academic.cohorts.preview-archive') }}'
    })"
    x-on:open-modal.window="if ($event.detail === 'modal-graduate-cohort') { if (!preview && cohortYear) fetchPreview(); }"
    class="flex flex-col gap-3.5">

        <div>
            <p class="text-xs text-muted">
                Arsipkan seluruh kelas dan nonaktifkan akun Ketua Tingkat (Keti) aktif pada angkatan yang telah menyelesaikan studi.
            </p>
        </div>

        <form method="POST" action="{{ route('admin.academic.cohorts.graduate') }}"
              x-data="{ confirmGraduate: false }"
              @open-modal.window="if ($event.detail === 'modal-graduate-cohort') { confirmGraduate = false; }"
              class="flex flex-col gap-3.5">
            @csrf
            <input type="hidden" name="cohort_year" :value="cohortYear">

            {{-- Pilihan Angkatan --}}
            <div>
                <label for="cohort_year_select" class="mb-1 block text-xs font-medium text-muted">Pilih Angkatan yang Diluluskan</label>
                <select id="cohort_year_select" x-model="cohortYear" @change="fetchPreview()"
                        class="w-full rounded-control border border-border bg-surface px-3 py-2 text-xs font-semibold text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    <option value="" disabled>-- Pilih Angkatan --</option>
                    @foreach ($distinctCohorts ?? [] as $cohort)
                        <option value="{{ $cohort }}">Angkatan {{ $cohort }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Loading State --}}
            <div x-show="loading" class="flex flex-col items-center justify-center gap-1.5 rounded-lg border border-border bg-page/50 py-5 text-muted">
                <i data-lucide="loader" class="size-5 animate-spin text-primary"></i>
                <span class="text-xs font-medium">Memuat rincian dampak kelulusan...</span>
            </div>

            {{-- Error State --}}
            <div x-show="error && !loading" x-cloak class="flex items-start gap-2 rounded-lg border border-danger/30 bg-danger-soft/40 p-2.5 text-xs text-danger">
                <i data-lucide="circle-alert" class="size-4 shrink-0 mt-0.5"></i>
                <div class="flex-1">
                    <strong class="font-bold">Gagal memuat preview:</strong>
                    <span x-text="error"></span>
                </div>
                <button type="button" @click="fetchPreview()" class="font-bold underline hover:no-underline">Coba lagi</button>
            </div>

            {{-- Preview Konten Ringkas --}}
            <template x-if="preview && !loading">
                <div class="flex flex-col gap-3">
                    {{-- Mini Strip 3 Metrik (Kompak & Ramping) --}}
                    <div class="grid grid-cols-1 divide-y divide-border rounded-lg border border-border bg-page/70 text-center sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                        <div class="px-2 py-1.5">
                            <span class="block text-[10px] font-semibold uppercase tracking-wider text-muted">Kelas Aktif</span>
                            <div class="mt-0.5 flex items-baseline justify-center gap-1">
                                <span class="text-sm font-bold text-ink" x-text="preview.active_classes_count"></span>
                                <span class="text-[10px] text-muted" x-text="'/ ' + preview.classes.length"></span>
                            </div>
                        </div>

                        <div class="px-2 py-1.5">
                            <span class="block text-[10px] font-semibold uppercase tracking-wider text-muted">Keti Aktif</span>
                            <div class="mt-0.5 flex items-baseline justify-center gap-1">
                                <span class="text-sm font-bold text-danger" x-text="preview.active_ketis_count"></span>
                                <span class="text-[10px] text-muted">dinonaktifkan</span>
                            </div>
                        </div>

                        <div class="px-2 py-1.5">
                            <span class="block text-[10px] font-semibold uppercase tracking-wider text-muted">Penawaran Matkul</span>
                            <div class="mt-0.5 flex items-baseline justify-center gap-1">
                                <span class="text-sm font-bold" :class="preview.active_offerings_count > 0 ? 'text-amber-600' : 'text-success'" x-text="preview.active_offerings_count"></span>
                                <span class="text-[10px] text-muted">semester ini</span>
                            </div>
                        </div>
                    </div>

                    {{-- Peringatan jika masih ada penawaran berjalan (Kompak) --}}
                    <div x-show="preview.active_offerings_count > 0" class="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50/80 px-2.5 py-1.5 text-[11px] text-amber-900">
                        <i data-lucide="triangle-alert" class="size-3.5 shrink-0 text-amber-600"></i>
                        <span><strong class="font-bold">Perhatian:</strong> Masih ada <strong x-text="preview.active_offerings_count"></strong> penawaran mata kuliah semester berjalan pada angkatan ini.</span>
                    </div>

                    {{-- Daftar Kelas Terdampak (Kompak) --}}
                    <div class="flex flex-col gap-1">
                        <div class="flex items-center justify-between text-[11px]">
                            <span class="font-semibold text-ink">Daftar Kelas (<span x-text="preview.classes.length"></span>)</span>
                            <span class="text-muted text-[10px]">Status</span>
                        </div>
                        <div class="max-h-28 overflow-y-auto divide-y divide-border rounded-lg border border-border bg-page/40 text-xs">
                            <template x-for="cls in preview.classes" :key="cls.id">
                                <div class="flex items-center justify-between px-2.5 py-1.5">
                                    <div class="flex items-center gap-1.5 min-w-0">
                                        <span class="font-semibold text-ink truncate" x-text="cls.name"></span>
                                        <span class="font-mono text-[10px] text-muted" x-text="'(' + cls.code + ')'"></span>
                                        <span class="text-[10px] text-muted hidden sm:inline" x-text="'• ' + cls.study_program"></span>
                                    </div>
                                    <div class="flex items-center gap-1.5 shrink-0">
                                        <span x-show="cls.student_count" class="text-[10px] text-muted" x-text="cls.student_count + ' mhs'"></span>
                                        <template x-if="cls.is_active">
                                            <span class="rounded bg-success-soft px-1.5 py-0.5 text-[9px] font-bold text-success">Aktif &rarr; Arsip</span>
                                        </template>
                                        <template x-if="!cls.is_active">
                                            <span class="rounded bg-page px-1.5 py-0.5 text-[9px] font-medium text-muted ring-1 ring-border">Sudah Arsip</span>
                                        </template>
                                    </div>
                                </div>
                            </template>
                            <div x-show="preview.classes.length === 0" class="p-3 text-center text-xs text-muted">
                                Tidak ada kelas ditemukan untuk angkatan ini.
                            </div>
                        </div>
                    </div>

                    {{-- Daftar Keti Aktif Terdampak (Kompak) --}}
                    <div class="flex flex-col gap-1">
                        <div class="flex items-center justify-between text-[11px]">
                            <span class="font-semibold text-ink">Akun Keti Aktif (<span x-text="preview.active_ketis.length"></span>)</span>
                            <span class="text-muted text-[10px]">Akan dinonaktifkan</span>
                        </div>
                        <div class="max-h-24 overflow-y-auto divide-y divide-border rounded-lg border border-border bg-page/40 text-xs">
                            <template x-for="keti in preview.active_ketis" :key="keti.id">
                                <div class="flex items-center justify-between px-2.5 py-1.5">
                                    <div class="flex items-center gap-1.5 min-w-0">
                                        <i data-lucide="user-x" class="size-3 text-danger shrink-0"></i>
                                        <span class="font-semibold text-ink truncate" x-text="keti.name"></span>
                                        <span x-show="keti.nim" class="font-mono text-[10px] text-muted" x-text="'(' + keti.nim + ')'"></span>
                                    </div>
                                    <div class="flex items-center gap-1.5 shrink-0">
                                        <span class="rounded bg-surface px-1.5 py-0.5 font-mono text-[10px] text-muted border border-border" x-text="keti.class_name"></span>
                                    </div>
                                </div>
                            </template>
                            <div x-show="preview.active_ketis.length === 0" class="p-2.5 text-center text-xs text-muted">
                                Tidak ada akun Keti aktif pada angkatan ini.
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            {{-- Opsi Penonaktifan Keti (Kompak Inline) --}}
            <label class="flex items-center gap-2 rounded-lg border border-border/70 bg-page/30 px-3 py-2 text-xs text-ink cursor-pointer hover:bg-page transition">
                <input type="hidden" name="deactivate_ketis" value="0">
                <input type="checkbox" name="deactivate_ketis" value="1" x-model="deactivateKetis" class="size-3.5 rounded accent-primary cursor-pointer">
                <span class="font-medium">Nonaktifkan akun login Ketua Tingkat (Keti) aktif yang terhubung</span>
            </label>

            {{-- Input Alasan Pengarsipan (Kompak) --}}
            <div>
                <label for="graduate-cohort-reason" class="mb-1 block text-xs font-medium text-muted">Alasan Pengarsipan (Audit Log)</label>
                <input type="text" name="reason" id="graduate-cohort-reason" x-model="reason" placeholder="Contoh: Kelulusan Angkatan 2021"
                       class="w-full rounded-control border border-border bg-surface px-3 py-1.5 text-xs text-ink placeholder:text-muted/60 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
            </div>

            {{-- Konfirmasi In-Modal --}}
            <label for="graduate-cohort-confirm" class="flex items-start gap-2 rounded-lg border border-danger/30 bg-danger-soft/30 px-3 py-2 text-xs text-ink cursor-pointer">
                <input id="graduate-cohort-confirm" type="checkbox" x-model="confirmGraduate" class="mt-0.5 size-3.5 rounded accent-danger cursor-pointer">
                <span>Saya memahami seluruh kelas pada <strong x-text="'Angkatan ' + (cohortYear || '—')"></strong> akan diarsipkan<span x-show="deactivateKetis"> dan akun Keti terkait akan dinonaktifkan</span>.</span>
            </label>

            {{-- Tombol Aksi Modal (Kompak) --}}
            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-border pt-3">
                <button type="button" class="rounded-control border border-border bg-surface px-3.5 py-1.5 text-xs font-semibold text-muted hover:text-ink transition cursor-pointer" x-on:click="$dispatch('close-modal', 'modal-graduate-cohort')">
                    Batal
                </button>
                <button type="submit" class="inline-flex items-center gap-1.5 rounded-control bg-danger px-4 py-1.5 text-xs font-semibold text-white hover:bg-danger/90 transition shadow-xs cursor-pointer disabled:cursor-not-allowed disabled:opacity-50"
                        x-bind:disabled="!confirmGraduate || !cohortYear || loading || (preview && preview.classes.length === 0)">
                    <i data-lucide="graduation-cap" class="size-3.5" aria-hidden="true"></i>
                    <span>Luluskan &amp; Arsipkan Angkatan</span>
                </button>
            </div>
        </form>
    </div>
</x-modal>

{{-- ========================================================================= --}}
{{-- MODAL 2: BUKA ANGKATAN BARU DARI TEMPLAT (COMPACT & SLEEK)               --}}
{{-- ========================================================================= --}}
<x-modal name="modal-generate-cohort" title="Buka Angkatan Baru dari Templat" width="max-w-3xl">
    <div x-data="cohortGenerateModal({
        initialSource: '{{ $distinctCohorts->first() ?? '' }}',
        initialTarget: {{ $targetDefault }},
        previewUrl: '{{ route('admin.academic.cohorts.preview-generate') }}'
    })"
    x-on:open-modal.window="if ($event.detail === 'modal-generate-cohort') { if (classes.length === 0 && sourceCohort && targetCohort) fetchPreview(); }"
    class="flex flex-col gap-3.5">

        <div>
            <p class="text-xs text-muted">
                Generate kelas-kelas angkatan baru secara otomatis berdasarkan struktur kelas dari angkatan sumber.
            </p>
        </div>

        <form method="POST" action="{{ route('admin.academic.cohorts.generate') }}"
              class="flex flex-col gap-3.5">
            @csrf

            {{-- Pengaturan Angkatan Sumber & Target --}}
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label for="source_cohort_select" class="mb-1 block text-xs font-medium text-muted">Angkatan Sumber (Templat)</label>
                    <select id="source_cohort_select" x-model="sourceCohort" @change="fetchPreview()"
                            class="w-full rounded-control border border-border bg-surface px-3 py-2 text-xs font-semibold text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                        <option value="" disabled>-- Pilih Angkatan Sumber --</option>
                        @foreach ($distinctCohorts ?? [] as $cohort)
                            <option value="{{ $cohort }}">Angkatan {{ $cohort }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="target_cohort_input" class="mb-1 block text-xs font-medium text-muted">Tahun Angkatan Baru (Target)</label>
                    <input type="number" min="2000" max="2100" id="target_cohort_input"
                           x-model.number="targetCohort"
                           @input.debounce.400ms="fetchPreview()"
                           required
                           placeholder="Contoh: {{ $targetDefault }}"
                           class="w-full rounded-control border border-border bg-surface px-3 py-2 text-xs font-semibold text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                </div>
            </div>

            {{-- Opsi Jumlah Mahasiswa (Kompak) --}}
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-page/70 px-3 py-2 text-xs">
                <label class="inline-flex items-center gap-2 font-medium text-ink cursor-pointer">
                    <input type="checkbox" x-model="copyStudentCount" @change="fetchPreview()" class="size-3.5 rounded accent-primary cursor-pointer">
                    <span>Salin jumlah mahasiswa dari templat sumber</span>
                </label>

                <div class="flex items-center gap-1.5 text-muted">
                    <label for="default-students-input">Default jika kosong:</label>
                    <input type="number" min="1" max="500" id="default-students-input"
                           x-model.number="defaultStudentCount"
                           @input.debounce.400ms="fetchPreview()"
                           placeholder="40"
                           class="w-16 rounded border border-border bg-surface px-2 py-1 text-center text-xs font-semibold text-ink focus:border-primary focus:outline-none">
                    <span>mhs</span>
                </div>
            </div>

            {{-- Loading State --}}
            <div x-show="loading" class="flex flex-col items-center justify-center gap-1.5 rounded-lg border border-border bg-page/50 py-6 text-muted">
                <i data-lucide="loader" class="size-5 animate-spin text-primary"></i>
                <span class="text-xs font-medium">Menghitung nama kelas baru, kode unik, dan duplikasi...</span>
            </div>

            {{-- Error State --}}
            <div x-show="error && !loading" x-cloak class="flex items-start gap-2 rounded-lg border border-danger/30 bg-danger-soft/40 p-2.5 text-xs text-danger">
                <i data-lucide="circle-alert" class="size-4 shrink-0 mt-0.5"></i>
                <div class="flex-1">
                    <strong class="font-bold">Peringatan:</strong>
                    <span x-text="error"></span>
                </div>
                <button type="button" @click="fetchPreview()" class="font-bold underline hover:no-underline">Coba lagi</button>
            </div>

            {{-- Preview Tabel Kelas Target (Kompak) --}}
            <div x-show="!loading && !error && classes.length > 0" class="flex flex-col gap-1.5">
                <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                    <div class="flex items-center gap-1.5">
                        <span class="font-semibold text-ink">Preview Kelas Target (<span x-text="classes.length"></span>):</span>
                        <span class="rounded bg-success-soft px-1.5 py-0.5 text-[10px] font-bold text-success" x-text="selectedCount + ' dipilih'"></span>
                        <span x-show="classes.some(c => c.already_exists)" class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800" x-text="classes.filter(c => c.already_exists).length + ' sudah ada'"></span>
                    </div>
                    <button type="button" @click="toggleSelectAll(!isAllSelected)" class="text-[11px] font-semibold text-primary hover:underline cursor-pointer" x-text="isAllSelected ? 'Batalkan Semua' : 'Pilih Semua yang Tersedia'"></button>
                </div>

                <div class="max-h-56 overflow-x-auto overflow-y-auto rounded-lg border border-border custom-scrollbar">
                    <table class="w-full border-collapse text-left text-xs">
                        <thead class="sticky top-0 z-10 border-b border-border bg-page text-[11px] font-semibold text-muted">
                            <tr>
                                <th class="w-8 px-2 py-1.5 text-center">
                                    <input type="checkbox"
                                           :checked="isAllSelected"
                                           @change="toggleSelectAll($event.target.checked)"
                                           class="size-3.5 rounded border-border text-primary focus:ring-primary cursor-pointer"
                                           title="Pilih semua yang baru">
                                </th>
                                <th class="px-2.5 py-1.5">Sumber</th>
                                <th class="px-2.5 py-1.5">Nama Kelas Target</th>
                                <th class="px-2.5 py-1.5">Kode</th>
                                <th class="px-2.5 py-1.5">Prodi</th>
                                <th class="px-2.5 py-1.5 text-center">Mhs</th>
                                <th class="px-2.5 py-1.5 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <template x-for="cls in classes" :key="cls.source_id">
                                <tr class="transition" :class="cls.already_exists ? 'bg-page/50 opacity-60' : (cls.selected ? 'bg-primary-soft/15' : 'hover:bg-page/40')">
                                    {{-- Checkbox --}}
                                    <td class="px-2 py-1.5 text-center">
                                        <input type="checkbox"
                                               x-model="cls.selected"
                                               :disabled="cls.already_exists"
                                               class="size-3.5 rounded border-border text-primary focus:ring-primary cursor-pointer">
                                    </td>

                                    {{-- Nama Kelas Sumber --}}
                                    <td class="px-2.5 py-1.5 font-medium text-muted truncate max-w-[120px]" x-text="cls.source_name"></td>

                                    {{-- Nama Target Baru --}}
                                    <td class="px-2.5 py-1.5">
                                        <input type="text"
                                               x-model="cls.target_name"
                                               :disabled="cls.already_exists || !cls.selected"
                                               required
                                               class="w-36 rounded border border-border bg-surface px-2 py-0.5 text-xs font-semibold text-ink focus:border-primary focus:outline-none">
                                    </td>

                                    {{-- Kode Target Baru --}}
                                    <td class="px-2.5 py-1.5 font-mono text-[10px] text-muted truncate max-w-[100px]" x-text="cls.target_code"></td>

                                    {{-- Program Studi --}}
                                    <td class="px-2.5 py-1.5 text-muted truncate max-w-[140px]" x-text="cls.study_program"></td>

                                    {{-- Jumlah Mahasiswa --}}
                                    <td class="px-2.5 py-1.5 text-center">
                                        <input type="number" min="1" max="500"
                                               x-model.number="cls.student_count"
                                               :disabled="cls.already_exists || !cls.selected"
                                               placeholder="40"
                                               class="w-14 rounded border border-border bg-surface px-1.5 py-0.5 text-center text-xs font-semibold text-ink focus:border-primary focus:outline-none">
                                    </td>

                                    {{-- Status Duplikasi / Baru --}}
                                    <td class="px-2.5 py-1.5 text-center">
                                        <template x-if="cls.already_exists">
                                            <span class="inline-flex items-center rounded bg-amber-100 px-1.5 py-0.5 text-[9px] font-semibold text-amber-800">
                                                Sudah Ada
                                            </span>
                                        </template>
                                        <template x-if="!cls.already_exists">
                                            <span class="inline-flex items-center rounded bg-success-soft px-1.5 py-0.5 text-[9px] font-semibold text-success">
                                                Siap Dibuat
                                            </span>
                                        </template>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Empty State jika tidak ada kelas di templat sumber --}}
            <div x-show="!loading && !error && classes.length === 0" class="rounded-lg border border-border bg-page/50 p-6 text-center text-xs text-muted">
                <i data-lucide="layers" class="mx-auto size-6 text-muted/60 mb-1.5"></i>
                <p class="font-semibold text-ink">Tidak ada kelas pada angkatan sumber yang dipilih.</p>
                <p class="mt-0.5">Silakan pilih tahun angkatan sumber lain yang memiliki daftar kelas.</p>
            </div>

            {{-- Hidden Fields untuk Submission Data --}}
            <input type="hidden" name="source_cohort_year" :value="sourceCohort">
            <input type="hidden" name="target_cohort_year" :value="targetCohort">
            <input type="hidden" name="copy_student_count" :value="copyStudentCount ? 1 : 0">
            <input type="hidden" name="default_student_count" :value="defaultStudentCount || ''">

            {{-- Array data kelas yang dipilih --}}
            <template x-for="(cls, idx) in selectedClasses" :key="idx">
                <div>
                    <input type="hidden" :name="'classes[' + idx + '][name]'" :value="cls.target_name">
                    <input type="hidden" :name="'classes[' + idx + '][study_program]'" :value="cls.study_program">
                    <input type="hidden" :name="'classes[' + idx + '][student_count]'" :value="cls.student_count ?? ''">
                </div>
            </template>

            {{-- Tombol Aksi Modal (Kompak) --}}
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-3">
                <div class="text-xs text-muted">
                    <span x-show="sourceCohort && targetCohort && sourceCohort == targetCohort" class="text-danger">
                        Tahun angkatan target harus berbeda dari angkatan sumber.
                    </span>
                    <span x-show="!(sourceCohort && targetCohort && sourceCohort == targetCohort) && selectedCount > 0">
                        <strong class="text-ink" x-text="selectedCount"></strong> kelas baru untuk Angkatan <strong class="text-ink" x-text="targetCohort"></strong>.
                    </span>
                    <span x-show="!(sourceCohort && targetCohort && sourceCohort == targetCohort) && selectedCount === 0" class="text-danger">
                        Pilih minimal 1 kelas.
                    </span>
                </div>
                <div class="ml-auto flex flex-wrap items-center justify-end gap-2">
                    <button type="button" class="rounded-control border border-border bg-surface px-3.5 py-1.5 text-xs font-semibold text-muted hover:text-ink transition cursor-pointer" x-on:click="$dispatch('close-modal', 'modal-generate-cohort')">
                        Batal
                    </button>
                    <button type="submit" class="inline-flex items-center gap-1.5 rounded-control bg-primary px-4 py-1.5 text-xs font-semibold text-white hover:bg-primary/90 transition shadow-xs cursor-pointer disabled:cursor-not-allowed disabled:opacity-50"
                            x-bind:disabled="selectedCount === 0 || loading || !sourceCohort || !targetCohort || sourceCohort == targetCohort">
                        <i data-lucide="copy-plus" class="size-3.5"></i>
                        <span x-text="'Buat ' + selectedCount + ' Kelas Angkatan ' + (targetCohort || '')"></span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</x-modal>

