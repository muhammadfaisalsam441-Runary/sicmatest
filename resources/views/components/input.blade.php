@props([
    'name',
    'type' => 'text',
    'value' => null,
])

<input type="{{ $type }}" name="{{ $name }}" id="{{ $attributes->get('id', $name) }}"
       @if ($type !== 'password') value="{{ old($name, $value) }}" @endif
       @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror
       {{ $attributes->except('id')->class([
           'w-full' => ! str_contains((string) $attributes->get('class'), 'w-'),
           'rounded-control border bg-surface px-4 py-3 text-sm text-ink placeholder:text-muted/70 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/15',
           'border-danger' => $errors->has($name),
           'border-muted/60' => ! $errors->has($name),
       ]) }}>
