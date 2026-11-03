{{-- Pesan hasil aksi di kanan atas; hilang otomatis kecuali galat. Isi dari session('toast') = ['type', 'title', 'message']. --}}
@php($toast = session('toast'))
@if ($toast)
    @php($type = $toast['type'] ?? 'success')
    <div x-data="{ show: true }" x-init="@js($type) !== 'error' && setTimeout(() => show = false, 5000)" x-show="show" x-transition.opacity
         class="fixed top-6 right-6 z-[60] w-[380px]">
        <div @class([
            'flex items-start gap-3 rounded-card border px-5 py-4 shadow-lg',
            'border-success bg-success-soft text-success' => $type === 'success',
            'border-danger bg-danger-soft text-danger' => $type === 'error',
            'border-primary bg-primary-soft text-primary' => $type === 'info',
        ]) role="{{ $type === 'error' ? 'alert' : 'status' }}">
            <i data-lucide="{{ match ($type) { 'error' => 'circle-alert', 'info' => 'info', default => 'circle-check' } }}" class="mt-0.5 size-5 shrink-0"></i>
            <div class="flex flex-1 flex-col gap-1">
                <p class="font-semibold">{{ $toast['title'] ?? '' }}</p>
                @if (! empty($toast['message']))
                    <p class="text-sm">{{ $toast['message'] }}</p>
                @endif
            </div>
            <button type="button" x-on:click="show = false" aria-label="Tutup pesan"><i data-lucide="x" class="size-4"></i></button>
        </div>
    </div>
@endif
