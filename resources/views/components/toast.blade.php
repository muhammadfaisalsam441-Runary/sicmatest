{{-- Pesan hasil aksi di kanan atas; hilang otomatis kecuali galat. Isi dari session('toast') = ['type', 'title', 'message'] atau event 'sicma:toast'. --}}
@props(['fallbackError' => false])

@php
    $initialToast = session('toast');

    if (! $initialToast && $fallbackError && session('error')) {
        $initialToast = [
            'type' => 'error',
            'title' => 'Tindakan belum berhasil',
            'message' => session('error'),
        ];
    }
@endphp

<div data-toast-host
     popover="manual"
     x-data="sicmaToast(@js($initialToast))"
     x-cloak
     :class="{ 'hidden': items.length === 0 }"
     class="pointer-events-none fixed top-4 right-4 bottom-auto left-auto z-[60] m-0 w-[calc(100vw-2rem)] max-w-[380px] overflow-visible border-0 bg-transparent p-0 sm:top-6 sm:right-6">
    <div class="flex flex-col gap-2.5">
        <template x-for="item in items" :key="item.id">
            <div x-on:mouseenter="pauseTimer(item.id)"
                 x-on:mouseleave="startTimer(item.id)"
                 x-on:focusin="pauseTimer(item.id)"
                 x-on:focusout="startTimer(item.id)"
                 :role="isError(item.type) ? 'alert' : 'status'"
                 :class="{
                     'border-success bg-success-soft text-success': item.type === 'success',
                     'border-danger bg-danger-soft text-danger': isError(item.type),
                     'border-warning bg-warning-soft text-warning-ink': item.type === 'warning',
                     'border-primary bg-primary-soft text-primary': item.type === 'info',
                 }"
                 class="pointer-events-auto flex items-start gap-3 rounded-card border px-5 py-4 shadow-lg">
                <span x-show="item.type === 'success'" class="mt-0.5 shrink-0">
                    <i data-lucide="circle-check" aria-hidden="true" class="size-5"></i>
                </span>
                <span x-show="isError(item.type)" class="mt-0.5 shrink-0">
                    <i data-lucide="circle-alert" aria-hidden="true" class="size-5"></i>
                </span>
                <span x-show="item.type === 'warning'" class="mt-0.5 shrink-0">
                    <i data-lucide="triangle-alert" aria-hidden="true" class="size-5"></i>
                </span>
                <span x-show="item.type === 'info'" class="mt-0.5 shrink-0">
                    <i data-lucide="info" aria-hidden="true" class="size-5"></i>
                </span>
                <div class="flex flex-1 flex-col gap-1">
                    <p x-show="item.title" x-text="item.title" class="font-semibold"></p>
                    <p x-show="item.message" x-text="item.message" class="text-sm"></p>
                </div>
                <button type="button"
                        x-on:click="dismiss(item.id)"
                        class="cursor-pointer rounded-control p-0.5 transition hover:opacity-80 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                        aria-label="Tutup pesan">
                    <i data-lucide="x" aria-hidden="true" class="size-4"></i>
                </button>
            </div>
        </template>
    </div>
</div>
