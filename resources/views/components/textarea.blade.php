@props(['name', 'value' => null, 'rows' => 3])

<textarea name="{{ $name }}" id="{{ $attributes->get('id', $name) }}" rows="{{ $rows }}"
          @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror
          {{ $attributes->except('id')->class([
           'w-full' => ! str_contains((string) $attributes->get('class'), 'w-'),
              'rounded-control border bg-surface px-4 py-3 text-sm text-ink placeholder:text-muted/70 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/15',
              'border-danger' => $errors->has($name),
              'border-muted/60' => ! $errors->has($name),
          ]) }}>{{ old($name, $value) }}</textarea>
