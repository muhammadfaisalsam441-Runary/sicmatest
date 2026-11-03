@props(['headers' => []])

<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-card border border-border bg-surface']) }}>
    <table class="w-full text-left text-sm">
        <thead class="border-b border-border text-xs text-muted">
            <tr>
                @foreach ($headers as $header)
                    <th scope="col" class="px-5 py-3 font-medium">{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-border text-ink">
            {{ $slot }}
        </tbody>
    </table>
    @isset($empty)
        {{ $empty }}
    @endisset
    @isset($footer)
        <div class="border-t border-border px-5 py-3">{{ $footer }}</div>
    @endisset
</div>
