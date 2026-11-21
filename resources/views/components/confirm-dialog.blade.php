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

<x-modal :name="$name" width="max-w-md" :labelled-by="$name.'-title'">
    <form method="POST"
          action="{{ $action }}"
          x-data="{ submitting: false }"
          x-on:submit="if (submitting) { $event.preventDefault(); return; } submitting = true"
          x-on:sicma:form-error.window="submitting = false"
          x-on:sicma:form-complete.window="submitting = false"
          class="flex flex-col gap-5">
        @csrf
        @unless (strtoupper($method) === 'POST')
            @method($method)
        @endunless

        <div class="flex items-start justify-between gap-4">
            <span class="flex size-12 items-center justify-center rounded-control bg-danger-soft text-danger">
                <i data-lucide="{{ $icon }}" aria-hidden="true" class="size-5"></i>
            </span>
            <button type="button"
                    x-on:click="$dispatch('close-modal', '{{ $name }}')"
                    class="cursor-pointer rounded-control p-1 text-muted transition hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                    aria-label="Tutup">
                <i data-lucide="x" aria-hidden="true" class="size-5"></i>
            </button>
        </div>

        <div class="flex flex-col gap-2">
            <h2 id="{{ $name }}-title" class="text-xl font-semibold text-ink">{{ $title }}</h2>
            @if ($message)
                <p class="text-sm leading-relaxed text-muted">{{ $message }}</p>
            @endif
            {{ $slot }}
        </div>

        @if ($reason)
            @php($reasonId = $name.'-reason')
            <x-field :label="$reason" name="reason" :for="$reasonId" error-key="reason" :required="$reasonRequired">
                <x-textarea name="reason" :id="$reasonId" :placeholder="$reasonPlaceholder" :required="$reasonRequired" />
            </x-field>
        @endif

        <div class="grid grid-cols-2 gap-3">
            <x-button variant="secondary" x-on:click="$dispatch('close-modal', '{{ $name }}')">{{ $cancel }}</x-button>
            <x-button variant="danger" type="submit" x-bind:disabled="submitting">{{ $confirm }}</x-button>
        </div>
    </form>
</x-modal>
