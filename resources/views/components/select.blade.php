@props([
    'name',
    'options' => [],
    'value' => null,
    'placeholder' => null,
    'disabledOptions' => [],
    'errorKey' => null,
    'describedBy' => null,
])

@php
    $resolvedId = $attributes->get('id', $name);
    $resolvedErrorKey = $errorKey ?? preg_replace('/\.+/', '.', trim((string) preg_replace('/\[([^\]]*)\]/', '.$1', $name), '.'));
    $hasError = $errors->has($resolvedErrorKey);
    $selectedValue = old($resolvedErrorKey, $value);
    $describedByIds = array_filter([
        $describedBy,
        $hasError ? $resolvedId.'-error' : null,
    ]);
    $classString = (string) $attributes->get('class');
    $needsFullWidth = preg_match('/(?:^|\s)w-/', $classString) !== 1;
@endphp

<select name="{{ $name }}"
        id="{{ $resolvedId }}"
        @if ($hasError) aria-invalid="true" @endif
        @if ($describedByIds !== []) aria-describedby="{{ implode(' ', $describedByIds) }}" @endif
        {{ $attributes->except(['id', 'aria-describedby'])->class([
            'w-full' => $needsFullWidth,
            'rounded-control border bg-surface px-4 py-3 text-base sm:text-sm min-h-11 text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/15',
            'border-danger' => $hasError,
            'border-muted/75' => ! $hasError,
        ]) }}>
    @if ($placeholder)
        <option value="" @selected($selectedValue === null || $selectedValue === '')>{{ $placeholder }}</option>
    @endif
    @foreach ($options as $optionValue => $optionLabel)
        <option value="{{ $optionValue }}"
                @selected($selectedValue !== null && (string) $selectedValue === (string) $optionValue)
                @disabled(in_array($optionValue, $disabledOptions, true))>{{ $optionLabel }}</option>
    @endforeach
</select>
