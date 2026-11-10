{{-- Formulir Tambah/Ubah ruang. $form membedakan isian lama (old) antar-modal. --}}
@php($isCurrent = old('form') === $form)

<form method="POST" action="{{ $action }}" class="flex flex-col gap-4">
    @csrf
    @unless ($method === 'POST')
        @method($method)
    @endunless
    <input type="hidden" name="form" value="{{ $form }}">

    @if ($isCurrent && $errors->any())
        <x-alert variant="danger">{{ $errors->count() }} isian perlu diperbaiki sebelum disimpan.</x-alert>
    @endif

    <x-field label="Nama ruang" :name="$isCurrent ? 'name' : $form.'-name'">
        <x-input name="name" :id="$form.'-name'" :value="$isCurrent ? old('name') : $room?->name" placeholder="Contoh: C1-R5" required />
        @if ($isCurrent) @error('code')<p class="text-xs font-medium text-danger">{{ $message }}</p>@enderror @endif
    </x-field>
    <x-field label="Jenis ruang" :name="$form.'-type'">
        <x-select name="type" :id="$form.'-type'" :options="$typeOptions" :value="$isCurrent ? old('type') : ($room?->type->value ?? 'classroom')" />
    </x-field>
    <div class="grid grid-cols-2 gap-3">
        <x-field label="Kapasitas" :name="$isCurrent ? 'capacity' : $form.'-capacity'">
            <x-input type="number" min="1" name="capacity" :id="$form.'-capacity'" :value="$isCurrent ? old('capacity') : $room?->capacity" placeholder="Jumlah kursi" />
        </x-field>
        <x-field label="Lantai" :name="$isCurrent ? 'floor' : $form.'-floor'">
            <x-input type="number" min="0" name="floor" :id="$form.'-floor'" :value="$isCurrent ? old('floor') : $room?->floor" placeholder="Contoh: 1" />
        </x-field>
    </div>
    <x-field label="Gedung" :name="$isCurrent ? 'building' : $form.'-building'" hint="Kosongkan bila gedung belum didata.">
        <x-input name="building" :id="$form.'-building'" :value="$isCurrent ? old('building') : $room?->building?->name" placeholder="Nama gedung" />
    </x-field>
    <x-field label="Fasilitas" :name="$isCurrent ? 'facilities' : $form.'-facilities'" hint="Pisahkan dengan koma.">
        <x-input name="facilities" :id="$form.'-facilities'" :value="$isCurrent ? old('facilities') : $room?->facilities->pluck('name')->implode(', ')" placeholder="Contoh: Proyektor, AC" />
    </x-field>

    <div class="mt-2 flex items-center justify-between gap-3">
        @if ($room)
            <x-button variant="danger-outline" icon="trash-2" x-on:click="$dispatch('close-modal', 'edit-room-{{ $room->id }}'); $dispatch('open-modal', 'delete-room-{{ $room->id }}')">Hapus ruang</x-button>
        @else
            <span></span>
        @endif
        <div class="flex gap-3">
            <x-button variant="secondary" x-on:click="$dispatch('close-modal', '{{ $room ? 'edit-room-'.$room->id : 'add-room' }}')">Batal</x-button>
            <x-button type="submit">Simpan</x-button>
        </div>
    </div>
</form>
