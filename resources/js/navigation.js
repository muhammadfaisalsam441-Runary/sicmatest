import {
    canBackgroundRefresh,
    captureFields,
    clearToasts,
    formActionUrl,
    hasFormChanges,
    isEditingField,
    navigationState,
    notifyToast,
    restoreFields,
} from './navigation/form-state.js';
import {
    assetKey,
    morphChildren,
    pageSignature,
    syncAttributes,
} from './navigation/dom-morph.js';
import { registerStatusPolling } from './navigation/status-polling.js';

export {
    canBackgroundRefresh,
    captureFields,
    clearToasts,
    formActionUrl,
    hasFormChanges,
    isEditingField,
    navigationState,
    notifyToast,
    registerStatusPolling,
    restoreFields,
};

export function installPortalNavigation(Alpine, renderIcons) {
    if (!document.body.dataset.portal) return null;

    const PUBLIC_ROUTE_ORDER = ['/', '/ruangan', '/panduan', '/bantuan'];
    const pages = new Map();
    const prefetched = new Map();
    let currentUrl = location.href;
    let request = null;
    let rendering = false;
    let scrollTimer = null;
    let pendingRealtimeRefresh = false;
    let realtimeTimer = null;
    history.scrollRestoration = 'manual';

    function resolveTransitionDirection(fromUrl, toUrl, options) {
        if (options.keepScroll || options.silent) return null;
        if (options.history === 'pop') return 'backward';
        try {
            const fromPath = new URL(fromUrl, location.href).pathname;
            const toPath = new URL(toUrl, location.href).pathname;
            const fromIdx = PUBLIC_ROUTE_ORDER.indexOf(fromPath);
            const toIdx = PUBLIC_ROUTE_ORDER.indexOf(toPath);
            if (fromIdx !== -1 && toIdx !== -1 && fromIdx !== toIdx) {
                return toIdx > fromIdx ? 'forward' : 'backward';
            }
        } catch {
            return null;
        }
        return 'forward';
    }

    function prefetchPage(rawHref) {
        if (!rawHref || navigator.connection?.saveData) return;
        let url;
        try {
            url = new URL(rawHref, location.href);
        } catch {
            return;
        }
        if (url.origin !== location.origin || !['http:', 'https:'].includes(url.protocol)) return;
        if (url.href === currentUrl) return;
        const cached = prefetched.get(url.href);
        if (cached && Date.now() - cached.time < 8000) return;

        const promise = fetch(url.href, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'text/html' },
        })
            .then(async (res) => {
                const type = res.headers.get('Content-Type') || '';
                if (!res.ok || !type.includes('text/html')) return null;
                return { html: await res.text(), finalUrl: res.url || url.href };
            })
            .catch(() => null);

        prefetched.delete(url.href);
        prefetched.set(url.href, { time: Date.now(), promise });
        if (prefetched.size > 8) prefetched.delete(prefetched.keys().next().value);
    }

    function formKey(form) {
        const action = formActionUrl(form).href;
        const peers = Array.from(document.forms).filter((other) => formActionUrl(other).href === action && other.method === form.method);
        return [action, form.querySelector('[name="_method"]')?.value || form.method,
            form.closest('[data-modal-name]')?.dataset.modalName || form.id || peers.indexOf(form)].join('|');
    }

    function rememberPage() {
        const drafts = new Map();
        for (const form of document.forms) {
            if (hasFormChanges(form)) drafts.set(formKey(form), captureFields(form));
        }
        const openModal = Array.from(document.querySelectorAll('[data-modal-name]'))
            .find((modal) => modal.getClientRects().length)?.dataset.modalName;
        const state = { scroll: [scrollX, scrollY], drafts, openModal };
        pages.delete(currentUrl);
        pages.set(currentUrl, state);
        if (pages.size > 20) pages.delete(pages.keys().next().value);
        return state;
    }

    function rememberHistory(scroll) {
        history.replaceState({ ...history.state, sicmaScroll: scroll }, '', currentUrl);
    }

    function notice(message, form = null, outcome = false, title = null) {
        const type = outcome === true ? 'success' : (outcome === 'warning' ? 'warning' : 'error');
        const resolvedTitle = title || (type === 'success'
            ? 'Berhasil'
            : (type === 'warning' ? 'Perhatian' : 'Permintaan belum berhasil'));

        if (document.querySelector('[data-toast-host]') && notifyToast({
            type,
            title: resolvedTitle,
            message,
            scope: form ? 'form' : 'navigation',
        })) {
            document.querySelectorAll('[data-client-notice]').forEach((el) => el.remove());
            return;
        }

        const target = form && !form.classList?.contains('hidden') ? form : null;
        const root = target || document.querySelector('main') || document.body;
        root.querySelector('[data-client-notice]')?.remove();
        const alert = document.createElement('p');
        alert.dataset.clientNotice = '';
        alert.setAttribute('role', type === 'success' ? 'status' : 'alert');
        alert.className = type === 'success'
            ? 'mb-4 rounded-control border border-success bg-success-soft px-4 py-3 text-sm text-success'
            : 'mb-4 rounded-control border border-danger bg-danger-soft px-4 py-3 text-sm text-danger';
        alert.textContent = message || resolvedTitle;
        root.prepend(alert);
    }

    function showErrors(form, data) {
        for (const previous of form.querySelectorAll('[data-client-error]')) previous.remove();
        for (const field of form.elements) {
            if (field.dataset?.clientInvalid !== undefined) {
                field.removeAttribute('aria-invalid');
                field.removeAttribute('data-client-invalid');
                field.classList.remove('border-danger');
            }
        }
        let firstInvalid = null;
        for (const [name, messages] of Object.entries(data.errors || {})) {
            const field = Array.from(form.elements).find((input) =>
                input.name.replace(/\[([^\]]+)\]/g, '.$1').replace(/\[\]$/, '') === name);
            if (!field) continue;
            if (!firstInvalid) firstInvalid = field;
            const error = document.createElement('p');
            error.dataset.clientError = '';
            error.className = 'text-xs font-medium text-danger';
            error.textContent = Array.isArray(messages) ? messages.join(' ') : String(messages);
            field.setAttribute('aria-invalid', 'true');
            field.dataset.clientInvalid = '';
            field.classList.add('border-danger');
            field.insertAdjacentElement('afterend', error);
        }
        firstInvalid?.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' });
        notice(data.message || 'Periksa kembali isian yang ditandai.', form, false, 'Data belum sesuai');
    }

    let lastSignature = pageSignature(document);

    async function render(next, url, options, previous) {
        if (next.body.dataset.portal !== document.body.dataset.portal
            || next.body.dataset.portalUser !== document.body.dataset.portalUser
            || assetKey(next) !== assetKey(document)) {
            if (options.silent) return;
            pages.clear();
            location.assign(url);
            return;
        }

        const csrf = next.querySelector('meta[name="csrf-token"]');
        if (csrf) document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', csrf.content);

        renderIcons(next.body);
        const nextSignature = pageSignature(next);

        if (options.silent) {
            if (!canBackgroundRefresh(document) || nextSignature === lastSignature) return;
            const currentMain = document.querySelector('main');
            const nextMain = next.querySelector('main');
            const currentHeader = document.querySelector('header');
            const nextHeader = next.querySelector('header');

            if (currentMain && nextMain) {
                rendering = true;
                Alpine.mutateDom(() => {
                    if (currentHeader && nextHeader) morphChildren(currentHeader, nextHeader, true, Alpine);
                    morphChildren(currentMain, nextMain, true, Alpine);
                });
                lastSignature = nextSignature;
                document.title = next.title;
                rendering = false;
                document.dispatchEvent(new CustomEvent('sicma:refreshed'));
                return;
            }
        }

        const { state, point } = navigationState(currentUrl, url, options, previous, pages.get(url));
        const failed = next.body.dataset.validationErrors === 'true';
        if (options.submitted && !failed) previous.drafts.delete(options.submitted);

        const applyDomUpdate = () => {
            rendering = true;
            Alpine.mutateDom(() => {
                syncAttributes(document.body, next.body);
                morphChildren(document.body, next.body, false, Alpine);
            });
            lastSignature = nextSignature;
            document.title = next.title;

            if (options.history !== 'pop') {
                history[options.history === 'replace' ? 'replaceState' : 'pushState']({ sicmaScroll: point }, '', url);
            }
            currentUrl = url;
            window.scrollTo({ left: point[0], top: point[1], behavior: 'instant' });
            if (state) {
                for (const form of document.forms) {
                    const saved = state.drafts.get(formKey(form));
                    if (!saved) continue;
                    for (const field of restoreFields(form, saved)) {
                        field.dispatchEvent(new Event('input', { bubbles: true }));
                        field.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
                const modal = failed ? options.modal : (!options.submitted ? state.openModal : null);
                if (modal) window.dispatchEvent(new CustomEvent('open-modal', { detail: modal }));
            }
            rendering = false;
            rememberHistory(point);
            document.dispatchEvent(new CustomEvent('sicma:navigated'));
        };

        const focusViewTarget = () => {
            if (options.keepScroll || document.querySelector('dialog[open]')) return;
            const heading = document.querySelector('#main-content h1, #main-content');
            if (!heading) return;
            if (heading.tagName === 'H1' && !heading.hasAttribute('tabindex')) {
                heading.setAttribute('tabindex', '-1');
            }
            heading.focus({ preventScroll: true });
        };

        const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        if (typeof document.startViewTransition === 'function' && !reduceMotion && !document.querySelector('dialog[open]')) {
            const direction = resolveTransitionDirection(currentUrl, url, options);
            let transition;
            if (direction) {
                try {
                    transition = document.startViewTransition({ update: applyDomUpdate, types: [direction] });
                } catch {
                    transition = document.startViewTransition(applyDomUpdate);
                }
            } else {
                transition = document.startViewTransition(applyDomUpdate);
            }
            await transition.updateCallbackDone;
            void transition.finished.finally(focusViewTarget);
        } else {
            applyDomUpdate();
            focusViewTarget();
        }
    }

    async function visit(url, options = {}) {
        const previous = rememberPage();
        if (options.history !== 'pop' && !options.silent) rememberHistory(previous.scroll);
        request?.controller.abort();
        const active = { controller: new AbortController(), method: options.method || 'GET', silent: Boolean(options.silent) };
        request = active;
        try {
            if (!options.silent && !options.form && active.method === 'GET') {
                const cached = prefetched.get(url);
                if (cached && Date.now() - cached.time < 8000) {
                    const resolved = await cached.promise;
                    if (request !== active) return;
                    if (resolved) {
                        prefetched.delete(url);
                        const next = new DOMParser().parseFromString(resolved.html, 'text/html');
                        await render(next, resolved.finalUrl, options, previous);
                        return;
                    }
                }
            }

            const response = await fetch(url, {
                method: active.method, body: options.body,
                credentials: 'same-origin', signal: active.controller.signal,
                headers: { Accept: options.form ? 'text/html, application/json;q=0.9' : 'text/html' },
            });
            if (request !== active) return;
            if (response.status === 401) {
                if (options.silent) return;
                pages.clear();
                location.assign(document.body.dataset.loginUrl);
                return;
            }
            const type = response.headers.get('Content-Type') || '';
            if (type.includes('application/json')) {
                const data = await response.json();
                if (!response.ok) {
                    if (options.form) {
                        showErrors(options.form, data);
                        options.form.dispatchEvent(new CustomEvent('sicma:form-error', { bubbles: true, detail: data }));
                    } else if (!options.silent) {
                        notice(data.message || '', null, false, 'Halaman belum dapat dimuat');
                    }
                    return;
                }
                request = null;
                const destination = new URL(data.redirect_url || currentUrl, location.href);
                if (destination.origin !== location.origin || !['http:', 'https:'].includes(destination.protocol)) {
                    notice('Tujuan halaman tidak dapat dibuka.', options.form, 'warning', 'Perubahan tersimpan');
                    return;
                }
                await visit(destination.href, { history: 'replace', keepScroll: true, submitted: options.submitted });
                notice('', null, true, data.message || 'Perubahan berhasil disimpan.');
                return;
            }
            if (!response.ok) {
                if (!options.silent) {
                    if (response.status === 419) {
                        notice('Muat ulang halaman sebelum mencoba kembali.', options.form, false, 'Sesi formulir kedaluwarsa');
                    } else {
                        notice('Isian formulirmu tetap tersedia; silakan coba lagi.', options.form, false, 'Permintaan belum berhasil');
                    }
                }
                return;
            }
            if (!type.includes('text/html') || response.headers.get('Content-Disposition')?.includes('attachment')) {
                if (!options.silent) location.assign(response.url || url);
                return;
            }
            const html = await response.text();
            if (request !== active) return;
            const next = new DOMParser().parseFromString(html, 'text/html');
            await render(next, response.url || url, options, previous);
        } catch (error) {
            if (error.name !== 'AbortError' && !options.silent) {
                if (options.form) {
                    notice('Periksa koneksi dan data sebelum mencoba kembali.', options.form, false, 'Hasil penyimpanan belum dapat dipastikan');
                } else {
                    notice('Isianmu tetap tersedia; silakan coba lagi.', null, false, 'Halaman belum dapat dimuat');
                }
            }
        } finally {
            if (request === active) {
                request = null;
            }
        }
    }

    document.addEventListener('pointerenter', (event) => {
        const link = event.target.closest?.('a[data-prefetch][href]');
        if (link) prefetchPage(link.href);
    }, true);

    document.addEventListener('focusin', (event) => {
        const link = event.target.closest?.('a[data-prefetch][href]');
        if (link) prefetchPage(link.href);
    });

    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey
            || event.shiftKey || event.altKey || link.hasAttribute('download')
            || link.hasAttribute('data-native') || (link.target && link.target !== '_self')) return;
        const url = new URL(link.href, location.href);
        if (url.origin !== location.origin || !['http:', 'https:'].includes(url.protocol)
            || (url.pathname === location.pathname && url.search === location.search && url.hash)) return;
        event.preventDefault();
        if (request !== null && !request.silent && request.method !== 'GET') return;
        void visit(url.href, url.href === currentUrl ? { history: 'replace', keepScroll: true } : {});
    });

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || event.defaultPrevented || form.hasAttribute('data-native')
            || (form.target && form.target !== '_self') || form.method === 'dialog') return;
        const submitter = event.submitter;
        const method = (submitter?.getAttribute('formmethod') || form.method || 'GET').toUpperCase();
        const url = formActionUrl(form, submitter);
        if (url.origin !== location.origin || !['GET', 'POST'].includes(method)) return;
        event.preventDefault();
        if (request !== null && !request.silent) return;
        const data = new FormData(form, submitter);
        if (method === 'GET') {
            url.search = new URLSearchParams(Array.from(data, ([key, value]) => [key, value instanceof File ? value.name : value])).toString();
            await visit(url.href, {
                keepScroll: url.pathname === location.pathname,
                submitted: formKey(form),
                modal: form.closest('[data-modal-name]')?.dataset.modalName,
            });
            return;
        }
        const externalButtons = form.id
            ? Array.from(document.querySelectorAll(`button[form="${CSS.escape(form.id)}"], input[form="${CSS.escape(form.id)}"]`))
            : [];
        const buttons = Array.from(new Set([
            ...form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]'),
            ...externalButtons,
        ])).filter((button) => !button.disabled);
        for (const button of buttons) button.disabled = true;
        form.setAttribute('aria-busy', 'true');
        form.inert = true;
        clearToasts('form');
        clearToasts('navigation');
        try {
            await visit(url.href, {
                method, body: data, form, history: 'replace', keepScroll: true, submitted: formKey(form),
                modal: form.closest('[data-modal-name]')?.dataset.modalName,
            });
        } finally {
            form.removeAttribute('aria-busy');
            form.inert = false;
            for (const button of buttons) button.disabled = false;
            form.dispatchEvent(new CustomEvent('sicma:form-complete', { bubbles: true }));
        }
    });

    window.addEventListener('scroll', () => {
        clearTimeout(scrollTimer);
        scrollTimer = setTimeout(() => {
            if (!rendering && currentUrl === location.href) rememberHistory([scrollX, scrollY]);
        }, 150);
    }, { passive: true });

    if (history.state?.sicmaScroll) {
        Alpine.nextTick(() => window.scrollTo({ left: history.state.sicmaScroll[0], top: history.state.sicmaScroll[1], behavior: 'instant' }));
    }

    window.addEventListener('popstate', (event) => {
        if (request !== null && !request.silent && request.method !== 'GET') {
            location.reload();
            return;
        }
        void visit(location.href, { history: 'pop', scroll: pages.get(location.href)?.scroll || event.state?.sicmaScroll });
    });

    function refresh() {
        if (request || rendering || !canBackgroundRefresh(document)) return false;
        pendingRealtimeRefresh = false;
        const activeVisit = visit(currentUrl, { history: 'replace', keepScroll: true, silent: true });
        return typeof activeVisit?.finally === 'function'
            ? activeVisit.finally(() => {
                if (pendingRealtimeRefresh && canBackgroundRefresh(document)) void refresh();
            })
            : activeVisit;
    }

    function triggerRealtimeRefresh(delay = 80) {
        pendingRealtimeRefresh = true;
        clearTimeout(realtimeTimer);
        realtimeTimer = setTimeout(() => {
            realtimeTimer = null;
            if (canBackgroundRefresh(document)) void refresh();
        }, delay);
    }

    setInterval(() => {
        void refresh();
    }, 25000);

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') void refresh();
    });

    document.addEventListener('sicma:realtime-update', () => triggerRealtimeRefresh());

    document.addEventListener('focusout', () => {
        if (pendingRealtimeRefresh) triggerRealtimeRefresh(120);
    });

    document.addEventListener('close', () => {
        if (pendingRealtimeRefresh) triggerRealtimeRefresh(120);
    }, true);

    return { refresh, triggerRealtimeRefresh };
}
