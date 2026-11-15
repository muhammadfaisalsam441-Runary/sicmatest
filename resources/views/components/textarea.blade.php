@props([
    'name',
    'value' => null,
    'rows' => 3,
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

<textarea name="{{ $name }}"
          id="{{ $resolvedId }}"
          rows="{{ $rows }}"
          @if ($hasError) aria-invalid="true" @endif
          @if ($describedByIds !== []) aria-describedby="{{ implode(' ', $describedByIds) }}" @endif
          {{ $attributes->except(['id', 'aria-describedby'])->class([
              'w-full' => $needsFullWidth,
              'rounded-control border bg-surface px-4 py-3 text-sm text-ink placeholder:text-muted focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/15',
              'border-danger' => $hasError,
              'border-muted/75' => ! $hasError,
          ]) }}>{{ old($resolvedErrorKey, $value) }}</textarea>
