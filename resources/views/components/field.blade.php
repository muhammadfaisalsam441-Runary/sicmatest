@props([
    'label',
    'name',
    'hint' => null,
    'for' => null,
    'errorKey' => null,
    'required' => false,
])

@php
    $resolvedFor = $for ?? $name;
    $resolvedErrorKey = $errorKey ?? preg_replace('/\.+/', '.', trim((string) preg_replace('/\[([^\]]*)\]/', '.$1', $name), '.'));
    $hintId = $hint ? $resolvedFor.'-hint' : null;
    $errorId = $resolvedFor.'-error';
@endphp

<div {{ $attributes->merge(['class' => 'flex min-w-0 flex-col gap-2']) }}>
    <label for="{{ $resolvedFor }}" class="text-sm font-medium text-ink">
        {{ $label }}@if ($required) <span class="text-danger" aria-hidden="true">*</span>@endif
    </label>
    {{ $slot }}
    @if ($hint)
        <p id="{{ $hintId }}" class="text-xs text-muted">{{ $hint }}</p>
    @endif
    @error($resolvedErrorKey)
        <p class="text-xs font-medium text-danger" id="{{ $errorId }}">{{ $message }}</p>
    @enderror
</div>
