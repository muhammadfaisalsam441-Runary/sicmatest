@props([
    'label',
    'name',
    'hint' => null,
])

<div {{ $attributes->merge(['class' => 'flex flex-col gap-2']) }}>
    <label for="{{ $name }}" class="text-sm font-medium text-ink">{{ $label }}</label>
    {{ $slot }}
    @if ($hint)
        <p class="text-xs text-muted">{{ $hint }}</p>
    @endif
    @error($name)
        <p class="text-xs font-medium text-danger" id="{{ $name }}-error">{{ $message }}</p>
    @enderror
</div>
