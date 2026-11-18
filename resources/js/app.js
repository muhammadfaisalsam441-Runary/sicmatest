import Alpine from 'alpinejs';
import { createIcons } from 'lucide';
import * as icons from './icons.js';
import { installPortalNavigation, registerStatusPolling } from './navigation.js';
import { installRealtimePush } from './realtime.js';
import { applyTableDensity, filterDrawer, readTableDensity, tableDensityToggle, tableScrollBox } from './alpine/table-ui.js';
import { teachingManager } from './alpine/teaching-manager.js';
import { lecturerManager } from './alpine/lecturer-manager.js';
import { roomManager } from './alpine/room-manager.js';
import { classManager } from './alpine/class-manager.js';
import { cohortArchiveModal, cohortGenerateModal } from './alpine/cohort-modals.js';
import { bookingModal } from './alpine/booking-modal.js';
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
installRealtimePush(navigation);

applyTableDensity(readTableDensity());
document.addEventListener('sicma:navigated', () => applyTableDensity(readTableDensity()));

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

// Shift+Click rentang checkbox pada baris tabel yang tampil.
const lastCheckedByTable = new WeakMap();
document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement) || target.type !== 'checkbox') return;
    const tbody = target.closest('tbody');
    const table = target.closest('table');
    if (!tbody || !table) return;
    const previous = lastCheckedByTable.get(table);
    if (event.shiftKey && previous && previous !== target && table.contains(previous)) {
        const checkboxes = Array.from(tbody.querySelectorAll('tr input[type="checkbox"]')).filter((cb) => {
            const row = cb.closest('tr');
            return row && row.offsetParent !== null && !cb.disabled;
        });
        const start = checkboxes.indexOf(previous);
        const end = checkboxes.indexOf(target);
        if (start !== -1 && end !== -1) {
            const [from, to] = start < end ? [start, end] : [end, start];
            for (let i = from; i <= to; i++) {
                const cb = checkboxes[i];
                if (cb !== target && cb.checked !== target.checked) {
                    cb.checked = target.checked;
                    cb.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
        }
    }
    lastCheckedByTable.set(table, target);
});

Alpine.data('tableDensityToggle', tableDensityToggle);
Alpine.data('tableScrollBox', tableScrollBox);
Alpine.data('filterDrawer', filterDrawer);
Alpine.data('teachingManager', teachingManager);
Alpine.data('lecturerManager', lecturerManager);
Alpine.data('roomManager', roomManager);
Alpine.data('classManager', classManager);
Alpine.data('cohortArchiveModal', cohortArchiveModal);
Alpine.data('cohortGenerateModal', cohortGenerateModal);
Alpine.data('bookingModal', bookingModal);
Alpine.data('sicmaToast', sicmaToast);

Alpine.start();

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
