@props([
    'name',
    'title' => null,
    'width' => 'max-w-lg',
    'show' => false,
    'ariaLabel' => null,
    'labelledBy' => null,
    'describedBy' => null,
])

{{-- Buka dengan $dispatch('open-modal', 'nama'), tutup dengan $dispatch('close-modal', 'nama'). --}}
<dialog x-data="{
            open: @js($show),
            init() {
                if (this.open) {
                    this.show();
                }
            },
            show() {
                this.open = true;
                this.$nextTick(() => {
                    const dialog = this.$refs.dialog;
                    if (this.open && dialog && ! dialog.open) {
                        dialog.showModal();
                    }
                    window.renderIcons?.(this.$el);
                });
            },
            close() {
                this.open = false;
                const dialog = this.$refs.dialog;
                if (dialog?.open) {
                    if (window.sicmaCloseDialog) {
                        void window.sicmaCloseDialog(dialog);
                    } else {
                        dialog.close();
                    }
                }
            },
            onBackdropClick(event) {
                if ('closedBy' in HTMLDialogElement.prototype) {
                    return;
                }
                if (event.target && event.target !== this.$refs.dialog) {
                    return;
                }
                const rect = this.$refs.dialog.getBoundingClientRect();
                if (rect.top <= event.clientY && event.clientY <= rect.top + rect.height
                    && rect.left <= event.clientX && event.clientX <= rect.left + rect.width) {
                    return;
                }
                this.close();
            },
        }"
        x-ref="dialog"
        x-on:open-modal.window="if ($event.detail === '{{ $name }}') show()"
        x-on:close-modal.window="if ($event.detail === '{{ $name }}') close()"
        x-on:cancel.prevent="close()"
        x-on:close="open = false"
        x-on:click="onBackdropClick($event)"
        closedby="any"
        data-modal-name="{{ $name }}"
        :data-modal-open="open ? 'true' : 'false'"
        @if ($labelledBy)
            aria-labelledby="{{ $labelledBy }}"
        @elseif ($title)
            aria-labelledby="{{ $name }}-modal-title"
        @else
            aria-label="{{ $ariaLabel ?? 'Dialog' }}"
        @endif
        @if ($describedBy)
            aria-describedby="{{ $describedBy }}"
        @endif
        {{ $attributes->merge(['class' => "m-auto w-[calc(100%-1.5rem)] sm:w-[calc(100%-2rem)] {$width} overflow-hidden rounded-2xl border border-border bg-surface p-0 text-ink shadow-xl outline-none backdrop:bg-ink/80"]) }}>
    <div class="max-h-[90dvh] overflow-y-auto p-5 sm:p-8">
        @if ($title)
            <div class="mb-5 flex items-start justify-between gap-4">
                <h2 id="{{ $name }}-modal-title" class="text-xl font-semibold text-ink">{{ $title }}</h2>
                <button type="button"
                        x-on:click="close()"
                        class="inline-flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-control text-muted transition hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                        aria-label="Tutup">
                    <i data-lucide="x" aria-hidden="true" class="size-5"></i>
                </button>
            </div>
        @endif
        {{ $slot }}
    </div>
</dialog>
