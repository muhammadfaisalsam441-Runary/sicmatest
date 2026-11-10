const ignoredTypes = new Set(['hidden', 'password', 'file', 'submit', 'button', 'reset']);

function controls(form) {
    return Array.from(form.elements).filter((field) => ['INPUT', 'SELECT', 'TEXTAREA'].includes(field.tagName)
        && field.name && !field.disabled && !field.readOnly
        && !ignoredTypes.has(field.type) && field.autocomplete !== 'one-time-code');
}

export function captureFields(form, defaults = false) {
    return controls(form).map((field, index) => {
        let value;
        if (field.type === 'checkbox' || field.type === 'radio') {
            value = defaults ? field.defaultChecked : field.checked;
        } else if (field.tagName === 'SELECT') {
            const selected = Array.from(field.options).filter((option) => defaults ? option.defaultSelected : option.selected);
            value = selected.map((option) => option.value);
            if (defaults && !field.multiple && value.length === 0 && field.options.length) {
                value = [field.options[0].value];
            }
        } else {
            value = defaults ? field.defaultValue : field.value;
        }
        return { name: field.name, type: field.type, index, value };
    });
}

export function restoreFields(form, saved) {
    const fields = controls(form);
    const restored = [];
    for (const state of saved) {
        const field = fields[state.index];
        if (!field || field.name !== state.name || field.type !== state.type) continue;
        if (field.type === 'checkbox' || field.type === 'radio') {
            field.checked = state.value;
        } else if (field.tagName === 'SELECT') {
            for (const option of field.options) option.selected = state.value.includes(option.value);
        } else {
            field.value = state.value;
        }
        restored.push(field);
    }
    return restored;
}

export function hasFormChanges(form) {
    return JSON.stringify(captureFields(form)) !== JSON.stringify(captureFields(form, true));
}

export function isEditingField(element) {
    if (!element) return false;
    if (element.isContentEditable) return true;
    if (!['INPUT', 'SELECT', 'TEXTAREA'].includes(element.tagName)) return false;
    return !['button', 'submit', 'reset'].includes(element.type);
}

export function canBackgroundRefresh(doc = document) {
    if (doc.visibilityState === 'hidden') return false;
    if ( isEditingField(doc.activeElement)) return false;
    if (Array.from(doc.querySelectorAll?.('[data-modal-name]') || []).some((modal) => modal.getClientRects?.().length)) {
        return false;
    }
    return !Array.from(doc.forms || []).some((form) => hasFormChanges(form)
        || Array.from(form.elements).some((field) => field.type === 'file' && field.files?.length));
}

export function navigationState(currentUrl, targetUrl, options, previous, stored) {
    const keep = options.keepScroll && new URL(targetUrl).pathname === new URL(currentUrl).pathname;
    const state = keep ? previous : stored;
    return { state, point: options.scroll || state?.scroll || [0, 0] };
}

export function formActionUrl(form, submitter) {
    const action = submitter?.getAttribute('formaction') ?? form.getAttribute('action');
    return new URL(action || form.ownerDocument.URL, form.ownerDocument.baseURI);
}

