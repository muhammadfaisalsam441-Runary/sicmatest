import assert from 'node:assert/strict';
import { test } from 'node:test';
import { canBackgroundRefresh, captureFields, restoreFields, hasFormChanges, isEditingField, navigationState, formActionUrl, registerStatusPolling } from '../../resources/js/navigation.js';

function input(name, value = '', extra = {}) {
    return { tagName: 'INPUT', type: 'text', name, value, defaultValue: value, ...extra };
}

function select(name, options, multiple = false) {
    return {
        tagName: 'SELECT', type: multiple ? 'select-multiple' : 'select-one', name, multiple,
        options: options.map(([value, selected = false, defaultSelected = selected]) => ({ value, selected, defaultSelected })),
    };
}

test('field action pada formulir massal tidak menggantikan URL pengiriman', () => {
    const page = 'https://sicma.test/admin/dosen';
    const form = {
        action: input('action', 'set_target_sks', { type: 'hidden' }),
        getAttribute: (name) => name === 'action' ? '/admin/dosen/batch' : null,
        ownerDocument: { URL: page, baseURI: page },
    };

    assert.equal(formActionUrl(form).href, 'https://sicma.test/admin/dosen/batch');
    assert.equal(formActionUrl(form, { getAttribute: () => '/admin/dosen/preview' }).href, 'https://sicma.test/admin/dosen/preview');
    assert.equal(formActionUrl(form, { getAttribute: () => '' }).href, page);
});

test('isian edit dipulihkan tanpa menimpa token, password, dan field milik server', () => {
    const original = { elements: [
        input('name', 'Nama awal'), input('code', 'KELAS-C'),
        input('_token', 'csrf-lama', { type: 'hidden' }),
        input('password', 'rahasia', { type: 'password' }),
        input('proof', '', { type: 'file' }),
        input('server_status', 'lama', { readOnly: true }),
        input('otp', '123456', { autocomplete: 'one-time-code' }),
    ] };
    original.elements[0].value = 'Tekom C';
    const saved = captureFields(original);
    assert.equal(saved.length, 2);
    const returned = { elements: [
        input('name', 'Nama terbaru server'), input('code', 'KELAS-C'),
        input('_token', 'csrf-baru', { type: 'hidden' }),
        input('password', '', { type: 'password' }),
        input('proof', '', { type: 'file' }),
        input('server_status', 'baru', { readOnly: true }),
        input('otp', '', { autocomplete: 'one-time-code' }),
    ] };
    restoreFields(returned, saved);
    assert.equal(returned.elements[0].value, 'Tekom C');
    assert.equal(returned.elements[2].value, 'csrf-baru');
    assert.equal(returned.elements[3].value, '');
    assert.equal(returned.elements[5].value, 'baru');
    assert.equal(returned.elements[6].value, '');
});

test('nama field sama pada form berbeda tidak mencampurkan isian', () => {
    const first = { elements: [input('name', 'Dosen A')] };
    const second = { elements: [input('name', 'Dosen B')] };
    first.elements[0].value = 'Dosen A diedit';
    const saved = captureFields(first);
    restoreFields(first, saved);
    assert.equal(first.elements[0].value, 'Dosen A diedit');
    assert.equal(second.elements[0].value, 'Dosen B');
    assert.equal(hasFormChanges(second), false);
});

test('checkbox, radio, textarea, dan pilihan banyak tetap sesuai edit', () => {
    const form = { elements: [
        input('active', '1', { type: 'checkbox', checked: false, defaultChecked: true }),
        input('scope', 'kelas', { type: 'radio', checked: true, defaultChecked: false }),
        input('note', 'Catatan', { tagName: 'TEXTAREA', type: 'textarea' }),
        select('rooms[]', [['AE101', false, true], ['AE102', true, false], ['AE103', true, false]], true),
    ] };
    const saved = captureFields(form);
    form.elements[0].checked = true;
    form.elements[1].checked = false;
    form.elements[2].value = '';
    form.elements[3].options.forEach((option) => { option.selected = false; });
    restoreFields(form, saved);
    assert.equal(form.elements[0].checked, false);
    assert.equal(form.elements[1].checked, true);
    assert.equal(form.elements[2].value, 'Catatan');
    assert.deepEqual(form.elements[3].options.filter((option) => option.selected).map((option) => option.value), ['AE102', 'AE103']);
});

test('form yang belum berubah tidak dianggap kotor; perubahan yang dibatalkan bersih lagi', () => {
    const form = { elements: [input('name', 'Awal'), select('status', [['active', true], ['inactive', false]])] };
    assert.equal(hasFormChanges(form), false);
    form.elements[0].value = 'Edit';
    assert.equal(hasFormChanges(form), true);
    form.elements[0].value = 'Awal';
    assert.equal(hasFormChanges(form), false);
    form.elements[1].options[0].selected = false;
    form.elements[1].options[1].selected = true;
    assert.equal(hasFormChanges(form), true);
});

