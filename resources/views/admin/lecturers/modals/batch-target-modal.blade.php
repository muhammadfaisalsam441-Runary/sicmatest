{{-- MODAL 2: BATCH ATUR TARGET SKS --}}
<div x-show="batchTargetModalOpen" x-cloak data-modal-name="lecturer-batch-target"
     role="dialog" aria-modal="true" aria-labelledby="lecturer-batch-title"
     @keydown.escape.window="if (batchTargetModalOpen) batchTargetModalOpen = false"
     class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="batchTargetModalOpen = false"></div>
    <div class="relative w-full max-w-md rounded-2xl bg-surface p-6 shadow-2xl">
        <div class="flex items-start justify-between border-b border-border pb-3">
            <div>
                <span class="text-xs font-semibold text-primary">Penyesuaian Massal</span>
                <h3 id="lecturer-batch-title" class="text-lg font-bold text-ink">Atur Target SKS (<span x-text="selectedIds.length"></span> Dosen Terpilih)</h3>
            </div>
            <button type="button" @click="batchTargetModalOpen = false" aria-label="Tutup modal target SKS" class="cursor-pointer text-muted hover:text-ink">
                <i data-lucide="x" class="size-5" aria-hidden="true"></i>
            </button>
        </div>

        <form method="POST" action="{{ route('admin.lecturers.batch') }}" class="mt-4 flex flex-col gap-4">
            @csrf
            <input type="hidden" name="form" value="batch_target">
            <input type="hidden" name="action" value="set_target_sks">
            <template x-for="id in selectedIds" :key="id">
                <input type="hidden" name="lecturer_ids[]" :value="id">
            </template>

            @if ($errors->any() && $oldForm === 'batch_target')
                <x-alert variant="danger">{{ $errors->first() }}</x-alert>
            @endif

            <div class="rounded-lg border border-primary/20 bg-primary-soft/40 p-3 text-xs text-ink">
                Target dan batas maksimum SKS ini akan diterapkan serentak ke <strong x-text="selectedIds.length"></strong> dosen yang sedang dicentang.
            </div>

            <div>
                <label for="batch-target-sks" class="mb-1 block text-xs font-medium text-muted">Target SKS untuk Seluruh Dosen Terpilih</label>
                <input id="batch-target-sks" type="number" step="1" min="0" name="target_sks" value="{{ $oldForm === 'batch_target' ? old('target_sks') : '' }}" placeholder="Misal: 12" required class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                @if ($oldForm === 'batch_target') @error('target_sks') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
            </div>

            <div>
                <label for="batch-max-sks" class="mb-1 block text-xs font-medium text-muted">Batas Maksimum SKS</label>
                <input id="batch-max-sks" type="number" step="1" min="0" name="max_sks" value="{{ $oldForm === 'batch_target' ? old('max_sks') : '' }}" placeholder="Misal: 16" required class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                @if ($oldForm === 'batch_target') @error('max_sks') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
            </div>

            <div class="mt-2 flex items-center justify-end gap-2 border-t border-border pt-4">
                <x-button variant="secondary" type="button" @click="batchTargetModalOpen = false">Batal</x-button>
                <x-button variant="primary" type="submit" icon="check">Terapkan ke Dosen Terpilih</x-button>
            </div>
        </form>
    </div>
</div>
