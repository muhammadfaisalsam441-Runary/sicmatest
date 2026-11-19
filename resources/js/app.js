import Alpine from 'alpinejs';
import { createIcons } from 'lucide';
import * as icons from './icons.js';
import { installPortalNavigation, registerStatusPolling } from './navigation.js';
import { sicmaToast } from './alpine/toast.js';

window.Alpine = Alpine;
window.renderIcons = (root = document) => createIcons({ icons, root });
window.renderIcons();

let supportsDisplayTransition;
function canTransitionDisplay() {
    if (supportsDisplayTransition !== undefined) return supportsDisplayTransition;
    if (!window.CSS?.supports?.('transition-behavior', 'allow-discrete') || !document.body) return false;
    const probe = document.createElement('div');
    probe.style.cssText = 'transition: display 1s allow-discrete !important; display: block;';
    document.body.appendChild(probe);
    getComputedStyle(probe).display;
    probe.style.display = 'none';
    supportsDisplayTransition = getComputedStyle(probe).display === 'block';
    probe.remove();
    return supportsDisplayTransition;
}

window.sicmaCloseDialog = async function (dialog) {
    if (!dialog || !dialog.open) return;
    if (!(window.CSS?.supports?.('overlay', 'auto') && canTransitionDisplay())) {
        dialog.setAttribute('data-closing', '');
        const animations = dialog.getAnimations({ subtree: true });
        if (animations.length > 0) {
            await Promise.race([
                Promise.allSettled(animations.map((animation) => animation.finished)),
                new Promise((resolve) => setTimeout(resolve, 2000)),
            ]);
        }
        if (!dialog.hasAttribute('data-closing')) return;
        dialog.removeAttribute('data-closing');
    }
    if (dialog.open) dialog.close();
};

const navigation = installPortalNavigation(Alpine, (root = document) => window.renderIcons(root));
registerStatusPolling(Alpine, navigation);

function scheduleRealtimePush(nav) {
    if (document.body?.dataset?.portal === 'auth') return;
    if (!document.querySelector('meta[name="reverb-config"]')) return;

    const start = () => {
        void import('./realtime.js')
            .then(({ installRealtimePush }) => installRealtimePush(nav))
            .catch(() => {});
    };

    if (typeof window.requestIdleCallback === 'function') {
        window.requestIdleCallback(start, { timeout: 2500 });
    } else {
        window.setTimeout(start, 400);
    }
}

scheduleRealtimePush(navigation);

