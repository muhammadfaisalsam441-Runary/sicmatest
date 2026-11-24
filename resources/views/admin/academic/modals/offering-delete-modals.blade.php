{{-- MODAL KONFIRMASI HAPUS SATUAN --}}
<div x-show="deleteModalOpen" x-cloak role="dialog" aria-modal="true" aria-labelledby="delete-offering-modal-title" class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="deleteModalOpen = false"></div>
    <div class="relative max-h-[90dvh] w-full max-w-md overflow-y-auto rounded-2xl bg-surface p-6 shadow-2xl">
        <div class="flex items-start justify-between border-b border-border pb-3">
            <h3 id="delete-offering-modal-title" class="text-lg font-bold text-ink">Hapus Penawaran Kuliah?</h3>
            <button type="button" @click="deleteModalOpen = false" aria-label="Tutup dialog" class="inline-flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-control text-muted transition hover:bg-slate-100 hover:text-ink">
                <i data-lucide="x" class="size-5" aria-hidden="true"></i>
            </button>
        </div>
        <p class="mt-3 text-xs text-muted">
            Apakah Anda yakin ingin menghapus penawaran <strong class="text-ink" x-text="offeringToDelete.name"></strong>?
            Pembagian SKS dosen pada penawaran ini juga akan ikut dihapus.
        </p>
        <form method="POST" :action="offeringToDelete.deleteUrl" class="mt-5 flex flex-wrap justify-end gap-2">
            @csrf
            @method('DELETE')
            <x-button variant="secondary" type="button" @click="deleteModalOpen = false">Batal</x-button>
            <x-button variant="danger" type="submit" icon="trash-2">Hapus Penawaran</x-button>
        </form>
    </div>
</div>

{{-- MODAL KONFIRMASI HAPUS BATCH --}}
<div x-show="batchDeleteModalOpen" x-cloak role="dialog" aria-modal="true" aria-labelledby="batch-delete-offering-modal-title" class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="batchDeleteModalOpen = false"></div>
    <div class="relative max-h-[90dvh] w-full max-w-md overflow-y-auto rounded-2xl bg-surface p-6 shadow-2xl">
        <div class="flex items-start justify-between border-b border-border pb-3">
            <div>
                <span class="text-xs font-semibold text-danger">Penyesuaian Massal</span>
                <h3 id="batch-delete-offering-modal-title" class="text-lg font-bold text-ink">Hapus (<span x-text="selectedIds.length"></span> Penawaran Terpilih)?</h3>
            </div>
            <button type="button" @click="batchDeleteModalOpen = false" aria-label="Tutup dialog" class="inline-flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-control text-muted transition hover:bg-slate-100 hover:text-ink">
                <i data-lucide="x" class="size-5" aria-hidden="true"></i>
            </button>
        </div>
        <div class="mt-4 rounded-lg border border-danger/20 bg-danger-soft/40 p-3 text-xs text-ink">
            Seluruh <strong x-text="selectedIds.length"></strong> penawaran yang sedang dicentang akan dihapus beserta pembagian SKS dosennya. Penawaran yang telah memiliki sesi jadwal di draf otomatis dilindungi dan tidak akan dihapus.
        </div>
        <form method="POST" action="{{ route('admin.academic.offerings.batch-delete') }}" class="mt-5">
            @csrf
            <template x-for="id in selectedIds" :key="id">
                <input type="hidden" name="offering_ids[]" :value="id">
            </template>
            <div class="flex flex-wrap justify-end gap-2">
                <x-button variant="secondary" type="button" @click="batchDeleteModalOpen = false">Batal</x-button>
                <x-button variant="danger" type="submit" icon="trash-2">Terapkan Hapus ke Penawaran Terpilih</x-button>
            </div>
        </form>
    </div>
</div>