test('pilihan default pertama tidak memblokir refresh otomatis tanpa alasan', () => {
    const form = { elements: [select('kind', [['all', true, false], ['available', false, false]])] };
    assert.equal(hasFormChanges(form), false);
});

test('perubahan struktur form tidak mengisi field yang namanya berbeda', () => {
    const saved = captureFields({ elements: [input('name', 'Data lama')] });
    const next = { elements: [input('new_field', 'Data baru server')] };
    assert.deepEqual(restoreFields(next, saved), []);
    assert.equal(next.elements[0].value, 'Data baru server');
});

test('menyimpan pada halaman yang sama mempertahankan posisi edit, tujuan baru mulai dari atas', () => {
    const previous = { scroll: [0, 1420], drafts: new Map() };
    assert.deepEqual(navigationState('https://sicma.test/admin/akademik?tab=kelas', 'https://sicma.test/admin/akademik?tab=kelas', { keepScroll: true }, previous).point, [0, 1420]);
    assert.deepEqual(navigationState('https://sicma.test/keti/ruangan/1', 'https://sicma.test/keti/reservasi/7', { keepScroll: true }, previous).point, [0, 0]);
});

test('kembali ke tab memulihkan posisi dan drafnya, tombol Back memakai posisi riwayat', () => {
    const stored = { scroll: [20, 800], drafts: new Map([['form', ['draf']]]) };
    const result = navigationState('https://sicma.test/admin/dosen', 'https://sicma.test/admin/akademik?tab=kelas', {}, { scroll: [0, 0] }, stored);
    assert.deepEqual(result.point, [20, 800]);
    assert.equal(result.state.drafts.get('form')[0], 'draf');
    assert.deepEqual(navigationState('https://sicma.test/admin/dosen', 'https://sicma.test/admin/akademik', { scroll: [0, 650] }, {}, stored).point, [0, 650]);
});

test('polling hanya memperbarui tampilan saat status berubah dan melepaskan timer ketika ditutup', async () => {
    const originals = { fetch: globalThis.fetch, document: globalThis.document, setInterval: globalThis.setInterval, clearInterval: globalThis.clearInterval };
    let factory;
    let refreshes = 0;
    let stopped;
    let status = { lock_version: 1, status: 'booked' };
    try {
        globalThis.document = { visibilityState: 'visible' };
        globalThis.fetch = async () => ({ ok: true, json: async () => status });
        globalThis.setInterval = () => 42;
        globalThis.clearInterval = (id) => { stopped = id; };
        registerStatusPolling({ data: (_, provider) => { factory = provider; } }, { refresh: () => { refreshes++; } });
        const component = factory({ url: '/status', interval: 1000, version: 1, status: 'booked' });
        component.init();
        await component.poll();
        assert.equal(refreshes, 0);
        status = { lock_version: 2, status: 'in_use' };
        await component.poll();
        assert.equal(refreshes, 1);
        const controller = new AbortController();
        component.controller = controller;
        component.destroy();
        assert.equal(stopped, 42);
        assert.equal(controller.signal.aborted, true);
    } finally {
        Object.assign(globalThis, originals);
    }
});

test('refresh latar belakang ditahan saat modal terbuka, tab tersembunyi, atau pengguna sedang mengetik', () => {
    const cleanForm = { elements: [input('q', '')] };
    assert.equal(canBackgroundRefresh({
        visibilityState: 'visible',
        activeElement: { tagName: 'BODY' },
        forms: [cleanForm],
        querySelectorAll: () => [],
    }), true);

    assert.equal(canBackgroundRefresh({
        visibilityState: 'hidden',
        activeElement: { tagName: 'BODY' },
        forms: [cleanForm],
        querySelectorAll: () => [],
    }), false);

    assert.equal(canBackgroundRefresh({
        visibilityState: 'visible',
        activeElement: input('q', ''),
        forms: [cleanForm],
        querySelectorAll: () => [],
    }), false);

    assert.equal(canBackgroundRefresh({
        visibilityState: 'visible',
        activeElement: { tagName: 'INPUT', type: 'submit' },
        forms: [cleanForm],
        querySelectorAll: () => [{ getClientRects: () => [{ width: 400 }] }],
    }), false);

    assert.equal(isEditingField(input('q', 'AE 107')), true);
    assert.equal(isEditingField({ tagName: 'INPUT', type: 'submit' }), false);
});
