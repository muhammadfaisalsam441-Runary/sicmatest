{{-- Pesan hasil aksi di kanan atas; hilang otomatis kecuali galat. Isi dari session('toast') = ['type', 'title', 'message']. --}}
@php($toast = session('toast'))
@if ($toast)
    @php($type = $toast['type'] ?? 'success')
    @php($isError = in_array($type, ['error', 'danger'], true))
    <div x-data="{
             show: true,
             timer: null,
             autoHide: @js(! $isError),
             start() {
                 if (! this.autoHide || this.timer) return;
                 this.timer = setTimeout(() => { this.show = false; }, 5000);
             },
             pause() {
                 if (this.timer) {
                     clearTimeout(this.timer);
                     this.timer = null;
                 }
             }
         }"
         x-init="start()"
         x-on:mouseenter="pause()"
         x-on:mouseleave="start()"
         x-on:focusin="pause()"
         x-on:focusout="start()"
         x-show="show"
         x-transition.opacity
         class="fixed top-4 right-4 z-[60] w-[calc(100vw-2rem)] max-w-[380px] sm:top-6 sm:right-6">
        <div @class([
            'flex items-start gap-3 rounded-card border px-5 py-4 shadow-lg',
            'border-success bg-success-soft text-success' => $type === 'success',
            'border-danger bg-danger-soft text-danger' => $isError,
            'border-warning bg-warning-soft text-warning-ink' => $type === 'warning',
            'border-primary bg-primary-soft text-primary' => $type === 'info',
        ]) role="{{ $isError ? 'alert' : 'status' }}">
            <i data-lucide="{{ match ($type) { 'error', 'danger' => 'circle-alert', 'warning' => 'triangle-alert', 'info' => 'info', default => 'circle-check' } }}" aria-hidden="true" class="mt-0.5 size-5 shrink-0"></i>
            <div class="flex flex-1 flex-col gap-1">
                <p class="font-semibold">{{ $toast['title'] ?? '' }}</p>
                @if (! empty($toast['message']))
                    <p class="text-sm">{{ $toast['message'] }}</p>
                @endif
            </div>
            <button type="button"
                    x-on:click="pause(); show = false"
                    class="cursor-pointer rounded-control p-0.5 transition hover:opacity-80 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                    aria-label="Tutup pesan">
                <i data-lucide="x" aria-hidden="true" class="size-4"></i>
            </button>
        </div>
    </div>
@endif