export function installPortalNavigation(Alpine, renderIcons) {
    if (!document.body.dataset.portal) return null;

    const pages = new Map();
    let currentUrl = location.href;
    let request = null;
    let rendering = false;
    let scrollTimer = null;
    history.scrollRestoration = 'manual';

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

    function notice(message, form = null, success = false) {
        const root = form || document.querySelector('main') || document.body;
        root.querySelector('[data-client-notice]')?.remove();
        const alert = document.createElement('p');
        alert.dataset.clientNotice = '';
        alert.setAttribute('role', success ? 'status' : 'alert');
        alert.className = success
            ? 'mb-4 rounded-control border border-success bg-success-soft px-4 py-3 text-sm text-success'
            : 'mb-4 rounded-control border border-danger bg-danger-soft px-4 py-3 text-sm text-danger';
        alert.textContent = message;
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
        notice(data.message || 'Periksa kembali isian yang ditandai.', form);
        for (const [name, messages] of Object.entries(data.errors || {})) {
            const field = Array.from(form.elements).find((input) =>
                input.name.replace(/\[([^\]]+)\]/g, '.$1').replace(/\[\]$/, '') === name);
            if (!field) continue;
            const error = document.createElement('p');
            error.dataset.clientError = '';
            error.className = 'text-xs font-medium text-danger';
            error.textContent = Array.isArray(messages) ? messages.join(' ') : String(messages);
            field.setAttribute('aria-invalid', 'true');
            field.dataset.clientInvalid = '';
            field.classList.add('border-danger');
            field.insertAdjacentElement('afterend', error);
        }
    }

    function assetKey(doc) {
        return Array.from(doc.head.querySelectorAll('script[type="module"][src], link[rel="stylesheet"][href]'))
            .map((node) => node.getAttribute('src') || node.getAttribute('href')).join('|');
    }

    function normalizeSignature(html) {
        return (html || '')
            .replace(/Data pukul \d{2}[.:]\d{2}(?:\s*WITA)?/g, 'Data pukul')
            .replace(/Sekarang • \d{2}[.:]\d{2}(?:\s*WITA)?/g, 'Sekarang');
    }

    function pageSignature(doc) {
        return [
            normalizeSignature(doc.querySelector('header')?.innerHTML),
            normalizeSignature(doc.querySelector('main')?.innerHTML),
        ].join('||');
    }

    let lastSignature = pageSignature(document);

    function syncAttributes(target, source) {
        for (const attr of Array.from(target.attributes)) {
            if (attr.name !== 'style' && !source.hasAttribute(attr.name)) {
                target.removeAttribute(attr.name);
            }
        }
        for (const attr of Array.from(source.attributes)) {
            if (attr.name === 'x-cloak') continue;
            if (target.getAttribute(attr.name) !== attr.value) {
                target.setAttribute(attr.name, attr.value);
            }
        }
        if (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA') {
            if (target.type === 'checkbox' || target.type === 'radio') target.checked = source.checked;
            else if (target.type !== 'file') target.value = source.value;
        } else if (target.tagName === 'OPTION') {
            target.selected = source.selected;
        }
    }

    function morphNode(current, next, silent = false) {
        if (current.nodeType !== next.nodeType) {
            if (current.nodeType === 1) Alpine.destroyTree(current);
            current.replaceWith(next);
            if (next.nodeType === 1) Alpine.initTree(next);
            return;
        }
        if (current.nodeType === 3 || current.nodeType === 8) {
            if (current.nodeValue !== next.nodeValue) current.nodeValue = next.nodeValue;
            return;
        }
        if (current.nodeType !== 1) return;

        if (current.tagName !== next.tagName
            || current.id !== next.id
            || (current.dataset?.modalName || '') !== (next.dataset?.modalName || '')) {
            Alpine.destroyTree(current);
            current.replaceWith(next);
            Alpine.initTree(next);
            return;
        }

        if (current.tagName === 'SVG') {
            if (current.outerHTML !== next.outerHTML) current.replaceWith(next);
            return;
        }

        if (silent && current.hasAttribute('data-modal-name')) return;

        const hasAlpine = current.hasAttribute('x-data') || next.hasAttribute('x-data');
        if (hasAlpine) {
            if (!silent || current.outerHTML !== next.outerHTML) {
                Alpine.destroyTree(current);
                current.replaceWith(next);
                Alpine.initTree(next);
            }
            return;
        }

        syncAttributes(current, next);
        morphChildren(current, next, silent);
    }

    function morphChildren(target, source, silent = false) {
        const currentNodes = Array.from(target.childNodes);
        const nextNodes = Array.from(source.childNodes);
        const common = Math.min(currentNodes.length, nextNodes.length);

        for (let i = 0; i < common; i++) {
            morphNode(currentNodes[i], nextNodes[i], silent);
        }
        for (let i = common; i < nextNodes.length; i++) {
            const added = nextNodes[i];
            target.appendChild(added);
            if (added.nodeType === 1) Alpine.initTree(added);
        }
        for (let i = currentNodes.length - 1; i >= common; i--) {
            const removed = currentNodes[i];
            if (silent && removed.nodeType === 1 && removed.hasAttribute('data-modal-name')) continue;
            if (removed.nodeType === 1) Alpine.destroyTree(removed);
            removed.remove();
        }
    }

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
                    if (currentHeader && nextHeader) morphChildren(currentHeader, nextHeader, true);
                    morphChildren(currentMain, nextMain, true);
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

        rendering = true;
        Alpine.mutateDom(() => {
            syncAttributes(document.body, next.body);
            morphChildren(document.body, next.body, false);
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
    }

    async function visit(url, options = {}) {
        const previous = rememberPage();
        if (options.history !== 'pop' && !options.silent) rememberHistory(previous.scroll);
        request?.controller.abort();
        const active = { controller: new AbortController(), method: options.method || 'GET', silent: Boolean(options.silent) };
        request = active;
        try {
            const response = await fetch(url, {
                method: active.method, body: options.body,
                credentials: 'same-origin', signal: active.controller.signal,
                headers: { Accept: options.form ? 'application/json, text/html' : 'text/html' },
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
                    if (options.form) showErrors(options.form, data);
                    else if (!options.silent) notice(data.message || 'Halaman belum dapat dimuat.');
                    return;
                }
                request = null;
                const destination = new URL(data.redirect_url || currentUrl, location.href);
                if (destination.origin !== location.origin || !['http:', 'https:'].includes(destination.protocol)) {
                    notice('Perubahan tersimpan, tetapi tujuan halaman tidak dapat dibuka.', options.form);
                    return;
                }
                await visit(destination.href, { history: 'replace', keepScroll: true, submitted: options.submitted });
                notice(data.message || 'Perubahan berhasil disimpan.', null, true);
                return;
            }
            if (!response.ok) {
                if (!options.silent) {
                    notice(response.status === 419
                        ? 'Sesi formulir sudah kedaluwarsa. Muat ulang halaman sebelum mencoba kembali.'
                        : 'Permintaan belum berhasil. Isian formulirmu tetap tersedia.', options.form);
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
                notice(options.form
                    ? 'Hasil penyimpanan belum dapat dipastikan. Periksa koneksi dan data sebelum mencoba kembali.'
                    : 'Halaman belum dapat dimuat. Isianmu tetap tersedia; silakan coba lagi.', options.form);
            }
        } finally {
            if (request === active) {
                request = null;
            }
        }
    }

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
        const buttons = Array.from(form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]'))
            .filter((button) => !button.disabled);
        for (const button of buttons) button.disabled = true;
        form.setAttribute('aria-busy', 'true');
        form.inert = true;
        try {
            await visit(url.href, {
                method, body: data, form, history: 'replace', keepScroll: true, submitted: formKey(form),
                modal: form.closest('[data-modal-name]')?.dataset.modalName,
            });
        } finally {
            form.removeAttribute('aria-busy');
            form.inert = false;
            for (const button of buttons) button.disabled = false;
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
        if (request || rendering || !canBackgroundRefresh(document)) return;
        return visit(currentUrl, { history: 'replace', keepScroll: true, silent: true });
    }

    setInterval(() => {
        void refresh();
    }, 25000);

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') void refresh();
    });

    return { refresh };
}

