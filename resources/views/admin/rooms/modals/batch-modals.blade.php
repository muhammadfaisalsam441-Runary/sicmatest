{{-- MODAL 3: KONFIRMASI HAPUS DINAMIS TUNGGAL --}}
<div x-show="deleteModalOpen" x-cloak data-modal-name="room-delete"
     role="dialog" aria-modal="true" aria-labelledby="room-delete-title"
     @keydown.escape.window="if (deleteModalOpen) deleteModalOpen = false"
     class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="deleteModalOpen = false"></div>
    <div class="relative w-full max-w-md rounded-2xl bg-surface p-6 shadow-2xl">
        <div class="flex items-start justify-between border-b border-border pb-3">
            <h3 id="room-delete-title" class="text-lg font-bold text-ink">Hapus ruang <span x-text="roomToDelete.name"></span>?</h3>
            <button type="button" @click="deleteModalOpen = false" aria-label="Tutup konfirmasi hapus ruang" class="cursor-pointer text-muted hover:text-ink">
                <i data-lucide="x" class="size-5" aria-hidden="true"></i>
            </button>
        </div>
        <p class="mt-3 text-xs text-muted">Ruang ini akan hilang dari daftar. Ruang yang masih dipakai reservasi aktif atau jadwal resmi tidak dapat dihapus.</p>

        <form method="POST" :action="@js(route('admin.rooms.destroy', '__ROOM_ID__')).replace('__ROOM_ID__', roomToDelete.id)" class="mt-4 flex flex-col gap-4">
            @csrf
            @method('DELETE')
            <div>
                <label for="room-delete-reason" class="mb-1 block text-xs font-medium text-muted">Alasan Penghapusan (Opsional)</label>
                <input id="room-delete-reason" type="text" name="reason" placeholder="Contoh: Ruang dialihfungsikan" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
            </div>

            <div class="mt-2 flex items-center justify-end gap-2 border-t border-border pt-4">
                <x-button variant="secondary" type="button" @click="deleteModalOpen = false">Batal</x-button>
                <x-button variant="danger" type="submit" icon="trash-2">Hapus Ruang</x-button>
            </div>
        </form>
    </div>
</div>

{{-- MODAL 4: BATCH ATUR KAPASITAS --}}
<div x-show="batchCapacityModalOpen" x-cloak data-modal-name="room-batch-capacity" role="dialog" aria-modal="true" aria-labelledby="batch-capacity-title"
     @keydown.escape.window="if (batchCapacityModalOpen) batchCapacityModalOpen = false" class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="batchCapacityModalOpen = false"></div>
    <div class="relative w-full max-w-md rounded-2xl bg-surface p-6 shadow-2xl">
        <div class="flex items-start justify-between border-b border-border pb-3">
            <div>
                <span class="text-xs font-semibold text-primary">Penyesuaian Massal</span>
                <h3 id="batch-capacity-title" class="text-lg font-bold text-ink">Atur Kapasitas (<span x-text="selectedIds.length"></span> Ruang Terpilih)</h3>
            </div>
            <button type="button" @click="batchCapacityModalOpen = false" aria-label="Tutup pengaturan kapasitas" class="cursor-pointer text-muted hover:text-ink">
                <i data-lucide="x" class="size-5" aria-hidden="true"></i>
            </button>
        </div>

        <form method="POST" action="{{ route('admin.rooms.batch') }}" class="mt-4 flex flex-col gap-4">
            @csrf
            <input type="hidden" name="form" value="batch_capacity">
            <input type="hidden" name="action" value="set_capacity">
            <template x-for="id in selectedIds" :key="id">
                <input type="hidden" name="room_ids[]" :value="id">
            </template>

            <div>
                <label for="batch-capacity" class="mb-1 block text-xs font-medium text-muted">Kapasitas Kursi per Ruang (Orang)</label>
                <x-input name="capacity" id="batch-capacity" type="number" min="1" max="1000" step="1" required
                         x-ref="batchCapacityInput" x-model="batchCapacity" placeholder="Misal: 40" aria-describedby="batch-capacity-help" />
                @if (old('form') === 'batch_capacity')
                    @error('capacity')
                        <p id="capacity-error" class="mt-1 text-sm text-danger">{{ $message }}</p>
                    @enderror
                    @error('room_ids')
                        <p class="mt-1 text-sm text-danger">{{ $message }}</p>
                    @enderror
                @endif
            </div>

            <div id="batch-capacity-help" class="rounded-lg border border-primary/20 bg-primary-soft/40 p-3 text-xs text-ink">
                Kapasitas ini akan diterapkan serentak ke <strong x-text="selectedIds.length"></strong> ruangan yang sedang dicentang.
            </div>

            <div class="mt-1 flex flex-wrap items-center justify-end gap-2 border-t border-border pt-4">
                <x-button variant="secondary" type="button" @click="batchCapacityModalOpen = false">Batal</x-button>
                <x-button variant="primary" type="submit" icon="check" x-bind:disabled="selectedIds.length === 0">
                    Terapkan ke Ruang Terpilih
                </x-button>
            </div>
        </form>
    </div>
