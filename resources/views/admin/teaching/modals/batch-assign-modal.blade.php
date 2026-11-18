{{-- MODAL 2: BATCH ASSIGN MASSAL --}}
<div x-show="batchModalOpen" x-cloak data-modal-name="batch-assign-modal"
     role="dialog" aria-modal="true" aria-labelledby="batch-assign-modal-title"
     class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="batchModalOpen = false"></div>
    <div class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-surface p-6 shadow-2xl">
        <div class="flex items-start justify-between border-b border-border pb-3">
            <div>
                <span class="text-xs font-semibold text-primary">Penyesuaian Massal</span>
                <h3 id="batch-assign-modal-title" class="text-lg font-bold text-ink">Tugaskan Dosen (<span class="text-primary" x-text="selectedIds.length"></span> Kelas Terpilih)</h3>
            </div>
            <button type="button" @click="batchModalOpen = false" aria-label="Tutup" class="cursor-pointer rounded p-1 text-muted hover:text-ink">
                <i data-lucide="x" class="size-5" aria-hidden="true"></i>
            </button>
        </div>

        <form method="POST" action="{{ route('admin.teaching.batch') }}" class="mt-4 flex flex-col gap-4">
            @csrf
            <template x-for="id in selectedIds" :key="id">
                <input type="hidden" name="offering_ids[]" :value="id">
            </template>

            <div class="rounded-lg border border-primary/20 bg-primary-soft/40 p-3 text-xs text-ink">
                Dosen dan peran yang dipilih di bawah ini akan diterapkan serentak ke <strong x-text="selectedIds.length"></strong> kelas penawaran yang sedang dicentang.
            </div>

            <div>
                <label for="batch-lecturer-id" class="mb-1 block text-xs font-medium text-muted">Pilih Dosen <span class="text-danger">*</span></label>
                <select id="batch-lecturer-id" name="lecturer_id" required class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                    <option value="">-- Pilih Dosen Terverifikasi --</option>
                    @foreach ($lecturerOptionsEnriched as $lec)
                        <option value="{{ $lec['id'] }}">
                            {{ $lec['name'] }} — {{ $lec['sks_info'] }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label for="batch-role" class="mb-1 block text-xs font-medium text-muted">Peran Dosen</label>
                    <select id="batch-role" name="role" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        <option value="lead">Pengampu (Utama)</option>
                        <option value="partner">Mitra (Pendamping)</option>
                    </select>
                </div>
                <div>
                    <label for="batch-phase" class="mb-1 block text-xs font-medium text-muted">Periode Mengajar</label>
                    <select id="batch-phase" name="phase" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        <option value="full">Satu semester penuh</option>
                        <option value="before_uts">Pertemuan 1–7 (sampai UTS)</option>
                        <option value="after_uts">Pertemuan 8–14 (sampai UAS)</option>
                    </select>
                </div>
            </div>

            <div class="rounded-lg bg-page p-3 text-xs text-muted">
                <i data-lucide="info" class="mr-1 inline size-4 text-primary" aria-hidden="true"></i>
                Jika memilih peran <strong>Pengampu (Utama)</strong>, pengampu lama yang tidak dikunci pada kelas terpilih akan diperbarui. Penugasan yang sudah dikunci tidak akan diubah.
            </div>

            <div class="mt-2 flex items-center justify-end gap-2 border-t border-border pt-4">
                <x-button variant="secondary" type="button" @click="batchModalOpen = false">Batal</x-button>
                <x-button variant="primary" type="submit" icon="check">Terapkan ke Kelas Terpilih</x-button>
            </div>
        </form>
    </div>
</div>
