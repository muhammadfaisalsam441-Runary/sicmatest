export function assetKey(doc) {
    return Array.from(doc.head.querySelectorAll('script[type="module"][src], link[rel="stylesheet"][href]'))
        .map((node) => node.getAttribute('src') || node.getAttribute('href')).join('|');
}

export function normalizeSignature(html) {
    return (html || '')
        .replace(/Data pukul \d{2}[.:]\d{2}(?:\s*WITA)?/g, 'Data pukul')
        .replace(/Sekarang • \d{2}[.:]\d{2}(?:\s*WITA)?/g, 'Sekarang');
}

export function pageSignature(doc) {
    return [
        normalizeSignature(doc.querySelector('header')?.innerHTML),
        normalizeSignature(doc.querySelector('main')?.innerHTML),
    ].join('||');
}

export function syncAttributes(target, source) {
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

export function morphNode(current, next, silent = false, Alpine = globalThis.Alpine) {
    if (current.nodeType !== next.nodeType) {
        if (current.nodeType === 1) Alpine?.destroyTree(current);
        current.replaceWith(next);
        if (next.nodeType === 1) Alpine?.initTree(next);
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
        Alpine?.destroyTree(current);
        current.replaceWith(next);
        Alpine?.initTree(next);
        return;
    }

    if (current.tagName === 'SVG') {
        if (current.outerHTML !== next.outerHTML) current.replaceWith(next);
        return;
    }

    if (silent && current.hasAttribute('data-modal-name')) return;

    if (current.hasAttribute('data-ambient-backdrop')
        && current.getAttribute('data-ambient-backdrop') === next.getAttribute('data-ambient-backdrop')) {
        return;
    }

    const hasAlpine = current.hasAttribute('x-data') || next.hasAttribute('x-data');
    if (hasAlpine) {
        if (!silent || current.outerHTML !== next.outerHTML) {
            Alpine?.destroyTree(current);
            current.replaceWith(next);
            Alpine?.initTree(next);
        }
        return;
    }

    syncAttributes(current, next);
    morphChildren(current, next, silent, Alpine);
}

export function morphChildren(target, source, silent = false, Alpine = globalThis.Alpine) {
    const currentNodes = Array.from(target.childNodes);
    const nextNodes = Array.from(source.childNodes);
    const common = Math.min(currentNodes.length, nextNodes.length);

    for (let i = 0; i < common; i++) {
        morphNode(currentNodes[i], nextNodes[i], silent, Alpine);
    }
    for (let i = common; i < nextNodes.length; i++) {
        const added = nextNodes[i];
        target.appendChild(added);
        if (added.nodeType === 1) Alpine?.initTree(added);
    }
    for (let i = currentNodes.length - 1; i >= common; i--) {
        const removed = currentNodes[i];
        if (silent && removed.nodeType === 1 && removed.hasAttribute('data-modal-name')) continue;
        if (removed.nodeType === 1) Alpine?.destroyTree(removed);
        removed.remove();
    }
}
