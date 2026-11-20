@props([
    'variant' => 'public',
])

@if (in_array($variant, ['public', 'portal'], true))
    <div aria-hidden="true"
         data-ambient-backdrop="{{ $variant }}"
         class="pointer-events-none {{ $variant === 'public' ? 'fixed' : 'absolute' }} inset-0 -z-10 overflow-hidden select-none">
        <div class="sicma-aurora-orb sicma-aurora-orb--primary"></div>
        <div class="sicma-aurora-orb sicma-aurora-orb--secondary"></div>
        <div class="sicma-arch-grid"></div>
        @if ($variant === 'portal')
            <div class="sicma-portal-backdrop absolute inset-x-0 top-0 h-80"></div>
        @endif
        <div class="sicma-schedule-cells relative mx-auto h-full min-h-[540px] max-w-[1168px]">
            <span class="sicma-schedule-cell" style="--cell-x: 8%; --cell-y: 14%; --cell-delay: 0s;"></span>
            <span class="sicma-schedule-cell" style="--cell-x: 20%; --cell-y: 38%; --cell-delay: 1.3s;"></span>
            <span class="sicma-schedule-cell sicma-schedule-cell--center" style="--cell-x: 34%; --cell-y: 22%; --cell-delay: 2.6s;"></span>
            <span class="sicma-schedule-cell sicma-schedule-cell--center" style="--cell-x: 48%; --cell-y: 12%; --cell-delay: 0.7s;"></span>
            <span class="sicma-schedule-cell sicma-schedule-cell--center" style="--cell-x: 62%; --cell-y: 44%; --cell-delay: 1.9s;"></span>
            <span class="sicma-schedule-cell" style="--cell-x: 74%; --cell-y: 18%; --cell-delay: 0.4s;"></span>
            <span class="sicma-schedule-cell" style="--cell-x: 86%; --cell-y: 34%; --cell-delay: 2.2s;"></span>
            <span class="sicma-schedule-cell sicma-schedule-cell--center" style="--cell-x: 56%; --cell-y: 62%; --cell-delay: 3.1s;"></span>
            <div class="sicma-grid-beam"></div>
            <div class="sicma-grid-beam--vertical"></div>
        </div>
    </div>
@elseif ($variant === 'auth')
    <div aria-hidden="true"
         data-ambient-backdrop="auth"
         class="pointer-events-none absolute inset-0 overflow-hidden select-none">
        <div class="sicma-auth-aurora sicma-auth-aurora--top"></div>
        <div class="sicma-auth-aurora sicma-auth-aurora--bottom"></div>
        <div class="sicma-auth-grid"></div>
        <span class="sicma-auth-cell" style="--cell-x: 15%; --cell-y: 20%; --cell-delay: 0.4s;"></span>
        <span class="sicma-auth-cell" style="--cell-x: 72%; --cell-y: 16%; --cell-delay: 1.8s;"></span>
        <span class="sicma-auth-cell" style="--cell-x: 56%; --cell-y: 48%; --cell-delay: 2.9s;"></span>
        <span class="sicma-auth-cell" style="--cell-x: 24%; --cell-y: 74%; --cell-delay: 1.1s;"></span>
        <span class="sicma-auth-cell" style="--cell-x: 78%; --cell-y: 68%; --cell-delay: 3.5s;"></span>
    </div>
@endif