export function registerStatusPolling(Alpine, navigation) {
    Alpine.data('statusPolling', (options) => ({
        elapsed: options.elapsed ?? 0,
        progress: options.progress ?? { stage: 'Menunggu antrean', total: 0, placed: 0 },
        timer: null,
        controller: null,
        init() {
            this.timer = setInterval(() => this.poll(), options.interval);
        },
        async poll() {
            if (this.controller || document.visibilityState === 'hidden') return;
            const controller = new AbortController();
            this.controller = controller;
            try {
                const response = await fetch(options.url, {
                    headers: { Accept: 'application/json' }, signal: controller.signal,
                });
                if (!response.ok) return;
                const data = await response.json();
                this.elapsed = data.elapsed ?? this.elapsed;
                this.progress = data.progress ?? this.progress;
                const changed = options.job
                    ? !['queued', 'running'].includes(data.status)
                    : data.lock_version !== options.version || data.status !== options.status;
                if (changed && !controller.signal.aborted) {
                    options.version = data.lock_version ?? options.version;
                    options.status = data.status ?? options.status;
                    if (navigation) await navigation.refresh();
                    else location.reload();
                }
            } catch {
                // A later poll retries without interrupting the current edit.
            } finally {
                this.controller = null;
            }
        },
        destroy() {
            clearInterval(this.timer);
            this.controller?.abort();
        },
    }));
}
