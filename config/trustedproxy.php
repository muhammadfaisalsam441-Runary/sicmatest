<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Di VPS yang memakai reverse proxy (Nginx/Caddy/Cloudflare) ke 127.0.0.1:8000,
    | set TRUSTED_PROXIES=* (atau IP/CIDR proxy) agar deteksi HTTPS dan rate limiter
    | membaca IP asli pengunjung meskipun config:cache aktif.
    |
    */
    'proxies' => env('TRUSTED_PROXIES'),
];
