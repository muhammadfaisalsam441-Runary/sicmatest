{{-- Overlay container untuk animasi pembuka 3D SICMA (dikelola oleh resources/js/splash-screen.js). --}}
<div id="sicma-splash-root"
     data-splash-screen
     data-video-webm="{{ asset('videos/sicma-splash-3d.webm') }}"
     data-video-mp4="{{ asset('videos/sicma-splash-3d.mp4') }}"
     data-poster-url="{{ asset('videos/sicma-splash-poster.webp') }}"
     class="pointer-events-none fixed inset-0 z-[100]"
     style="display: none;"
     aria-hidden="true"></div>
