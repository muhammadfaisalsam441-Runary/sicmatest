@props([
    'name',
    'placeholder' => '',
    'autocomplete' => 'current-password',
    'toggleLabel' => 'kata sandi',
    'required' => true,
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

<div class="relative {{ $needsFullWidth ? 'w-full' : '' }}" x-data="{ show: false }">
    <input type="password"
           :type="show ? 'text' : 'password'"
           name="{{ $name }}"
           id="{{ $resolvedId }}"
           @if ($placeholder !== '') placeholder="{{ $placeholder }}" @endif
           @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
           @if ($required) required @endif
           @if ($hasError) aria-invalid="true" @endif
           @if ($describedByIds !== []) aria-describedby="{{ implode(' ', $describedByIds) }}" @endif
           {{ $attributes->except(['id', 'aria-describedby', 'type', 'placeholder', 'autocomplete', 'required'])->class([
               'w-full' => $needsFullWidth,
               'rounded-control border bg-surface pl-4 pr-11 py-3 text-sm text-ink placeholder:text-muted focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/15',
               'border-danger' => $hasError,
               'border-muted/75' => ! $hasError,
           ]) }}>
    <button type="button"
            @click="show = !show"
            aria-label="Tampilkan {{ $toggleLabel }}"
            :aria-label="show ? 'Sembunyikan {{ $toggleLabel }}' : 'Tampilkan {{ $toggleLabel }}'"
            aria-pressed="false"
            :aria-pressed="show.toString()"
            class="absolute right-2.5 top-1/2 inline-flex size-8 -translate-y-1/2 cursor-pointer items-center justify-center rounded-control text-muted transition hover:bg-page hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
        <span x-show="!show" class="inline-flex">
            <i data-lucide="eye" class="size-4" aria-hidden="true"></i>
        </span>
        <span x-show="show" x-cloak class="inline-flex">
            <i data-lucide="eye-off" class="size-4" aria-hidden="true"></i>
        </span>
    </button>
</div>
