@props(['steps' => [], 'current' => 1, 'links' => []])

<ol {{ $attributes->merge(['class' => 'flex items-center gap-1']) }}>
    @foreach ($steps as $index => $step)
        @php
            $number = $index + 1;
            $isCurrent = $number === $current;
            $isDone = $number < $current;
            $link = $links[$index] ?? null;

            $itemClasses = implode(' ', array_filter([
                'inline-flex items-center gap-1.5 rounded-control px-2.5 py-1 text-xs transition whitespace-nowrap',
                $link ? 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary' : '',
                $isCurrent ? 'bg-primary-soft/80 font-semibold text-primary' : '',
                $isDone ? 'font-medium text-ink' : '',
                $isDone && $link ? 'hover:bg-page' : '',
                ! $isCurrent && ! $isDone ? 'font-medium text-muted' : '',
                ! $isCurrent && ! $isDone && $link ? 'hover:bg-page hover:text-ink' : '',
            ]));

            $badgeClasses = implode(' ', [
                'flex size-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold',
                $isCurrent ? 'bg-primary text-white' : ($isDone ? 'bg-success-soft text-success' : 'border border-border bg-page text-muted'),
            ]);
        @endphp

        <li class="flex items-center gap-1">
            @if ($link)
                <a href="{{ $link }}" @if ($isCurrent) aria-current="step" @endif class="{{ $itemClasses }}">
                    <span aria-hidden="true" class="{{ $badgeClasses }}">{{ $isDone ? '✓' : $number }}</span>
                    @if ($isDone)<span class="sr-only">Selesai:</span>@endif
                    <span>{{ $step }}</span>
                </a>
            @else
                <span @if ($isCurrent) aria-current="step" @endif class="{{ $itemClasses }}">
                    <span aria-hidden="true" class="{{ $badgeClasses }}">{{ $isDone ? '✓' : $number }}</span>
                    @if ($isDone)<span class="sr-only">Selesai:</span>@endif
                    <span>{{ $step }}</span>
                </span>
            @endif

            @unless ($loop->last)
                <i data-lucide="chevron-right" @class(['size-3.5 shrink-0', 'text-success/70' => $isDone, 'text-muted/45' => ! $isDone]) aria-hidden="true"></i>
            @endunless
        </li>
    @endforeach
</ol>