// Shortcut `/` untuk memfokuskan kolom pencarian tabel aktif.
document.addEventListener('keydown', (event) => {
    if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey) return;
    const active = document.activeElement;
    if (active && (['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName) || active.isContentEditable)) return;
    const container = document.getElementById('main-content') || document;
    for (const input of container.querySelectorAll('input[data-table-search], input[type="search"], input[name="q"]')) {
        if (input.offsetParent !== null && !input.disabled) {
            event.preventDefault();
            input.focus();
            if (typeof input.select === 'function') input.select();
            break;
        }
    }
});

Alpine.data('sicmaToast', sicmaToast);

async function bootPortal() {
    const portal = document.body?.dataset?.portal;
    if (portal === 'admin') {
        const { registerAdminPortal } = await import('./portals/admin.js');
        registerAdminPortal(Alpine);
    } else if (portal === 'keti') {
        const { registerKetiPortal } = await import('./portals/keti.js');
        registerKetiPortal(Alpine);
    }

    Alpine.start();
}

void bootPortal();

function shouldAutoPlaySplash() {
    if (new URLSearchParams(window.location.search).get('splash') === '1') return true;
    try {
        return window.sessionStorage.getItem('sicma.splash.seen') !== '1';
    } catch {
        return false;
    }
}

async function launchSicmaSplash(options = {}) {
    const root = document.getElementById('sicma-splash-root');
    if (root && root.style.display === 'none') {
        root.style.display = 'flex';
        root.style.backgroundColor = '#041022';
        root.style.opacity = '1';
    }
    try {
        const { playSicmaSplash } = await import('./splash-screen.js');
        return playSicmaSplash(options);
    } catch {
        if (root) {
            root.innerHTML = '';
            root.style.display = 'none';
            root.setAttribute('aria-hidden', 'true');
        }
        return false;
    }
}

window.playSicmaSplash = (options = {}) => launchSicmaSplash({ forceFullMotion: true, ...options });

document.addEventListener('click', (event) => {
    const trigger = event.target.closest?.('[data-replay-splash]');
    if (!trigger) return;
    event.preventDefault();
    void window.playSicmaSplash();
});

if (document.getElementById('sicma-splash-root') && shouldAutoPlaySplash()) {
    void launchSicmaSplash();
}

const BG_MOTION_STORAGE_KEY = 'sicma.bgMotion';

function isBgMotionPaused() {
    return document.documentElement?.dataset.bgMotion === 'paused';
}

function syncBgMotionButtons(root = document) {
    const paused = isBgMotionPaused();
    const nextLabel = paused ? 'Putar animasi latar' : 'Jeda animasi latar';

    for (const button of root.querySelectorAll('[data-toggle-bg-motion]')) {
        button.setAttribute('aria-label', nextLabel);
        const labelNode = button.querySelector('[data-bg-motion-label]');
        if (labelNode) {
            labelNode.textContent = nextLabel;
        }
    }
}

try {
    if (window.localStorage.getItem(BG_MOTION_STORAGE_KEY) === 'paused') {
        document.documentElement.dataset.bgMotion = 'paused';
    }
} catch {
    // Abaikan error penyimpanan jika localStorage diblokir.
}

syncBgMotionButtons();
document.addEventListener('sicma:navigated', () => syncBgMotionButtons());

document.addEventListener('click', (event) => {
    const trigger = event.target.closest?.('[data-toggle-bg-motion]');
    if (!trigger) return;

    const nextPaused = !isBgMotionPaused();
    if (nextPaused) {
        document.documentElement.dataset.bgMotion = 'paused';
    } else {
        delete document.documentElement.dataset.bgMotion;
    }

    try {
        window.localStorage.setItem(BG_MOTION_STORAGE_KEY, nextPaused ? 'paused' : 'playing');
    } catch {
        // Abaikan error penyimpanan jika localStorage diblokir.
    }

    syncBgMotionButtons();
});

// Mouse-tracking spotlight pada grid kartu ruangan (hanya perangkat pointer presisi/desktop).
if (window.matchMedia?.('(hover: hover) and (pointer: fine)').matches) {
    let spotFrame = 0;
    let spotEvent = null;
    let activeSpotGrid = null;

    function clearGridSpotlight(grid) {
        if (!grid) return;
        for (const card of grid.querySelectorAll('[data-room-card]')) {
            card.removeAttribute('data-spot-active');
            card.style.setProperty('--spot-x', '-999px');
            card.style.setProperty('--spot-y', '-999px');
        }
    }

    document.addEventListener('pointermove', (event) => {
        if (event.pointerType === 'touch') return;
        const card = event.target?.closest?.('[data-room-card]');
        if (!card) {
            if (activeSpotGrid) {
                clearGridSpotlight(activeSpotGrid);
                activeSpotGrid = null;
            }
            return;
        }

        const grid = card.closest('.grid') || card.parentElement;
        if (activeSpotGrid && activeSpotGrid !== grid) {
            clearGridSpotlight(activeSpotGrid);
        }
        activeSpotGrid = grid;
        spotEvent = { x: event.clientX, y: event.clientY, grid, card };

        if (spotFrame) return;
        spotFrame = window.requestAnimationFrame(() => {
            spotFrame = 0;
            const current = spotEvent;
            if (!current) return;
            const cards = current.grid ? current.grid.querySelectorAll('[data-room-card]') : [current.card];
            for (const item of cards) {
                const rect = item.getBoundingClientRect();
                item.style.setProperty('--spot-x', `${current.x - rect.left}px`);
                item.style.setProperty('--spot-y', `${current.y - rect.top}px`);
                item.setAttribute('data-spot-active', 'true');
            }
        });
    }, { passive: true });

    document.addEventListener('pointerleave', (event) => {
        if (event.target === document || event.target === document.documentElement) {
            clearGridSpotlight(activeSpotGrid);
            activeSpotGrid = null;
        }
    });

    document.addEventListener('sicma:navigated', () => {
        clearGridSpotlight(activeSpotGrid);
        activeSpotGrid = null;
    });
}
