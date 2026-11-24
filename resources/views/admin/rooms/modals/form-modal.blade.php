{{-- MODAL 1: EDIT / TAMBAH RUANG DINAMIS TUNGGAL --}}
<div x-show="formModalOpen" x-cloak data-modal-name="room-form"
     role="dialog" aria-modal="true" aria-labelledby="room-form-title"
     @keydown.escape.window="if (formModalOpen) formModalOpen = false"
     class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="formModalOpen = false"></div>
    <div class="relative max-h-[90dvh] w-full max-w-lg overflow-y-auto rounded-2xl bg-surface p-6 shadow-2xl">
        <div class="flex items-start justify-between border-b border-border pb-3">
            <div>
                <span class="text-xs font-semibold text-primary" x-text="formRoom.id ? 'Perbarui Data Ruangan' : 'Ruang Baru'"></span>
                <h3 id="room-form-title" class="text-lg font-bold text-ink" x-text="formRoom.id ? 'Ubah Ruang: ' + formRoom.name : 'Tambah Ruang Baru'"></h3>
            </div>
            <button type="button" @click="formModalOpen = false" aria-label="Tutup modal ruang" class="inline-flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-control text-muted transition hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                <i data-lucide="x" class="size-5" aria-hidden="true"></i>
            </button>
        </div>

        @php($oldForm ??= old('form'))
        @php($isRoomFormError = $errors->any() && ($oldForm === 'add' || str_starts_with((string) $oldForm, 'edit-')))
        <form method="POST" action="{{ route('admin.rooms.store') }}"
              :action="formRoom.id ? @js(route('admin.rooms.update', '__ROOM_ID__')).replace('__ROOM_ID__', formRoom.id) : @js(route('admin.rooms.store'))"
              class="mt-4 flex flex-col gap-4">
            @csrf
            <template x-if="formRoom.id">
                <input type="hidden" name="_method" value="PUT">
            </template>
            <input type="hidden" name="form" :value="formRoom.id ? 'edit-' + formRoom.id : 'add'">
            <input type="hidden" name="room_id" :value="formRoom.id || ''">

            @if ($isRoomFormError)
                <x-alert variant="danger">{{ $errors->first() }}</x-alert>
            @endif

            <div>
                <label for="room-form-name" class="mb-1 block text-xs font-medium text-muted">Nama Ruang <span class="text-danger">*</span></label>
                <input id="room-form-name" type="text" name="name" x-model="formRoom.name" required placeholder="Contoh: AE 107 atau Lab ICT 10" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                @if ($isRoomFormError)
                    @error('name') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror
                    @error('code') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror
                @endif
            </div>

            <div>
                <label for="room-form-type" class="mb-1 block text-xs font-medium text-muted">Jenis Ruang <span class="text-danger">*</span></label>
                <select id="room-form-type" name="type" x-model="formRoom.type" required class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                    <option value="classroom">Ruang kuliah (Kelas teori / umum)</option>
                    <option value="lab">Laboratorium (Lab komputer / praktikum)</option>
                </select>
                @if ($isRoomFormError) @error('type') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label for="room-form-capacity" class="mb-1 block text-xs font-medium text-muted">Kapasitas (Kursi)</label>
                    <input id="room-form-capacity" type="number" min="1" max="1000" name="capacity" x-model="formRoom.capacity" placeholder="Contoh: 40" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                    @if ($isRoomFormError) @error('capacity') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                </div>
                <div>
                    <label for="room-form-floor" class="mb-1 block text-xs font-medium text-muted">Lantai</label>
                    <input id="room-form-floor" type="number" min="0" max="20" name="floor" x-model="formRoom.floor" placeholder="Contoh: 1" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                    @if ($isRoomFormError) @error('floor') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                </div>
            </div>

            <div>
                <label for="room-form-building" class="mb-1 block text-xs font-medium text-muted">Gedung</label>
                <input id="room-form-building" type="text" name="building" x-model="formRoom.building" placeholder="Contoh: AE atau Pinisi" list="building-suggestions" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                <datalist id="building-suggestions">
                    @foreach ($buildings as $b)
                        <option value="{{ $b->name }}"></option>
                    @endforeach
                </datalist>
                <p class="mt-1 text-[11px] text-muted">Kosongkan bila gedung belum didata (akan ditandai Perlu Verifikasi).</p>
                @if ($isRoomFormError) @error('building') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
            </div>

            <div>
                <label for="room-form-facilities" class="mb-1 block text-xs font-medium text-muted">Fasilitas Ruang (Pisahkan dengan koma)</label>
                <input id="room-form-facilities" type="text" name="facilities" x-model="formRoom.facilities" placeholder="Contoh: Proyektor, AC, Stopkontak, Papan tulis" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                @if ($isRoomFormError) @error('facilities') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                {{-- Saran Tag Cepat --}}
                <div class="mt-2 flex flex-wrap gap-1.5">
                    <span class="mr-1 self-center text-[11px] text-muted">Saran cepat:</span>
                    <template x-for="tag in ['Proyektor', 'AC', 'Papan tulis', 'Stopkontak', 'Smart TV', 'Sound System']" :key="tag">
                        <button type="button" @click="addFacilityTag(tag)"
                                :class="(formRoom.facilities || '').split(',').map(s => s.trim().toLowerCase()).includes(tag.toLowerCase()) ? 'border-primary bg-primary-soft text-primary font-semibold' : 'border-border bg-page text-muted hover:border-primary hover:text-primary'"
                                class="cursor-pointer rounded-full border px-2 py-0.5 text-[11px] transition"
                                x-text="(formRoom.facilities || '').split(',').map(s => s.trim().toLowerCase()).includes(tag.toLowerCase()) ? '✓ ' + tag : '+ ' + tag"></button>
                    </template>
                </div>
            </div>

            <div class="rounded-control border border-border bg-page/50 px-3 py-2.5">
                <input type="hidden" name="is_bookable" value="0">
                <label for="room-is-bookable" class="flex cursor-pointer select-none items-start gap-2.5 text-sm text-ink">
                    <input type="checkbox" id="room-is-bookable" name="is_bookable" value="1" x-model="formRoom.is_bookable" class="mt-0.5 size-4 cursor-pointer rounded accent-primary">
                    <span class="flex flex-col gap-0.5">
                        <span class="font-medium">Dapat dipesan Keti</span>
                        <span class="text-[11px] text-muted">Nonaktifkan jika ruang hanya dipakai untuk jadwal resmi atau penggunaan khusus.</span>
                    </span>
                </label>
                @if ($isRoomFormError) @error('is_bookable') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
            </div>

            <div class="mt-2 flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
                <template x-if="formRoom.id">
                    <x-button variant="danger-outline" type="button" icon="trash-2" @click="openDeleteFromEdit()">Hapus ruang</x-button>
                </template>
                <template x-if="!formRoom.id">
                    <div class="hidden sm:block"></div>
                </template>
                <div class="ml-auto flex flex-wrap items-center justify-end gap-2">
                    <x-button variant="secondary" type="button" @click="formModalOpen = false">Batal</x-button>
                    <x-button variant="primary" type="submit" icon="check">Simpan Data Ruang</x-button>
                </div>
            </div>
        </form>
    </div>
</div>
