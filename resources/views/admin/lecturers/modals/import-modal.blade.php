{{-- MODAL 3: IMPOR EXCEL / CSV --}}
<div x-show="importModalOpen" x-cloak data-modal-name="lecturer-import"
     role="dialog" aria-modal="true" aria-labelledby="lecturer-import-title"
     @keydown.escape.window="if (importModalOpen) importModalOpen = false"
     class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="importModalOpen = false"></div>
    <div class="relative w-full max-w-md rounded-2xl bg-surface p-6 shadow-2xl">
        <div class="flex items-start justify-between border-b border-border pb-3">
            <div>
                <span class="text-xs font-semibold text-primary">Impor Data</span>
                <h3 id="lecturer-import-title" class="text-lg font-bold text-ink">Impor Dosen dari Spreadsheet</h3>
            </div>
            <button type="button" @click="importModalOpen = false" aria-label="Tutup modal impor dosen" class="cursor-pointer text-muted hover:text-ink">
                <i data-lucide="x" class="size-5" aria-hidden="true"></i>
            </button>
        </div>

        <form method="POST" action="{{ route('admin.lecturers.import') }}" enctype="multipart/form-data" class="mt-4 flex flex-col gap-4">
            @csrf
            <input type="hidden" name="form" value="import">
            @if ($errors->any() && $oldForm === 'import')
                <x-alert variant="danger">{{ $errors->first() }}</x-alert>
            @endif
            <p class="text-xs text-muted">
                Format kolom file (Excel / CSV): <strong class="text-ink">Nama, NIP, NIDN, Homebase</strong> (JTIK / Luar JTIK), <strong class="text-ink">Pangkat, Gol., Jabatan</strong>. Baris dicocokkan lewat NIP, NIDN, lalu nama; dosen dengan NIP atau NIDN otomatis berstatus terverifikasi.
            </p>
            <div>
                <label for="lecturer-import-file" class="mb-1 block text-xs font-medium text-muted">Pilih Berkas Excel / CSV</label>
                <input id="lecturer-import-file" type="file" name="file" accept=".xlsx,.xls,.csv" required class="w-full text-sm">
                @if ($oldForm === 'import') @error('file') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
            </div>

            <div class="mt-2 flex items-center justify-end gap-2 border-t border-border pt-4">
                <x-button variant="secondary" type="button" @click="importModalOpen = false">Batal</x-button>
                <x-button variant="primary" type="submit" icon="upload">Mulai Impor</x-button>
            </div>
        </form>
    </div>
</div>
