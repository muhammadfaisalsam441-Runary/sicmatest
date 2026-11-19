const SPLASH_SEEN_KEY = 'sicma.splash.seen';
const FADE_OUT_MS = 480;
const REDUCED_HOLD_MS = 1200;
const MAX_WATCHDOG_MS = 7500;
const STAGE_FEATHER_MASK = 'radial-gradient(ellipse 52% 52% at 50% 50%, #000 72%, rgba(0, 0, 0, 0.85) 84%, transparent 100%)';

let activeSession = null;
let sessionSeq = 0;

function markSplashSeen() {
    try {
        window.sessionStorage.setItem(SPLASH_SEEN_KEY, '1');
    } catch {
        // Abaikan jika sessionStorage dibatasi browser.
    }
}

function resetSplashRoot(root) {
    root.innerHTML = '';
    root.style.display = 'none';
    root.style.opacity = '';
    root.style.transition = '';
    root.className = 'pointer-events-none fixed inset-0 z-[100]';
    root.setAttribute('aria-hidden', 'true');
    root.removeAttribute('role');
    root.removeAttribute('aria-modal');
}

function releaseVideoElement(video) {
    if (!video) return;
    try {
        video.pause();
        video.removeAttribute('src');
        for (const sourceEl of video.querySelectorAll('source')) {
            sourceEl.remove();
        }
        video.load();
    } catch {
        // Abaikan error pelepasan dekoder video pada browser tertentu.
    }
}

function buildOverlayDom(root) {
    root.innerHTML = '';
    root.style.display = 'flex';
    root.style.opacity = '1';
    root.style.transition = `opacity ${FADE_OUT_MS}ms cubic-bezier(0.22, 1, 0.36, 1)`;
    root.className = 'pointer-events-auto fixed inset-0 z-[100] flex flex-col items-center justify-center overflow-hidden bg-[#00020a] select-none';
    root.setAttribute('aria-hidden', 'false');
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-label', 'Animasi pembuka 3D SICMA');

    root.innerHTML = `
        <div class="pointer-events-none absolute inset-0" style="background: radial-gradient(circle at 50% 48%, rgba(11, 46, 118, 0.24) 0%, rgba(2, 8, 22, 0.88) 46%, #00020a 100%);"></div>

        <div data-splash-stage
             class="pointer-events-none relative z-10 flex items-center justify-center"
             style="width: min(88vmin, 720px); max-width: 100vw; aspect-ratio: 1 / 1; transform: perspective(1100px) rotateX(0deg) rotateY(0deg); will-change: transform;">
            <div data-splash-media
                 class="relative h-full w-full overflow-hidden"
                 style="mask-image: ${STAGE_FEATHER_MASK}; -webkit-mask-image: ${STAGE_FEATHER_MASK};">
            </div>
        </div>

        <div class="pointer-events-none fixed inset-x-0 bottom-7 z-20 flex flex-col items-center gap-3 px-6">
            <div class="h-1 w-44 overflow-hidden rounded-full bg-white/10">
                <div data-splash-progress
                     class="h-full w-full origin-left rounded-full bg-gradient-to-r from-[#2D68C4] via-[#00F0FF] to-[#7CF7FF] shadow-[0_0_12px_rgba(0,240,255,0.8)]"
                     style="transform: scaleX(0); will-change: transform;"></div>
            </div>
        </div>

        <button type="button"
                data-splash-skip
                class="fixed top-5 right-5 z-20 flex cursor-pointer items-center gap-1.5 rounded-full border border-white/15 bg-white/10 px-4 py-2 text-xs font-semibold tracking-wide text-white/90 backdrop-blur-md transition hover:border-cyan-300/60 hover:bg-white/15 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-400 sm:top-7 sm:right-7">
            <span>Lewati</span>
            <span aria-hidden="true" class="text-[10px] text-white/60">ESC</span>
        </button>
    `;

    return {
        stageEl: root.querySelector('[data-splash-stage]'),
        mediaEl: root.querySelector('[data-splash-media]'),
        progressEl: root.querySelector('[data-splash-progress]'),
        skipBtn: root.querySelector('[data-splash-skip]'),
    };
}

function renderPosterFallback(ui, posterUrl) {
    if (!ui.mediaEl || !posterUrl) return;
    ui.mediaEl.innerHTML = '';
    const img = document.createElement('img');
    img.src = posterUrl;
    img.alt = 'SICMA — Solusi Cerdas Reservasi Kelas';
    img.decoding = 'async';
    img.className = 'block h-full w-full object-contain';
    ui.mediaEl.appendChild(img);

    if (ui.progressEl) {
        ui.progressEl.style.transform = 'scaleX(1)';
    }
}