</div>

{{-- MODAL 5: BATCH TAMBAH FASILITAS --}}
<div x-show="batchFacilityModalOpen" x-cloak data-modal-name="room-batch-facility"
     role="dialog" aria-modal="true" aria-labelledby="batch-facility-title"
     @keydown.escape.window="if (batchFacilityModalOpen) batchFacilityModalOpen = false"
     class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="batchFacilityModalOpen = false"></div>
    <div class="relative w-full max-w-md rounded-2xl bg-surface p-6 shadow-2xl">
        <div class="flex items-start justify-between border-b border-border pb-3">
            <div>
                <span class="text-xs font-semibold text-primary">Penyesuaian Massal</span>
                <h3 id="batch-facility-title" class="text-lg font-bold text-ink">Tambah Fasilitas (<span x-text="selectedIds.length"></span> Ruang Terpilih)</h3>
            </div>
            <button type="button" @click="batchFacilityModalOpen = false" aria-label="Tutup modal tambah fasilitas" class="cursor-pointer text-muted hover:text-ink">
                <i data-lucide="x" class="size-5" aria-hidden="true"></i>
            </button>
        </div>

        <form method="POST" action="{{ route('admin.rooms.batch') }}" class="mt-4 flex flex-col gap-4">
            @csrf
            <input type="hidden" name="form" value="batch_facility">
            <input type="hidden" name="action" value="add_facilities">
            <template x-for="id in selectedIds" :key="id">
                <input type="hidden" name="room_ids[]" :value="id">
            </template>

            @if ($errors->any() && $oldForm === 'batch_facility')
                <x-alert variant="danger">{{ $errors->first() }}</x-alert>
            @endif

            <div>
                <label for="batch-facilities-input" class="mb-1 block text-xs font-medium text-muted">Fasilitas yang Ditambahkan (Pisahkan koma)</label>
                <input id="batch-facilities-input" type="text" name="facilities" value="{{ $oldForm === 'batch_facility' ? old('facilities') : '' }}" required placeholder="Contoh: AC, Proyektor, Papan tulis" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                @if ($oldForm === 'batch_facility')
                    @error('facilities') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror
                    @error('room_ids') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror
                @endif
            </div>

            <div class="rounded-lg border border-primary/20 bg-primary-soft/40 p-3 text-xs text-ink">
                Fasilitas baru akan ditambahkan ke <strong x-text="selectedIds.length"></strong> ruangan terpilih tanpa menghapus fasilitas yang sudah ada.
            </div>

            <div class="mt-1 flex items-center justify-end gap-2 border-t border-border pt-4">
                <x-button variant="secondary" type="button" @click="batchFacilityModalOpen = false">Batal</x-button>
                <x-button variant="primary" type="submit" icon="check" x-bind:disabled="selectedIds.length === 0">Terapkan ke Ruang Terpilih</x-button>
            </div>
        </form>
    </div>
</div>

{{-- MODAL 6: KONFIRMASI BUKA PENUTUPAN RUANG --}}
<div x-show="reopenModalOpen" x-cloak data-modal-name="room-reopen-closure"
     role="dialog" aria-modal="true" aria-labelledby="room-reopen-title"
     @keydown.escape.window="if (reopenModalOpen) reopenModalOpen = false"
     class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="reopenModalOpen = false"></div>
    <div class="relative w-full max-w-md rounded-2xl bg-surface p-6 shadow-2xl">
        <div class="flex items-start justify-between border-b border-border pb-3">
            <h3 id="room-reopen-title" class="text-lg font-bold text-ink">Buka kembali ruang <span x-text="closureToReopen.name"></span>?</h3>
            <button type="button" @click="reopenModalOpen = false" aria-label="Tutup konfirmasi buka ruang" class="cursor-pointer text-muted hover:text-ink">
                <i data-lucide="x" class="size-5" aria-hidden="true"></i>
            </button>
        </div>
        <p class="mt-3 text-xs text-muted">Penutupan ruang akan dihapus sehingga ruang kembali tersedia untuk pemesanan.</p>
        <form method="POST" :action="closureToReopen.url" class="mt-5 flex justify-end gap-2">
            @csrf
            @method('DELETE')
            <x-button variant="secondary" type="button" @click="reopenModalOpen = false">Batal</x-button>
            <x-button variant="primary" type="submit" icon="lock-open">Buka Ruang</x-button>
        </form>
    </div>
</div>
