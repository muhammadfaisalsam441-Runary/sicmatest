@props([
    'name',
    'type' => 'text',
    'value' => null,
    'errorKey' => null,
    'describedBy' => null,
])

@php
    $resolvedId = $attributes->get('id', $name);
    $resolvedErrorKey = $errorKey ?? preg_replace('/\.+/', '.', trim((string) preg_replace('/\[([^\]]*)\]/', '.$1', $name), '.'));
    $hasError = $errors->has($resolvedErrorKey);
    $describedByIds = array_filter([
        $describedBy,
        $hasError ? $resolvedId.'-error' : null,
    ]);
    $classString = (string) $attributes->get('class');
    $needsFullWidth = preg_match('/(?:^|\s)w-/', $classString) !== 1;
@endphp

@if ($type === 'password')
    <div class="relative {{ $needsFullWidth ? 'w-full' : '' }}" x-data="{ show: false }">
        <input type="password"
               :type="show ? 'text' : 'password'"
               name="{{ $name }}"
               id="{{ $resolvedId }}"
               @if ($hasError) aria-invalid="true" @endif
               @if ($describedByIds !== []) aria-describedby="{{ implode(' ', $describedByIds) }}" @endif
               {{ $attributes->except(['id', 'aria-describedby', 'type'])->class([
                   'w-full' => $needsFullWidth,
                   'rounded-control border bg-surface pl-4 pr-12 py-3 text-base sm:text-sm min-h-11 text-ink placeholder:text-muted focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/15',
                   'border-danger' => $hasError,
                   'border-muted/75' => ! $hasError,
               ]) }}>
        <button type="button"
                x-on:click="show = ! show"
                aria-label="Tampilkan kata sandi"
                :aria-label="show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'"
                aria-pressed="false"
                :aria-pressed="show.toString()"
                class="absolute right-1.5 top-1/2 inline-flex size-10 -translate-y-1/2 cursor-pointer items-center justify-center rounded-control text-muted transition hover:bg-page hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
            <span x-show="!show" class="inline-flex">
                <i data-lucide="eye" class="size-4" aria-hidden="true"></i>
            </span>
            <span x-show="show" x-cloak class="inline-flex">
                <i data-lucide="eye-off" class="size-4" aria-hidden="true"></i>
            </span>
        </button>
    </div>
@else
    <input type="{{ $type }}"
           name="{{ $name }}"
           id="{{ $resolvedId }}"
           @if ($type !== 'password') value="{{ old($resolvedErrorKey, $value) }}" @endif
           @if ($hasError) aria-invalid="true" @endif
           @if ($describedByIds !== []) aria-describedby="{{ implode(' ', $describedByIds) }}" @endif
           {{ $attributes->except(['id', 'aria-describedby'])->class([
               'w-full' => $needsFullWidth,
               'rounded-control border bg-surface px-4 py-3 text-base sm:text-sm min-h-11 text-ink placeholder:text-muted focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/15',
               'border-danger' => $hasError,
               'border-muted/75' => ! $hasError,
           ]) }}>
@endif