function playPosterMode(root, posterUrl) {
    const ui = buildOverlayDom(root);
    renderPosterFallback(ui, posterUrl);

    let cleanedUp = false;
    let closing = false;
    let holdTimer = null;
    let fadeTimer = null;

    const finishAndDispose = (immediate = false) => {
        if (cleanedUp) return;
        if (closing && !immediate) return;
        closing = true;

        clearTimeout(holdTimer);
        clearTimeout(fadeTimer);
        window.removeEventListener('keydown', onKeyDown);

        const tearDown = () => {
            if (cleanedUp) return;
            cleanedUp = true;
            resetSplashRoot(root);
            if (activeSession?.cleanup === finishAndDispose) {
                activeSession = null;
            }
        };

        if (immediate) {
            tearDown();
            return;
        }

        root.style.opacity = '0';
        fadeTimer = setTimeout(tearDown, FADE_OUT_MS);
    };

    const onKeyDown = (e) => {
        if (e.key === 'Escape') {
            finishAndDispose(false);
        }
    };

    ui.skipBtn?.addEventListener('click', () => finishAndDispose(false));
    window.addEventListener('keydown', onKeyDown);
    holdTimer = setTimeout(() => finishAndDispose(false), REDUCED_HOLD_MS);

    return { cleanup: finishAndDispose };
}

