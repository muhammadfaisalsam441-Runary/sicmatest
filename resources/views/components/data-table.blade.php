@props([
    'headers' => [],
    'sticky' => true,
    'maxHeight' => 'max-h-[70dvh]',
    'ariaLabel' => 'Tabel data',
])

<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-card border border-border bg-surface']) }}>
    <div role="region"
         aria-label="{{ $ariaLabel }}"
         tabindex="0"
         @class(['overflow-x-auto custom-scrollbar focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary', $maxHeight => $sticky, 'overflow-y-auto' => $sticky])>
        <table class="w-full border-collapse text-left text-sm">
            @if (count($headers) > 0)
                <thead @class(['border-b border-border bg-page text-xs font-semibold text-muted', 'sticky top-0 z-10' => $sticky])>
                    <tr>
                        @foreach ($headers as $header)
                            <th scope="col" class="px-3.5 py-3 font-medium whitespace-nowrap sm:px-5">{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
            @endif
            @isset($head)
                <thead @class(['border-b border-border bg-page text-xs font-semibold text-muted', 'sticky top-0 z-10' => $sticky])>
                    {{ $head }}
                </thead>
            @endisset
            <tbody class="divide-y divide-border text-ink">
                {{ $slot }}
            </tbody>
        </table>
    </div>
    @isset($empty)
        {{ $empty }}
    @endisset
    @isset($footer)
        <div class="border-t border-border px-5 py-3">{{ $footer }}</div>
    @endisset
</div>
