            <div class="flex flex-col gap-4 admin-page-stack"
                 x-data="classManager(@js($classesPayload ?? []))"
                 :data-editing="(editMode || batchModalOpen || selectedIds.length > 0) ? 'true' : null"
                 @sicma:navigated.document="batchModalOpen = false">
                <div
                    class="flex flex-col gap-4"
                    x-data="{
                        deleteClassModalOpen: false,
                        deleteClassUrl: '',
                        deleteClassName: '',
                        archiveConfirmModalOpen: false,
                        confirmDeleteClass(url, name) {
                            this.deleteClassUrl = url;
                            this.deleteClassName = name;
                            this.deleteClassModalOpen = true;
                        },
                    }"
                    @keydown.escape.window="batchModalOpen = false; deleteClassModalOpen = false; archiveConfirmModalOpen = false"
                >
                @if ($errors->any() && ! in_array(old('form'), ['class-new', 'class-edit'], true))
                    <x-alert variant="danger">{{ $errors->first() }}</x-alert>
                @endif

                {{-- Toolbar Pencarian & Filter Prodi/Angkatan --}}
                <x-card padding="p-4" class="admin-table-card flex flex-col gap-3">
                    <div class="flex flex-wrap items-center gap-1.5 border-b border-border pb-2.5">
                        <button type="button" @click="statusFilter = 'semua'"
                                :class="statusFilter === 'semua' ? 'bg-primary text-white' : 'bg-page text-muted hover:text-ink hover:bg-border'"
                                class="rounded-full px-3 py-1 text-xs font-semibold transition cursor-pointer">
                            Semua ({{ $classGroups->count() }})
                        </button>
                        <button type="button" @click="statusFilter = 'perlu_dilengkapi'"
                                :class="statusFilter === 'perlu_dilengkapi' ? 'bg-danger text-white' : 'bg-danger-soft/60 text-danger hover:bg-danger-soft'"
                                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition cursor-pointer">
                            <i data-lucide="triangle-alert" class="size-3.5" aria-hidden="true"></i>
                            Perlu Dilengkapi ({{ $classStats['needs_attention'] ?? 0 }})
                        </button>
                        <button type="button" @click="statusFilter = 'lengkap'"
                                :class="statusFilter === 'lengkap' ? 'bg-success text-white' : 'bg-success-soft/60 text-success hover:bg-success-soft'"
                                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition cursor-pointer">
                            <i data-lucide="circle-check" class="size-3.5" aria-hidden="true"></i>
                            Lengkap ({{ $classStats['complete'] ?? 0 }})
                        </button>
                        <button type="button" @click="statusFilter = 'arsip'"
                                :class="statusFilter === 'arsip' ? 'bg-primary text-white' : 'bg-page text-muted hover:text-ink hover:bg-border'"
                                class="rounded-full px-3 py-1 text-xs font-semibold transition cursor-pointer">
                            Arsip ({{ $classStats['inactive'] ?? 0 }})
                        </button>
                    </div>

                    {{-- Form Pencarian & Filter Prodi/Angkatan --}}
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <div class="relative flex-1 min-w-[200px]">
                            <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true"></i>
                            <input id="class-search" type="search" x-model="search" data-table-search
                                   autocorrect="off" autocapitalize="none" spellcheck="false"
                                   aria-label="Cari Nama Kelas, Kode, atau Program Studi"
                                   placeholder="Cari nama kelas, kode, atau prodi..."
                                   class="w-full rounded-control border border-border bg-surface pl-9 pr-8 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                            <button type="button" x-show="search.trim() !== ''" x-cloak @click="search = ''" aria-label="Bersihkan pencarian" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted hover:text-ink cursor-pointer" title="Bersihkan pencarian">
                                <i data-lucide="x" class="size-3.5" aria-hidden="true"></i>
                            </button>
                        </div>

                        <select id="class-prodi-filter" x-model="prodiFilter" aria-label="Filter Program Studi" class="w-full sm:w-56 rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                            <option value="">Semua Program Studi</option>
                            @foreach ($distinctPrograms ?? [] as $dp)
                                <option value="{{ $dp }}">{{ $dp }}</option>
                            @endforeach
                        </select>

                        <select id="class-cohort-filter" x-model="cohortFilter" aria-label="Filter Angkatan" class="w-full sm:w-44 rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                            <option value="">Semua Angkatan</option>
                            @foreach ($distinctCohorts ?? [] as $cohort)
                                <option value="{{ $cohort }}">Angkatan {{ $cohort }}</option>
                            @endforeach
                        </select>

                        <button type="button" @click="search = ''; prodiFilter = ''; cohortFilter = ''; statusFilter = 'semua'"
                                x-show="search !== '' || prodiFilter !== '' || cohortFilter !== '' || statusFilter !== 'semua'"
                                x-cloak
                                class="inline-flex h-[38px] shrink-0 items-center justify-center gap-1.5 rounded-control border border-border px-3 text-xs font-semibold text-muted hover:text-ink hover:bg-page transition cursor-pointer">
                            <i data-lucide="rotate-ccw" class="size-3.5" aria-hidden="true"></i>
                            <span>Reset</span>
                        </button>
                    </div>

                    {{-- Bar Bantuan Cepat saat Mode Edit Massal Aktif --}}
                    <x-bulk-edit-bar form="bulkClassForm" label="Set jumlah mahasiswa cepat:">
                        <input type="number" min="1" max="500" x-model="fillEmptyValue" aria-label="Jumlah mahasiswa cepat" class="w-16 rounded border border-border bg-surface px-2 py-1 text-center text-xs font-semibold text-ink focus:border-primary focus:outline-none">
                        <button type="button" @click="applyFillEmpty()" class="cursor-pointer rounded border border-primary/40 bg-surface px-2.5 py-1 text-xs font-semibold text-primary shadow-xs transition hover:bg-primary hover:text-white">
                            Isi yang Kosong
                        </button>
                        <button type="button" @click="applyFillAll()" class="cursor-pointer rounded border border-primary/40 bg-primary/15 px-2.5 py-1 text-xs font-semibold text-primary shadow-xs transition hover:bg-primary hover:text-white"
                                x-text="selectedIds.length > 0 ? 'Terapkan ke ' + selectedIds.length + ' Kelas Terpilih' : 'Terapkan ke Semua Tampil (' + visibleIds.length + ')'">
                        </button>
                    </x-bulk-edit-bar>

                    {{-- TABEL KELAS --}}
                    <x-table-scroll>
                        <x-slot:title>Menampilkan <strong class="text-ink" x-text="visibleIds.length"></strong> dari {{ $classGroups->count() }} kelas</x-slot:title>
                        <x-slot:headerActions>
                            <x-table-density-toggle />
                            <button type="button" @click="toggleEditMode()"
                                    :class="editMode ? 'bg-amber-500 text-white border-amber-500' : 'bg-surface text-ink border-border hover:bg-primary-soft hover:text-primary'"
                                    class="inline-flex items-center gap-1.5 rounded-control border px-2.5 py-1 text-xs font-semibold transition cursor-pointer">
                                <i data-lucide="pencil" class="size-3.5" aria-hidden="true"></i>
                                <span x-text="editMode ? 'Tutup Edit Massal' : 'Edit Massal'"></span>
                            </button>
                        </x-slot:headerActions>
                        <table class="w-full border-collapse text-left text-sm">
                            <thead class="sticky top-0 z-10 border-b border-border bg-page text-xs font-semibold text-muted">
                                <tr>
                                    <th scope="col" class="w-10 px-4 py-3 text-center">
                                        <input type="checkbox"
                                                @change="toggleSelectAll($event.target.checked)"
                                                :checked="isAllSelected()"
                                                class="size-4 rounded border-border text-primary focus:ring-primary cursor-pointer"
                                                aria-label="Pilih semua kelas yang tampil"
                                                title="Pilih semua yang tampil">
                                    </th>
                                    <th scope="col" class="px-4 py-3 font-semibold">Nama Kelas & Kode</th>
                                    <th scope="col" class="px-4 py-3 font-semibold">Program Studi</th>
                                    <th scope="col" class="px-4 py-3 font-semibold text-center">Angkatan</th>
                                    <th scope="col" class="px-4 py-3 font-semibold text-center">Jadwal</th>
                                    <th scope="col" class="px-4 py-3 font-semibold text-center">Jumlah Mahasiswa</th>
                                    <th scope="col" class="px-4 py-3 font-semibold text-center">Status</th>
                                    <th scope="col" class="px-4 py-3 text-right font-semibold">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @forelse ($classGroups as $index => $classGroup)
                                    @php
                                        $searchStr = mb_strtolower($classGroup->name.' '.$classGroup->study_program.' '.$classGroup->cohort_year.' '.$classGroup->code);
                                        $needsAttention = $classGroup->student_count === null;
                                        $statusKey = ! $classGroup->is_active ? 'arsip' : ($needsAttention ? 'perlu_dilengkapi' : 'lengkap');
                                    @endphp
                                    <tr data-id="{{ $classGroup->id }}"
                                        data-search="{{ $searchStr }}"
                                        data-status="{{ $statusKey }}"
                                        data-prodi="{{ $classGroup->study_program }}"
                                        data-cohort="{{ $classGroup->cohort_year }}"
                                        x-show="isRowVisible({{ $classGroup->id }})"
                                        class="border-b border-border transition hover:bg-primary-soft/20"
                                        :class="{ 'bg-primary-soft/30': selectedIds.map(Number).includes({{ $classGroup->id }}) }">

                                        {{-- Checkbox --}}
                                        <td class="px-4 py-3 text-center">
                                            <input type="checkbox"
                                                   value="{{ $classGroup->id }}"
                                                   x-model.number="selectedIds"
                                                   aria-label="Pilih kelas {{ $classGroup->name }}"
                                                   class="size-4 rounded border-border text-primary focus:ring-primary cursor-pointer">
                                        </td>

                                        {{-- Nama Kelas & Kode --}}
                                        <td class="px-4 py-3">
                                            <div x-show="!editMode" class="flex flex-col">
                                                <span class="font-semibold text-ink">{{ $classGroup->name }}</span>
                                                <span class="font-mono text-[11px] text-muted">{{ $classGroup->code }}</span>
                                            </div>
                                            <div x-show="editMode" x-cloak class="flex flex-col gap-1">
                                                <input type="text"
                                                       form="bulkClassForm"
                                                       name="classes[{{ $index }}][name]"
                                                       value="{{ $classGroup->name }}"
                                                       :disabled="!editMode"
                                                       x-model="classesData[{{ $classGroup->id }}].name"
                                                       required
                                                       class="w-36 rounded-control border border-border bg-surface px-2.5 py-1 text-sm font-semibold text-ink focus:border-primary focus:outline-none">
                                                <span class="font-mono text-[10px] text-muted">{{ $classGroup->code }}</span>
                                            </div>
                                        </td>

                                        {{-- Program Studi --}}
                                        <td class="px-4 py-3">
                                            <span x-show="!editMode" class="text-xs text-muted">{{ $classGroup->study_program }}</span>
                                            <div x-show="editMode" x-cloak>
                                                <input type="text"
                                                       form="bulkClassForm"
                                                       name="classes[{{ $index }}][study_program]"
                                                       value="{{ $classGroup->study_program }}"
                                                       :disabled="!editMode"
                                                       x-model="classesData[{{ $classGroup->id }}].study_program"
                                                       list="prodi-options-grid"
                                                       required
                                                       class="w-48 rounded-control border border-border bg-surface px-2.5 py-1 text-xs text-ink focus:border-primary focus:outline-none">
                                            </div>
                                        </td>

                                        {{-- Angkatan --}}
                                        <td class="px-4 py-3 text-center">
                                            <span x-show="!editMode" class="text-sm font-medium text-ink">{{ $classGroup->cohort_year }}</span>
                                            <div x-show="editMode" x-cloak class="flex justify-center">
                                                <input type="number" min="2000" max="2100"
                                                       form="bulkClassForm"
                                                       name="classes[{{ $index }}][cohort_year]"
                                                       value="{{ $classGroup->cohort_year }}"
                                                       :disabled="!editMode"
                                                       x-model="classesData[{{ $classGroup->id }}].cohort_year"
                                                       required
                                                       class="w-20 rounded-control border border-border bg-surface px-2 py-1 text-center text-sm text-ink focus:border-primary focus:outline-none">
                                            </div>
                                        </td>

                                        {{-- Sesi Jadwal --}}
                                        <td class="px-4 py-3 text-center">
                                            <span class="inline-flex items-center rounded-md bg-page px-2 py-0.5 text-xs font-medium text-muted ring-1 ring-border">
                                                {{ $classGroup->schedule_sessions_count }} sesi
                                            </span>
                                        </td>

                                        {{-- Jumlah Mahasiswa (Peserta) --}}
                                        <td class="px-4 py-3 text-center">
                                            <div x-show="!editMode">
                                                @if ($classGroup->student_count)
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-page px-2.5 py-0.5 text-xs font-semibold text-ink border border-border">
                                                        <i data-lucide="users" class="size-3 text-muted"></i>
                                                        {{ $classGroup->student_count }} mhs
                                                    </span>
                                                @else
                                                    <button type="button" @click="toggleEditMode()" class="inline-flex items-center gap-1 text-xs font-semibold text-danger hover:underline cursor-pointer" title="Klik untuk edit cepat">
                                                        <i data-lucide="triangle-alert" class="size-3.5" aria-hidden="true"></i>
                                                        <span>Belum diisi</span>
                                                    </button>
                                                @endif
                                            </div>
                                            <div x-show="editMode" x-cloak class="flex justify-center">
                                                <input type="number" min="1" max="500"
                                                       form="bulkClassForm"
                                                       name="classes[{{ $index }}][student_count]"
                                                       value="{{ $classGroup->student_count }}"
                                                       :disabled="!editMode"
                                                       x-model="classesData[{{ $classGroup->id }}].student_count"
                                                       placeholder="0 mhs"
                                                       class="w-24 rounded-control border border-primary/40 bg-surface px-2.5 py-1 text-center text-sm font-bold text-ink focus:border-primary focus:ring-1 focus:ring-primary focus:outline-none shadow-xs">
                                            </div>
                                        </td>

                                        {{-- Status Data / Aktif --}}
                                        <td class="px-4 py-3 text-center">
                                            <div x-show="!editMode">
                                                @if (! $classGroup->is_active)
                                                    <x-status-badge>Arsip</x-status-badge>
                                                @elseif ($classGroup->student_count === null)
                                                    <x-status-badge variant="warning">Perlu dilengkapi</x-status-badge>
                                                @else
                                                    <x-status-badge variant="success">Lengkap</x-status-badge>
                                                @endif
                                            </div>
                                            <div x-show="editMode" x-cloak class="flex items-center justify-center gap-1.5">
                                                <input type="hidden" form="bulkClassForm" name="classes[{{ $index }}][id]" value="{{ $classGroup->id }}" :disabled="!editMode">
                                                <input type="hidden" form="bulkClassForm" name="classes[{{ $index }}][is_active]" value="0" :disabled="!editMode">
                                                <label class="inline-flex items-center gap-1 text-xs cursor-pointer">
                                                    <input type="checkbox"
                                                           form="bulkClassForm"
                                                           name="classes[{{ $index }}][is_active]"
                                                           value="1"
                                                           @checked($classGroup->is_active)
                                                           :disabled="!editMode"
                                                           x-model="classesData[{{ $classGroup->id }}].is_active"
                                                           class="size-4 accent-primary rounded cursor-pointer">
                                                    <span class="text-[11px]" x-text="classesData[{{ $classGroup->id }}].is_active ? 'Aktif' : 'Nonaktif'"></span>
                                                </label>
                                            </div>
                                        </td>

                                        {{-- Aksi --}}
                                        <td class="px-4 py-3 text-right">
                                            <div class="flex items-center justify-end gap-1.5">
                                                <button type="button"
                                                        class="inline-flex items-center gap-1 rounded-control border border-border bg-surface px-2.5 py-1 text-xs font-semibold text-primary hover:bg-primary-soft hover:border-primary/40 transition cursor-pointer"
                                                        @click="$dispatch('class-open-form', @js([
                                                            'id' => $classGroup->id,
                                                            'name' => $classGroup->name,
                                                            'code' => $classGroup->code,
                                                            'study_program' => $classGroup->study_program,
                                                            'cohort_year' => $classGroup->cohort_year,
                                                            'student_count' => $classGroup->student_count,
                                                            'is_active' => (bool) $classGroup->is_active,
                                                        ]))">
                                                    <i data-lucide="pencil" class="size-3.5" aria-hidden="true"></i>
                                                    <span>Edit</span>
                                                </button>
                                                <button type="button"
                                                        @click="confirmDeleteClass('{{ route('admin.academic.classes.destroy', $classGroup) }}', @js($classGroup->name))"
                                                        class="inline-flex items-center gap-1 rounded-control border border-danger/30 bg-danger-soft/30 px-2.5 py-1 text-xs font-semibold text-danger hover:bg-danger hover:text-white transition cursor-pointer">
                                                    <i data-lucide="trash-2" class="size-3.5" aria-hidden="true"></i>
                                                    <span>Hapus</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="py-12 text-center text-muted">
                                            <i data-lucide="users" class="mx-auto size-8 text-muted/60 mb-2" aria-hidden="true"></i>
                                            <p class="font-medium text-ink">Belum ada data kelas.</p>
                                        </td>
                                    </tr>
                                @endforelse
                                @if ($classGroups->isNotEmpty())
                                    <tr x-show="visibleIds.length === 0" x-cloak>
                                        <td colspan="8" class="py-12 text-center text-muted">
                                            <i data-lucide="search-x" class="mx-auto size-8 text-muted/60 mb-2" aria-hidden="true"></i>
                                            <p class="font-medium text-ink">Tidak ada kelas yang cocok dengan filter atau pencarian.</p>
                                            <button type="button" @click="search = ''; prodiFilter = ''; cohortFilter = ''; statusFilter = 'semua'"
                                                    class="mt-3 inline-flex items-center gap-1.5 rounded-control border border-border bg-surface px-3 py-1.5 text-xs font-semibold text-primary hover:bg-primary-soft transition cursor-pointer">
                                                <i data-lucide="rotate-ccw" class="size-3.5" aria-hidden="true"></i>
                                                <span>Reset Filter</span>
                                            </button>
                                        </td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </x-table-scroll>

                    {{-- Footer jika dalam editMode --}}
                    <div x-show="editMode" x-cloak class="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
                        <span class="text-xs text-muted">Simpan setelah selesai mengubah data kelas di atas.</span>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" @click="cancelEditMode()" class="rounded-control border border-border bg-surface px-4 py-2 text-xs font-semibold text-muted hover:text-ink transition cursor-pointer">
                                Batal
                            </button>
                            <button type="submit" form="bulkClassForm" class="inline-flex items-center gap-1.5 rounded-control bg-primary px-5 py-2 text-xs font-bold text-white hover:bg-primary/90 transition shadow-sm cursor-pointer">
                                <i data-lucide="check" class="size-4" aria-hidden="true"></i>
                                Simpan Semua Perubahan
                            </button>
                        </div>
                    </div>
                </x-card>

                {{-- FLOATING BATCH ACTION BAR --}}
                <x-batch-action-bar unit="kelas" show="selectedIds.length > 0 && !editMode">
                    {{-- 1. Atur Mahasiswa Modal --}}
                    <button type="button" @click="openBatchModal('set_student_count', 'Atur Jumlah Mahasiswa')" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white shadow-xs transition hover:bg-primary/90">
                        <i data-lucide="users" class="size-3.5" aria-hidden="true"></i>
                        Set Mahasiswa
                    </button>

                    {{-- 2. Set Angkatan Modal --}}
                    <button type="button" @click="openBatchModal('set_cohort_year', 'Set Tahun Angkatan')" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface transition hover:bg-surface/20">
                        <i data-lucide="calendar" class="size-3.5" aria-hidden="true"></i>
                        Set Angkatan
                    </button>

                    {{-- 3. Set Prodi Modal --}}
                    <button type="button" @click="openBatchModal('set_study_program', 'Set Program Studi')" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface transition hover:bg-surface/20">
                        <i data-lucide="building-2" class="size-3.5" aria-hidden="true"></i>
                        Set Prodi
                    </button>

                    {{-- 4. Aktifkan --}}
                    <button type="button" @click="submitBatch('activate')" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-success px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-success/90">
                        <i data-lucide="check" class="size-3.5" aria-hidden="true"></i>
                        Aktifkan
                    </button>

                    {{-- 5. Arsipkan --}}
                    <button type="button" @click="archiveConfirmModalOpen = true" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-danger/85 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-danger">
                        <i data-lucide="lock" class="size-3.5" aria-hidden="true"></i>
                        Arsipkan
                    </button>
                </x-batch-action-bar>

                {{-- FORM TERSEMBUNYI UNTUK BATCH ACTION --}}
                <form x-ref="batchForm" method="POST" action="{{ route('admin.academic.classes.batch') }}" class="hidden">
                    @csrf
                    <input type="hidden" name="form" value="batch_classes">
                    <input type="hidden" name="action" x-model="batchAction">
                    <input type="hidden" name="student_count" x-model="batchValue" :disabled="batchAction !== 'set_student_count'">
                    <input type="hidden" name="cohort_year" x-model="batchValue" :disabled="batchAction !== 'set_cohort_year'">
                    <input type="hidden" name="study_program" x-model="batchValue" :disabled="batchAction !== 'set_study_program'">
                    <template x-for="id in selectedIds" :key="id">
                        <input type="hidden" name="class_ids[]" :value="id">
                    </template>
                </form>

                {{-- FORM TERSEMBUNYI UNTUK BULK EDIT GRID --}}
                <form id="bulkClassForm" method="POST" action="{{ route('admin.academic.classes.bulk-update') }}" class="hidden">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="form" value="bulk_classes">
                </form>

                @include('admin.academic.modals.class-batch-modals')

                {{-- Datalist options untuk prodi --}}
                <datalist id="prodi-options-grid">
                    @foreach ($distinctPrograms ?? [] as $dp)
                        <option value="{{ $dp }}"></option>
                    @endforeach
                </datalist>
                <datalist id="prodi-options-batch">
                    @foreach ($distinctPrograms ?? [] as $dp)
                        <option value="{{ $dp }}"></option>
                    @endforeach
                </datalist>
                </div>
            </div>
