<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
@if (config('sicma.realtime.enabled', true) && config('broadcasting.default') === 'reverb' && filled(config('broadcasting.connections.reverb.key')))
<meta name="reverb-config" content="{{ json_encode([
    'key' => config('broadcasting.connections.reverb.key'),
    'wsHost' => config('broadcasting.connections.reverb.client.host'),
    'wsPort' => (int) (config('broadcasting.connections.reverb.client.port') ?: 8080),
    'wssPort' => (int) (config('broadcasting.connections.reverb.client.port') ?: 8080),
    'forceTLS' => config('broadcasting.connections.reverb.client.scheme', 'http') === 'https',
    'channel' => config('sicma.realtime.channel') ?: 'sicma.availability',
    'event' => config('sicma.realtime.event') ?: 'availability.updated',
], JSON_THROW_ON_ERROR) }}">
@endif

<title>{{ $title ?? null ? $title.' • ' : '' }}{{ config('app.name') }}</title>
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" media="print" onload="this.media='all'">
<noscript>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap">
</noscript>
@vite(['resources/css/app.css', 'resources/js/app.js'])
