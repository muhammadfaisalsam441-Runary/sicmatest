<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">

<title>{{ $title ?? null ? $title.' • ' : '' }}{{ config('app.name') }}</title>
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">

@fonts
@vite(['resources/css/app.css', 'resources/js/app.js'])