export async function playSicmaSplash(options = {}) {
    const root = document.getElementById('sicma-splash-root');
    if (!root) {
        return false;
    }

    const token = ++sessionSeq;

    if (activeSession) {
        activeSession.cleanup(true);
        activeSession = null;
    }

    markSplashSeen();

    const webmUrl = root.dataset.videoWebm || '/videos/sicma-splash-3d.webm';
    const mp4Url = root.dataset.videoMp4 || '/videos/sicma-splash-3d.mp4';
    const posterUrl = root.dataset.posterUrl || '/videos/sicma-splash-poster.webp';

    const prefersReduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    if (prefersReduced && !options.forceFullMotion) {
        activeSession = playPosterMode(root, posterUrl);
        return true;
    }

    const ui = buildOverlayDom(root);
    if (!ui.mediaEl || !ui.stageEl) {
        activeSession = playPosterMode(root, posterUrl);
        return true;
    }

    if (posterUrl) {
        const posterUnderlay = document.createElement('img');
        posterUnderlay.src = posterUrl;
        posterUnderlay.alt = '';
        posterUnderlay.decoding = 'async';
        posterUnderlay.className = 'pointer-events-none absolute inset-0 block h-full w-full object-contain';
        ui.mediaEl.appendChild(posterUnderlay);
    }

    const video = document.createElement('video');
    video.setAttribute('data-splash-video', '');
    video.autoplay = true;
    video.muted = true;
    video.defaultMuted = true;
    video.playsInline = true;
    video.preload = 'auto';
    video.poster = posterUrl;
    video.className = 'relative z-10 block h-full w-full object-contain transition-opacity duration-200';
    video.style.opacity = '0';

    const srcWebm = document.createElement('source');
    srcWebm.src = webmUrl;
    srcWebm.type = 'video/webm';

    const srcMp4 = document.createElement('source');
    srcMp4.src = mp4Url;
    srcMp4.type = 'video/mp4';

    video.append(srcMp4, srcWebm);
    ui.mediaEl.appendChild(video);

    let rafId = null;
    let watchdogTimer = null;
    let fallbackTimer = null;
    let fadeTimer = null;
    let cleanedUp = false;
    let closing = false;
    let usingFallbackPoster = false;

    let pointerTargetX = 0;
    let pointerTargetY = 0;
    let pointerCurrentX = 0;
    let pointerCurrentY = 0;
    let lastFrameMs = 0;

    const finishAndDispose = (immediate = false) => {
        if (cleanedUp) return;
        if (closing && !immediate) return;
        closing = true;

        if (rafId !== null) {
            cancelAnimationFrame(rafId);
            rafId = null;
        }
        clearTimeout(watchdogTimer);
        clearTimeout(fallbackTimer);
        clearTimeout(fadeTimer);

        window.removeEventListener('pointermove', onPointerMove);
        window.removeEventListener('keydown', onKeyDown);
        video.removeEventListener('loadeddata', revealVideo);
        video.removeEventListener('playing', revealVideo);
        video.removeEventListener('ended', handleEnded);
        video.removeEventListener('error', switchToPosterFallback);
        srcWebm.removeEventListener('error', switchToPosterFallback);

        const tearDown = () => {
            if (cleanedUp) return;
            cleanedUp = true;
            releaseVideoElement(video);
            resetSplashRoot(root);
            if (activeSession?.cleanup === finishAndDispose) {
                activeSession = null;
            }
        };

        if (immediate) {
            tearDown();
            return;
        }

        root.style.opacity = '0';
        fadeTimer = setTimeout(tearDown, FADE_OUT_MS);
    };

    const revealVideo = () => {
        if (cleanedUp || closing || usingFallbackPoster) return;
        video.style.opacity = '1';
    };

    const handleEnded = () => {
        if (cleanedUp || closing || token !== sessionSeq) return;
        if (ui.progressEl) {
            ui.progressEl.style.transform = 'scaleX(1)';
        }
        finishAndDispose(false);
    };

    const switchToPosterFallback = () => {
        if (cleanedUp || closing || usingFallbackPoster || token !== sessionSeq) return;
        usingFallbackPoster = true;
        releaseVideoElement(video);
        renderPosterFallback(ui, posterUrl);
        clearTimeout(fallbackTimer);
        fallbackTimer = setTimeout(() => finishAndDispose(false), REDUCED_HOLD_MS);
    };

    const onPointerMove = (e) => {
        if (e.pointerType === 'touch') return;
        const nx = (e.clientX / Math.max(1, window.innerWidth)) * 2 - 1;
        const ny = (e.clientY / Math.max(1, window.innerHeight)) * 2 - 1;
        pointerTargetX = Math.max(-1, Math.min(1, nx));
        pointerTargetY = Math.max(-1, Math.min(1, ny));
    };

    const onKeyDown = (e) => {
        if (e.key === 'Escape') {
            finishAndDispose(false);
        }
    };

    ui.skipBtn?.addEventListener('click', () => finishAndDispose(false));
    window.addEventListener('pointermove', onPointerMove, { passive: true });
    window.addEventListener('keydown', onKeyDown);

    video.addEventListener('loadeddata', revealVideo);
    video.addEventListener('playing', revealVideo);
    video.addEventListener('ended', handleEnded);
    video.addEventListener('error', switchToPosterFallback);
    srcWebm.addEventListener('error', switchToPosterFallback);

    activeSession = { cleanup: finishAndDispose };

    watchdogTimer = setTimeout(() => {
        if (!cleanedUp && !closing && token === sessionSeq) {
            finishAndDispose(false);
        }
    }, MAX_WATCHDOG_MS);

    try {
        const playResult = video.play?.();
        if (playResult && typeof playResult.catch === 'function') {
            playResult.catch(() => {
                if (!cleanedUp && !closing && token === sessionSeq) {
                    switchToPosterFallback();
                }
            });
        }
    } catch {
        switchToPosterFallback();
    }

    const tick = (now) => {
        if (cleanedUp || closing || token !== sessionSeq) return;

        const dtSec = lastFrameMs > 0 ? Math.min(0.05, (now - lastFrameMs) * 0.001) : 0.016;
        lastFrameMs = now;

        if (
            pointerTargetX !== 0
            || pointerTargetY !== 0
            || Math.abs(pointerCurrentX) > 0.0005
            || Math.abs(pointerCurrentY) > 0.0005
        ) {
            const damp = 1 - Math.exp(-dtSec * 8.5);
            pointerCurrentX += (pointerTargetX - pointerCurrentX) * damp;
            pointerCurrentY += (pointerTargetY - pointerCurrentY) * damp;

            const rotY = pointerCurrentX * 5.0;
            const rotX = -pointerCurrentY * 4.5;
            ui.stageEl.style.transform = `perspective(1100px) rotateX(${rotX.toFixed(3)}deg) rotateY(${rotY.toFixed(3)}deg)`;
        }

        if (!usingFallbackPoster && Number.isFinite(video.duration) && video.duration > 0) {
            const normT = Math.max(0, Math.min(1, video.currentTime / video.duration));
            if (ui.progressEl) {
                ui.progressEl.style.transform = `scaleX(${normT.toFixed(4)})`;
            }
            if (video.currentTime >= Math.max(0.5, video.duration - 0.25)) {
                if (ui.progressEl) {
                    ui.progressEl.style.transform = 'scaleX(1)';
                }
                finishAndDispose(false);
                return;
            }
        }

        rafId = requestAnimationFrame(tick);
    };

    rafId = requestAnimationFrame(tick);
    return true;
}

export function installSplashScreen() {
    // Tetap diekspor untuk kompatibilitas impor langsung
}
