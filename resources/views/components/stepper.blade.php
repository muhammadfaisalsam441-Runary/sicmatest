@props(['steps' => [], 'current' => 1, 'links' => []])

<ol {{ $attributes->merge(['class' => 'flex items-center gap-3']) }}>
    @foreach ($steps as $index => $step)
        @php($number = $index + 1)
        <li class="flex items-center gap-3">
            <span @class([
                'flex size-7 items-center justify-center rounded-full text-xs font-semibold',
                'bg-primary text-white' => $number === $current,
                'bg-success-soft text-success' => $number < $current,
                'bg-primary-soft text-primary' => $number > $current,
            ])>{{ $number < $current ? '✓' : $number }}</span>
            @if ($links[$index] ?? null)
                <a href="{{ $links[$index] }}" @if ($number === $current) aria-current="step" @endif
                   @class(['text-sm hover:underline', 'font-semibold text-ink' => $number === $current, 'text-muted' => $number !== $current])>{{ $step }}</a>
            @else
                <span @class(['text-sm', 'font-semibold text-ink' => $number === $current, 'text-muted' => $number !== $current])>{{ $step }}</span>
            @endif
            @unless ($loop->last)
                <span class="h-px w-8 bg-border" aria-hidden="true"></span>
            @endunless
        </li>
    @endforeach
</ol>
