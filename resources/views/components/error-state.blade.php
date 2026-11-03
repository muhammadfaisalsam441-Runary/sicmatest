@props(['title' => 'Data tidak dapat dimuat', 'message' => 'Periksa koneksi lalu coba lagi.', 'retry' => null])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center gap-3 rounded-card border border-danger/40 bg-danger-soft px-8 py-14 text-center']) }} role="alert">
    <span class="flex size-12 items-center justify-center rounded-full bg-surface text-danger">
        <i data-lucide="circle-alert" class="size-5"></i>
    </span>
    <h3 class="text-lg font-semibold text-danger">{{ $title }}</h3>
    <p class="max-w-md text-sm text-danger/90">{{ $message }}</p>
    @if ($retry)
        <x-button variant="danger-outline" :href="$retry" class="mt-2">Coba lagi</x-button>
    @endif
    {{ $slot }}
</div>
