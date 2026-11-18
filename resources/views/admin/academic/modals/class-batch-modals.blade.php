{{-- MODAL BATCH ATUR NILAI --}}
<div x-show="batchModalOpen" x-cloak role="dialog" aria-modal="true" aria-labelledby="batch-class-modal-title" class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="batchModalOpen = false"></div>
    <div class="relative w-full max-w-md rounded-2xl bg-surface p-6 shadow-2xl">
        <div class="flex items-start justify-between border-b border-border pb-3">
            <div>
                <span class="text-xs font-semibold text-primary">Penyesuaian Massal</span>
                <h3 id="batch-class-modal-title" class="text-lg font-bold text-ink" x-text="batchLabel + ' (' + selectedIds.length + ' Kelas Terpilih)'"></h3>
            </div>
            <button type="button" @click="batchModalOpen = false" aria-label="Tutup dialog" class="cursor-pointer rounded-md p-1 text-muted hover:bg-slate-100 hover:text-ink">
                <i data-lucide="x" class="size-5" aria-hidden="true"></i>
            </button>
        </div>

        <div class="mt-4 flex flex-col gap-4">
            <template x-if="batchAction === 'set_student_count'">
                <div>
                    <label for="batch-class-student-count" class="mb-1 block text-xs font-medium text-muted">Jumlah Mahasiswa per Kelas</label>
                    <input id="batch-class-student-count" type="number" min="1" max="500" x-model="batchValue" @keydown.enter.prevent="if (batchValue) { submitBatch(); batchModalOpen = false; }" placeholder="Misal: 40" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                </div>
            </template>

            <template x-if="batchAction === 'set_cohort_year'">
                <div>
                    <label for="batch-class-cohort-year" class="mb-1 block text-xs font-medium text-muted">Tahun Angkatan</label>
                    <input id="batch-class-cohort-year" type="number" min="2000" max="2100" x-model="batchValue" @keydown.enter.prevent="if (batchValue) { submitBatch(); batchModalOpen = false; }" placeholder="Misal: 2026" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                </div>
            </template>

            <template x-if="batchAction === 'set_study_program'">
                <div>
                    <label for="batch-class-study-program" class="mb-1 block text-xs font-medium text-muted">Nama Program Studi</label>
                    <input id="batch-class-study-program" type="text" x-model="batchValue" @keydown.enter.prevent="if (String(batchValue).trim() !== '') { submitBatch(); batchModalOpen = false; }" list="prodi-options-batch" placeholder="Misal: Pendidikan Teknik Informatika dan Komputer" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                </div>
            </template>

            <div class="rounded-lg border border-primary/20 bg-primary-soft/40 p-3 text-xs text-ink">
                Perubahan ini akan diterapkan serentak ke <strong x-text="selectedIds.length"></strong> kelas yang sedang dicentang.
            </div>

            <div class="mt-1 flex items-center justify-end gap-2 border-t border-border pt-4">
                <x-button variant="secondary" type="button" @click="batchModalOpen = false">Batal</x-button>
                <x-button variant="primary" type="button" icon="check"
                          x-bind:disabled="String(batchValue ?? '').trim() === ''"
                          @click="if (String(batchValue ?? '').trim() !== '') { submitBatch(); batchModalOpen = false; }">
                    Terapkan ke Kelas Terpilih
                </x-button>
            </div>
        </div>
    </div>
</div>

{{-- MODAL KONFIRMASI ARSIP BATCH --}}
<div x-show="archiveConfirmModalOpen" x-cloak role="dialog" aria-modal="true" aria-labelledby="archive-class-modal-title" class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="archiveConfirmModalOpen = false"></div>
    <div class="relative w-full max-w-md rounded-2xl bg-surface p-6 shadow-2xl">
        <div class="flex items-start justify-between border-b border-border pb-3">
            <h3 id="archive-class-modal-title" class="text-base font-bold text-ink">Arsipkan kelas terpilih?</h3>
            <button type="button" @click="archiveConfirmModalOpen = false" aria-label="Tutup dialog" class="cursor-pointer rounded-md p-1 text-muted hover:bg-slate-100 hover:text-ink">
                <i data-lucide="x" class="size-4" aria-hidden="true"></i>
            </button>
        </div>
        <p class="mt-3 text-sm text-muted">
            Sebanyak <strong class="text-ink" x-text="selectedIds.length"></strong> kelas terpilih akan dinonaktifkan (diarsipkan) dan tidak ikut dalam penjadwalan aktif.
        </p>
        <div class="mt-5 flex justify-end gap-2">
            <x-button type="button" variant="ghost" @click="archiveConfirmModalOpen = false">Batal</x-button>
            <x-button type="button" variant="danger" icon="lock" @click="submitBatch('archive'); archiveConfirmModalOpen = false">Arsipkan</x-button>
        </div>
    </div>
</div>

{{-- MODAL KONFIRMASI HAPUS KELAS --}}
<div x-show="deleteClassModalOpen" x-cloak role="dialog" aria-modal="true" aria-labelledby="delete-class-modal-title" class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="deleteClassModalOpen = false"></div>
    <div class="relative w-full max-w-md rounded-2xl bg-surface p-6 shadow-2xl">
        <div class="flex items-start justify-between border-b border-border pb-3">
            <h3 id="delete-class-modal-title" class="text-base font-bold text-ink">Hapus kelas?</h3>
            <button type="button" @click="deleteClassModalOpen = false" aria-label="Tutup dialog" class="cursor-pointer rounded-md p-1 text-muted hover:bg-slate-100 hover:text-ink">
                <i data-lucide="x" class="size-4" aria-hidden="true"></i>
            </button>
        </div>
        <p class="mt-3 text-sm text-muted">
            Kelas <strong class="text-ink" x-text="deleteClassName"></strong> akan dihapus permanen jika belum memiliki data terkait.
        </p>
        <form method="POST" :action="deleteClassUrl" class="mt-5 flex justify-end gap-2">
            @csrf
            @method('DELETE')
            <x-button type="button" variant="ghost" @click="deleteClassModalOpen = false">Batal</x-button>
            <x-button type="submit" variant="danger" icon="trash-2">Hapus Kelas</x-button>
        </form>
    </div>
</div>
