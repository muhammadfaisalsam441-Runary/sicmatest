/**
 * Buat salinan mendalam untuk data tabel yang dapat diedit agar bisa dibatalkan.
 */
export function cloneTableData(source) {
    return JSON.parse(JSON.stringify(source || {}));
}

/**
 * Periksa apakah seluruh ID baris pada daftar saat ini sudah terpilih.
 */
export function isAllSelected(selectedIds, rowIds) {
    const ids = rowIds.map(Number);
    if (ids.length === 0) return false;
    const current = selectedIds.map(Number);
    return ids.every((id) => current.includes(id));
}

/**
 * Tambah atau hapus seluruh ID baris pada daftar saat ini ke/dari pilihan.
 */
export function toggleSelectAll(selectedIds, rowIds, checked) {
    const ids = rowIds.map(Number);
    const current = selectedIds.map(Number);
    return checked
        ? [...new Set([...current, ...ids])]
        : current.filter((id) => !ids.includes(id));
}

/**
 * Kembalikan ID terpilih jika ada, atau seluruh ID baris yang tampil.
 */
export function targetIds(selectedIds, visibleIds) {
    const selected = selectedIds.map(Number);
    return selected.length > 0 ? selected : visibleIds;
}

/**
 * Kirim elemen form HTML dengan requestSubmit() bila tersedia.
 */
export function submitFormElement(form) {
    if (!form) return;
    if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
    } else {
        form.submit();
    }
}

/**
 * Jalankan persiapan aksi massal lalu kirim `$refs.batchForm` pada tick berikutnya.
 */
export function submitBatch(component, prepare) {
    if (component.selectedIds.length === 0) return;
    if (typeof prepare === 'function') {
        prepare();
    }
    component.$nextTick(() => submitFormElement(component.$refs.batchForm));
}

/**
 * Isi field numerik positif (> 0) pada baris target, opsional hanya yang masih kosong.
 */
export function fillPositiveField(records, ids, field, rawValue, { onlyEmpty = false } = {}) {
    const val = Number(rawValue);
    if (!val || val <= 0) return;
    ids.forEach((id) => {
        const record = records[id];
        if (!record) return;
        const current = record[field];
        if (!onlyEmpty || current === '' || current === null || current === undefined || Number(current) <= 0) {
            record[field] = val;
        }
    });
}

/**
 * Mixin metode pemilihan baris tabel untuk komponen Alpine.
 */
export function bulkSelectionMethods(getRowIds) {
    return {
        targetIds() {
            return targetIds(this.selectedIds, getRowIds.call(this).map(Number));
        },

        isAllSelected() {
            return isAllSelected(this.selectedIds, getRowIds.call(this));
        },

        toggleSelectAll(checked) {
            this.selectedIds = toggleSelectAll(this.selectedIds, getRowIds.call(this), checked);
        },
    };
}

/**
 * Mixin mode edit massal dan pembatalan perubahan untuk komponen Alpine.
 */
export function bulkEditMethods(dataKey, originalKey, { renderIconsOnEnter = false } = {}) {
    return {
        toggleEditMode() {
            if (this.editMode) {
                this.cancelEditMode();
            } else {
                this.editMode = true;
                if (renderIconsOnEnter) {
                    this.$nextTick(() => window.renderIcons?.());
                }
            }
        },

        cancelEditMode() {
            this[dataKey] = cloneTableData(this[originalKey]);
            this.editMode = false;
        },
    };
}
