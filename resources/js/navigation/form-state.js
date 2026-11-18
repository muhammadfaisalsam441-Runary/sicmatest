const ignoredTypes = new Set(['hidden', 'password', 'file', 'submit', 'button', 'reset']);

export function controls(form) {
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
    if (isEditingField(doc.activeElement)) return false;
    if (doc.querySelector?.('[data-editing="true"]')) return false;
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

export function notifyToast(detail, win = typeof window !== 'undefined' ? window : undefined) {
    if (!win || typeof win.dispatchEvent !== 'function' || typeof CustomEvent !== 'function') {
        return false;
    }
    win.dispatchEvent(new CustomEvent('sicma:toast', { detail }));
    return true;
}

export function clearToasts(scope = null, win = typeof window !== 'undefined' ? window : undefined) {
    if (!win || typeof win.dispatchEvent !== 'function' || typeof CustomEvent !== 'function') {
        return false;
    }
    win.dispatchEvent(new CustomEvent('sicma:toast-clear', { detail: { scope } }));
    return true;
}
