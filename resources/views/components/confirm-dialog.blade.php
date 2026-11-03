@props([
    'name',
    'title',
    'message' => null,
    'action',
    'method' => 'POST',
    'confirm' => 'Ya, lanjutkan',
    'cancel' => 'Batal',
    'icon' => 'trash-2',
    'reason' => null,
    'reasonRequired' => false,
    'reasonPlaceholder' => 'Contoh: ruang dipakai kegiatan jurusan',
])

<x-modal :name="$name" width="max-w-md">
    <form method="POST" action="{{ $action }}" class="flex flex-col gap-5">
        @csrf
        @unless (strtoupper($method) === 'POST')
            @method($method)
        @endunless

        <span class="flex size-12 items-center justify-center rounded-control bg-danger-soft text-danger">
            <i data-lucide="{{ $icon }}" class="size-5"></i>
        </span>
        <div class="flex flex-col gap-2">
            <h2 class="text-xl font-semibold text-ink">{{ $title }}</h2>
            @if ($message)
                <p class="text-sm leading-relaxed text-muted">{{ $message }}</p>
            @endif
            {{ $slot }}
        </div>

        @if ($reason)
            <x-field :label="$reason" name="reason">
                <x-textarea name="reason" :placeholder="$reasonPlaceholder" :required="$reasonRequired" />
            </x-field>
        @endif

        <div class="grid grid-cols-2 gap-3">
            <x-button variant="secondary" x-on:click="$dispatch('close-modal', '{{ $name }}')">{{ $cancel }}</x-button>
            <x-button variant="danger" type="submit">{{ $confirm }}</x-button>
        </div>
    </form>
</x-modal>
