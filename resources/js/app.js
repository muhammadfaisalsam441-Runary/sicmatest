import Alpine from 'alpinejs';
import { createIcons } from 'lucide';
import * as icons from './icons';
import { installPortalNavigation, registerStatusPolling } from './navigation';
import { installRealtimePush } from './realtime';

window.Alpine = Alpine;

/**
 * Render ulang ikon Lucide (<i data-lucide="nama-ikon">) di dalam elemen tertentu.
 * Dipanggil otomatis saat halaman dimuat; panggil lagi setelah konten dinamis ditambahkan.
 */
window.renderIcons = (root = document) => createIcons({ icons, root });

window.renderIcons();

let supportsDisplayTransition;
function canTransitionDisplay() {
    if (supportsDisplayTransition !== undefined) return supportsDisplayTransition;
    if (!window.CSS?.supports?.('transition-behavior', 'allow-discrete') || !document.body) {
        return false;
    }
    const probe = document.createElement('div');
    probe.style.cssText = 'transition: display 1s allow-discrete !important; display: block;';
    document.body.appendChild(probe);
    getComputedStyle(probe).display;
    probe.style.display = 'none';
    supportsDisplayTransition = getComputedStyle(probe).display === 'block';
    probe.remove();
    return supportsDisplayTransition;
}

function supportsTopLayerExit() {
    return Boolean(window.CSS?.supports?.('overlay', 'auto') && canTransitionDisplay());
}

window.sicmaCloseDialog = async function (dialog) {
    if (!dialog || !dialog.open) return;
    if (!supportsTopLayerExit()) {
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

const TABLE_DENSITY_KEY = 'sicma.tableDensity';

function readTableDensity() {
    try {
        return localStorage.getItem(TABLE_DENSITY_KEY) === 'compact' ? 'compact' : 'normal';
    } catch {
        return 'normal';
    }
}

function applyTableDensity(density) {
    const value = density === 'compact' ? 'compact' : 'normal';
    if (document.documentElement) {
        document.documentElement.dataset.uiDensity = value;
    }
    if (document.body) {
        document.body.dataset.tableDensity = value;
    }
    return value;
}

applyTableDensity(readTableDensity());
document.addEventListener('sicma:navigated', () => applyTableDensity(readTableDensity()));

// Shortcut `/` untuk memfokuskan kolom pencarian tabel aktif.
document.addEventListener('keydown', (event) => {
    if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey) return;
    const active = document.activeElement;
    if (active && (['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName) || active.isContentEditable)) {
        return;
    }
    const container = document.getElementById('main-content') || document;
    const candidates = container.querySelectorAll('input[data-table-search], input[type="search"], input[name="q"]');
    for (const input of candidates) {
        if (input.offsetParent !== null && !input.disabled) {
            event.preventDefault();
            input.focus();
            if (typeof input.select === 'function') {
                input.select();
            }
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
            const desired = target.checked;
            for (let i = from; i <= to; i++) {
                const cb = checkboxes[i];
                if (cb !== target && cb.checked !== desired) {
                    cb.checked = desired;
                    cb.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
        }
    }
    lastCheckedByTable.set(table, target);
});

Alpine.data('tableDensityToggle', () => ({
    density: readTableDensity(),

    init() {
        this.density = applyTableDensity(readTableDensity());
        this._onChanged = (e) => {
            this.density = e.detail?.density ?? readTableDensity();
        };
        window.addEventListener('sicma:density-changed', this._onChanged);
    },

    destroy() {
        if (this._onChanged) {
            window.removeEventListener('sicma:density-changed', this._onChanged);
        }
    },

    toggle() {
        const next = this.density === 'compact' ? 'normal' : 'compact';
        this.density = applyTableDensity(next);
        try {
            localStorage.setItem(TABLE_DENSITY_KEY, this.density);
        } catch {
            // Abaikan error penyimpanan jika browser memblokir localStorage.
        }
        window.dispatchEvent(new CustomEvent('sicma:density-changed', { detail: { density: this.density } }));
        this.$nextTick(() => window.renderIcons?.(this.$el));
    },
}));

Alpine.data('teachingManager', (pageOfferingIds, lecturerOptionsByOffering, allLecturers = []) => ({
    selectedIds: [],
    quickModalOpen: false,
    quickLecturerId: '',
    quickPhase: 'full',
    quickSearch: '',
    editingAssignmentId: null,
    batchModalOpen: false,
    batchSearch: '',
    rekapModalOpen: false,
    lecturerSearch: '',
    activeOffering: { id: null, course: '', classGroup: '', sks: 0, role: 'lead', team: [] },

    get quickLecturers() {
        const list = lecturerOptionsByOffering[this.activeOffering.id] ?? [];
        const q = this.quickSearch.trim().toLowerCase();
        return q ? list.filter((l) => l.name.toLowerCase().includes(q)) : list;
    },

    get otherLecturers() {
        const recommendedIds = new Set((lecturerOptionsByOffering[this.activeOffering.id] ?? []).map((l) => Number(l.id)));
        const list = allLecturers.filter((l) => !recommendedIds.has(Number(l.id)));
        const q = this.quickSearch.trim().toLowerCase();
        return q ? list.filter((l) => l.name.toLowerCase().includes(q)) : list;
    },

    isAllSelected() {
        const current = this.selectedIds.map(Number);
        return pageOfferingIds.length > 0 && pageOfferingIds.every((id) => current.includes(Number(id)));
    },

    toggleSelectAll(checked) {
        const pageIds = pageOfferingIds.map(Number);
        const current = this.selectedIds.map(Number);
        this.selectedIds = checked
            ? [...new Set([...current, ...pageIds])]
            : current.filter((id) => !pageIds.includes(id));
    },

    openQuickAssign(id, course, classGroup, role = 'lead', sks = 0, team = [], phase = null) {
        this.editingAssignmentId = null;
        this.quickLecturerId = '';
        const hasBeforeUts = (team ?? []).some((m) => m.phase === 'before_uts');
        this.quickPhase = phase || (role === 'partner' && hasBeforeUts ? 'after_uts' : 'full');
        this.quickSearch = '';
        this.activeOffering = { id, course, classGroup, sks, role, team: team ?? [] };
        this.quickModalOpen = true;
        this.$nextTick(() => window.renderIcons());
    },

    openEditAssignment(offering, assignment) {
        this.editingAssignmentId = assignment.id;
        this.quickLecturerId = String(assignment.lecturer_id);
        this.quickPhase = assignment.phase || 'full';
        this.quickSearch = '';
        this.activeOffering = {
            id: offering.id,
            course: offering.course,
            classGroup: offering.classGroup,
            sks: offering.sks ?? 0,
            role: assignment.role || 'lead',
            team: offering.team ?? [],
        };
        this.quickModalOpen = true;
        this.$nextTick(() => window.renderIcons());
    },

    switchToCreate(role = 'partner') {
        this.editingAssignmentId = null;
        this.quickLecturerId = '';
        const hasBeforeUts = (this.activeOffering.team ?? []).some((m) => m.phase === 'before_uts');
        this.quickPhase = role === 'partner' && hasBeforeUts ? 'after_uts' : 'full';
        this.activeOffering.role = role;
    },
}));

Alpine.data('lecturerManager', (pageLecturerIds = [], initialLecturers = {}) => {
    const cloneLecturers = (src) => JSON.parse(JSON.stringify(src || {}));

    return {
        selectedIds: [],
        editMode: false,
        fillTargetSks: 12,
        fillMaxSks: 16,
        originalLecturers: cloneLecturers(initialLecturers),
        lecturersData: cloneLecturers(initialLecturers),
        formModalOpen: false,
        batchTargetModalOpen: false,
        importModalOpen: false,
        batchAction: '',
        courseQuery: '',
        formLecturer: { course_ids: [] },

        get visibleIds() {
            return pageLecturerIds.map(Number);
        },

        targetIds() {
            const selected = this.selectedIds.map(Number);
            return selected.length > 0 ? selected : this.visibleIds;
        },

        toggleEditMode() {
            if (this.editMode) {
                this.cancelEditMode();
            } else {
                this.editMode = true;
                this.$nextTick(() => window.renderIcons?.());
            }
        },

        cancelEditMode() {
            this.lecturersData = cloneLecturers(this.originalLecturers);
            this.editMode = false;
        },

        applyFillEmptySks() {
            const tVal = this.fillTargetSks !== '' && this.fillTargetSks !== null ? Number(this.fillTargetSks) : null;
            const mVal = this.fillMaxSks !== '' && this.fillMaxSks !== null ? Number(this.fillMaxSks) : null;
            this.targetIds().forEach((id) => {
                const l = this.lecturersData[id];
                if (!l) return;
                if (tVal !== null && (l.target_sks === '' || l.target_sks === null || l.target_sks === undefined)) {
                    l.target_sks = tVal;
                }
                if (mVal !== null && (l.max_sks === '' || l.max_sks === null || l.max_sks === undefined)) {
                    l.max_sks = mVal;
                }
            });
        },

        applyFillAllSks() {
            const tVal = this.fillTargetSks !== '' && this.fillTargetSks !== null ? Number(this.fillTargetSks) : null;
            const mVal = this.fillMaxSks !== '' && this.fillMaxSks !== null ? Number(this.fillMaxSks) : null;
            this.targetIds().forEach((id) => {
                const l = this.lecturersData[id];
                if (!l) return;
                if (tVal !== null) l.target_sks = tVal;
                if (mVal !== null) l.max_sks = mVal;
            });
        },

        get selectedCourseIds() {
            return [...new Set([...(this.formLecturer.source_course_ids ?? []), ...this.formLecturer.course_ids])];
        },

        toggleCourse(id, checked) {
            if (this.formLecturer.source_course_ids?.includes(id)) return;
            this.formLecturer.course_ids = checked
                ? [...new Set([...this.formLecturer.course_ids, id])]
                : this.formLecturer.course_ids.filter((courseId) => courseId !== id);
        },

        isAllSelected() {
            const current = this.selectedIds.map(Number);
            return pageLecturerIds.length > 0 && pageLecturerIds.every((id) => current.includes(Number(id)));
        },

        toggleSelectAll(checked) {
            const pageIds = pageLecturerIds.map(Number);
            const current = this.selectedIds.map(Number);
            this.selectedIds = checked
                ? [...new Set([...current, ...pageIds])]
                : current.filter((id) => !pageIds.includes(id));
        },

        openCreateModal() {
            this.openEditModal({ homebase: 'jtik', profile_status: 'verified', target_sks: '12', max_sks: '16', course_ids: [] });
        },

        openEditModal(data) {
            this.formLecturer = { ...data };
            this.courseQuery = '';
            this.formModalOpen = true;
            this.$nextTick(() => window.renderIcons());
        },

        submitBatch(action) {
            if (this.selectedIds.length === 0) return;
            this.batchAction = action;
            this.$nextTick(() => {
                const form = this.$refs.batchForm;
                if (!form) return;
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            });
        },
    };
});

Alpine.data('roomManager', (pageRoomIds = [], defaults = {}, initialRooms = {}) => {
    const cloneRooms = (src) => JSON.parse(JSON.stringify(src || {}));
    const parseEditId = (val) => {
        if (val !== undefined && val !== null && val !== '') {
            const num = Number(val);
            return Number.isFinite(num) && num > 0 ? num : null;
        }
        return null;
    };
    const inferredFormId = typeof defaults.form === 'string' && /^edit-(\d+)$/.test(defaults.form)
        ? Number(defaults.form.match(/^edit-(\d+)$/)[1])
        : null;
    const initialRoomId = parseEditId(defaults.roomId ?? defaults.id) ?? inferredFormId;

    return {
        editMode: false,
        fillEmptyValue: 40,
        originalRooms: cloneRooms(initialRooms),
        roomsData: cloneRooms(initialRooms),
        selectedIds: defaults.selectedIds ?? [],
        formModalOpen: Boolean(defaults.formModalOpen),
        closureModalOpen: Boolean(defaults.closureModalOpen),
        deleteModalOpen: false,
        batchFacilityModalOpen: false,
        batchCapacityModalOpen: Boolean(defaults.batchCapacityModalOpen),
        batchCapacity: defaults.batchCapacity ?? '',
        batchCapacityConfirming: false,
        batchAction: '',
        batchParams: { building: '', type: '' },
        closureRoomId: defaults.closureRoomId ?? '',
        closureStart: defaults.closureStart ?? '',
        closureEnd: defaults.closureEnd ?? '',
        closureReason: defaults.closureReason ?? '',
        roomToDelete: { id: null, name: '' },
        formRoom: {
            id: initialRoomId,
            name: defaults.name ?? '',
            code: defaults.code ?? '',
            type: defaults.type ?? 'classroom',
            capacity: defaults.capacity ?? '',
            floor: defaults.floor ?? '',
            building: defaults.building ?? '',
            facilities: defaults.facilities ?? '',
            is_bookable: defaults.is_bookable ?? true,
        },

        get visibleIds() {
            return pageRoomIds.map(Number);
        },

        targetIds() {
            const selected = this.selectedIds.map(Number);
            return selected.length > 0 ? selected : this.visibleIds;
        },

        isAllSelected() {
            const current = this.selectedIds.map(Number);
            return pageRoomIds.length > 0 && pageRoomIds.every((id) => current.includes(Number(id)));
        },

        toggleSelectAll(checked) {
            const pageIds = pageRoomIds.map(Number);
            const current = this.selectedIds.map(Number);
            this.selectedIds = checked
                ? [...new Set([...current, ...pageIds])]
                : current.filter((id) => !pageIds.includes(id));
        },

        toggleEditMode() {
            if (this.editMode) {
                this.cancelEditMode();
            } else {
                this.editMode = true;
            }
        },

        cancelEditMode() {
            this.roomsData = cloneRooms(this.originalRooms);
            this.editMode = false;
        },

        applyFillEmpty() {
            const val = Number(this.fillEmptyValue);
            if (!val || val <= 0) return;
            this.targetIds().forEach((id) => {
                const room = this.roomsData[id];
                if (!room) return;
                if (room.capacity === '' || room.capacity === null || room.capacity === undefined || Number(room.capacity) <= 0) {
                    room.capacity = val;
                }
            });
        },

        applyFillAll() {
            const val = Number(this.fillEmptyValue);
            if (!val || val <= 0) return;
            this.targetIds().forEach((id) => {
                const room = this.roomsData[id];
                if (room) {
                    room.capacity = val;
                }
            });
        },

        openCreateModal() {
            this.formRoom = {
                id: null,
                name: '',
                code: '',
                type: 'classroom',
                capacity: '',
                floor: '',
                building: '',
                facilities: '',
                is_bookable: true,
            };
            this.formModalOpen = true;
            this.$nextTick(() => window.renderIcons());
        },

        openEditModal(data) {
            this.formRoom = {
                id: data.id,
                name: data.name || '',
                code: data.code || '',
                type: data.type || 'classroom',
                capacity: data.capacity ?? '',
                floor: data.floor ?? '',
                building: data.building || '',
                facilities: data.facilities || '',
                is_bookable: data.is_bookable ?? true,
            };
            this.formModalOpen = true;
            this.$nextTick(() => window.renderIcons());
        },

        openClosureModal(roomId) {
            if (roomId) this.closureRoomId = roomId;
            this.closureModalOpen = true;
            this.$nextTick(() => window.renderIcons());
        },

        openDeleteModal(id, name) {
            this.roomToDelete = { id, name };
            this.deleteModalOpen = true;
            this.$nextTick(() => window.renderIcons());
        },

        openDeleteFromEdit() {
            if (!this.formRoom.id) return;
            this.openDeleteModal(this.formRoom.id, this.formRoom.name);
            this.formModalOpen = false;
        },

        addFacilityTag(tag) {
            const current = (this.formRoom.facilities || '').split(',').map((s) => s.trim()).filter(Boolean);
            if (!current.includes(tag)) {
                current.push(tag);
                this.formRoom.facilities = current.join(', ');
            }
        },

        openBatchCapacityModal() {
            this.batchCapacity = this.batchCapacity || '40';
            this.batchCapacityConfirming = false;
            this.batchCapacityModalOpen = true;
            this.$nextTick(() => {
                window.renderIcons();
                this.$refs.batchCapacityInput?.focus();
            });
        },

        submitBatchCapacity(event) {
            if (this.selectedIds.length === 0) return;
            const form = event.target;
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        },

        submitBatch(action, params = {}) {
            if (this.selectedIds.length === 0) return;
            this.batchAction = action;
            this.batchParams.building = params.building || '';
            this.batchParams.type = params.type || '';
            this.$nextTick(() => {
                const form = this.$refs.batchForm;
                if (!form) return;
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            });
        },
    };
});

Alpine.data('classManager', (initialClasses = {}) => {
    const cloneClasses = (src) => JSON.parse(JSON.stringify(src || {}));

    return {
        editMode: false,
        selectedIds: [],
        search: '',
        statusFilter: 'semua',
        prodiFilter: '',
        cohortFilter: '',
        batchModalOpen: false,
        batchAction: '',
        batchLabel: '',
        batchValue: '',
        fillEmptyValue: 40,
        originalClasses: cloneClasses(initialClasses),
        classesData: cloneClasses(initialClasses),

        statusKeyFor(cls) {
            if (!cls.is_active) return 'arsip';
            const hasStudents = cls.student_count !== null && cls.student_count !== '' && cls.student_count !== undefined && Number(cls.student_count) > 0;
            return hasStudents ? 'lengkap' : 'perlu_dilengkapi';
        },

        isRowVisible(id) {
            const cls = this.classesData[id] || this.originalClasses[id];
            if (!cls) return false;
            const orig = this.originalClasses[id] || cls;

            const q = this.search.trim().toLowerCase();
            if (q) {
                const haystack = `${cls.name || ''} ${cls.study_program || ''} ${cls.cohort_year || ''} ${cls.code || ''} ${orig.name || ''} ${orig.code || ''}`.toLowerCase();
                if (!haystack.includes(q)) return false;
            }

            if (this.statusFilter !== 'semua') {
                const origStatus = this.statusKeyFor(orig);
                const curStatus = this.statusKeyFor(cls);
                if (origStatus !== this.statusFilter && curStatus !== this.statusFilter) return false;
            }

            if (this.prodiFilter && cls.study_program !== this.prodiFilter && orig.study_program !== this.prodiFilter) {
                return false;
            }

            if (this.cohortFilter && String(cls.cohort_year) !== String(this.cohortFilter) && String(orig.cohort_year) !== String(this.cohortFilter)) {
                return false;
            }

            return true;
        },

        get visibleIds() {
            return Object.keys(this.classesData)
                .map(Number)
                .filter((id) => this.isRowVisible(id));
        },

        targetIds() {
            const selected = this.selectedIds.map(Number);
            return selected.length > 0 ? selected : this.visibleIds;
        },

        isAllSelected() {
            const rows = this.visibleIds;
            if (!rows.length) return false;
            const current = this.selectedIds.map(Number);
            return rows.every((id) => current.includes(id));
        },

        toggleSelectAll(checked) {
            const rows = this.visibleIds;
            const current = this.selectedIds.map(Number);
            this.selectedIds = checked
                ? [...new Set([...current, ...rows])]
                : current.filter((id) => !rows.includes(id));
        },

        toggleEditMode() {
            if (this.editMode) {
                this.cancelEditMode();
            } else {
                this.editMode = true;
            }
        },

        cancelEditMode() {
            this.classesData = cloneClasses(this.originalClasses);
            this.editMode = false;
        },

        applyFillEmpty() {
            const val = Number(this.fillEmptyValue);
            if (!val || val <= 0) return;
            this.targetIds().forEach((id) => {
                const cls = this.classesData[id];
                if (!cls) return;
                if (cls.student_count === '' || cls.student_count === null || cls.student_count === undefined || Number(cls.student_count) <= 0) {
                    cls.student_count = val;
                }
            });
        },

        applyFillAll() {
            const val = Number(this.fillEmptyValue);
            if (!val || val <= 0) return;
            this.targetIds().forEach((id) => {
                const cls = this.classesData[id];
                if (cls) {
                    cls.student_count = val;
                }
            });
        },

        openBatchModal(action, label) {
            this.batchAction = action;
            this.batchLabel = label;
            this.batchValue = action === 'set_student_count'
                ? '40'
                : (action === 'set_cohort_year' ? String(new Date().getFullYear()) : '');
            this.batchModalOpen = true;
            this.$nextTick(() => window.renderIcons());
        },

        submitBatch(action = null, value = null) {
            if (this.selectedIds.length === 0) return;
            if (action) this.batchAction = action;
            if (value !== null) this.batchValue = value;
            this.$nextTick(() => {
                const form = this.$refs.batchForm;
                if (!form) return;
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            });
        },
    };
});

/**
 * UUID v4 untuk idempotency key reservasi. crypto.randomUUID hanya tersedia di konteks aman (HTTPS/localhost),
 * sedangkan getRandomValues tersedia juga di http://sicma-jtik.test.
 */
function uuidV4() {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

Alpine.data('cohortArchiveModal', (config = {}) => ({
    cohortYear: config.initialCohort || '',
    previewUrl: config.previewUrl || '',
    loading: false,
    error: null,
    preview: null,
    reason: '',
    deactivateKetis: true,

    async init() {
        if (this.cohortYear) {
            await this.fetchPreview();
        }
    },

    async fetchPreview() {
        if (!this.cohortYear) {
            this.preview = null;
            return;
        }
        this.loading = true;
        this.error = null;
        try {
            const url = new URL(this.previewUrl, window.location.origin);
            url.searchParams.set('cohort_year', this.cohortYear);
            const res = await fetch(url.toString(), {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw new Error(data.message || `Gagal memuat preview (${res.status})`);
            }
            this.preview = await res.json();
            if (!this.reason || this.reason.startsWith('Kelulusan Angkatan')) {
                this.reason = `Kelulusan Angkatan ${this.cohortYear}`;
            }
        } catch (err) {
            this.error = err.message || 'Terjadi kesalahan saat memuat preview';
            this.preview = null;
        } finally {
            this.loading = false;
            this.$nextTick(() => window.renderIcons?.(this.$el));
        }
    },
}));

Alpine.data('cohortGenerateModal', (config = {}) => ({
    sourceCohort: config.initialSource || '',
    targetCohort: config.initialTarget || (new Date().getFullYear() + 1),
    copyStudentCount: true,
    defaultStudentCount: 40,
    previewUrl: config.previewUrl || '',
    loading: false,
    error: null,
    classes: [],
    selectAll: true,

    get selectedClasses() {
        return this.classes.filter((c) => c.selected && !c.already_exists);
    },

    get selectedCount() {
        return this.selectedClasses.length;
    },

    get isAllSelected() {
        const selectable = this.classes.filter((c) => !c.already_exists);
        return selectable.length > 0 && selectable.every((c) => c.selected);
    },

    toggleSelectAll(checked) {
        this.selectAll = checked;
        this.classes.forEach((c) => {
            if (!c.already_exists) {
                c.selected = checked;
            }
        });
    },

    async init() {
        if (this.sourceCohort && this.targetCohort) {
            await this.fetchPreview();
        }
    },

    async fetchPreview() {
        if (!this.sourceCohort || !this.targetCohort) {
            this.classes = [];
            return;
        }
        if (Number(this.sourceCohort) === Number(this.targetCohort)) {
            this.error = 'Tahun angkatan target tidak boleh sama dengan angkatan sumber.';
            this.classes = [];
            return;
        }
        this.loading = true;
        this.error = null;
        try {
            const url = new URL(this.previewUrl, window.location.origin);
            url.searchParams.set('source_cohort_year', this.sourceCohort);
            url.searchParams.set('target_cohort_year', this.targetCohort);
            url.searchParams.set('copy_student_count', this.copyStudentCount ? '1' : '0');
            if (this.defaultStudentCount) {
                url.searchParams.set('default_student_count', this.defaultStudentCount);
            }
            const res = await fetch(url.toString(), {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw new Error(data.message || `Gagal memuat preview (${res.status})`);
            }
            const data = await res.json();
            this.classes = (data.classes || []).map((cls) => ({
                ...cls,
                selected: !cls.already_exists,
            }));
            this.selectAll = this.classes.some((c) => !c.already_exists);
        } catch (err) {
            this.error = err.message || 'Terjadi kesalahan saat memuat preview';
            this.classes = [];
        } finally {
            this.loading = false;
            this.$nextTick(() => window.renderIcons?.(this.$el));
        }
    },
}));

// Modal "Pesan ruang" bersama Portal Keti. Setiap pembukaan memakai idempotency key baru agar
// pemesanan ruang lain tidak memakai ulang kunci pemesanan sebelumnya (B07).
Alpine.data('bookingModal', (config = {}) => ({
    open: false,
    submitting: false,
    room: config.initial ?? null,
    key: config.key || uuidV4(),
    purpose: config.purpose ?? '',

    init() {
        this.$el.addEventListener('sicma:form-error', () => {
            this.submitting = false;
        });
        this.$el.addEventListener('sicma:form-complete', () => {
            this.submitting = false;
        });
    },

    show(detail) {
        this.room = detail;
        this.key = uuidV4();
        this.submitting = false;
        this.open = true;
        this.$nextTick(() => this.$root.querySelector('select[name="purpose"]')?.focus());
    },

    close() {
        if (!this.submitting) this.open = false;
    },
}));

Alpine.data('tableScrollBox', () => ({
    fullscreen: false,
    activeScroll: false,
    hasAutoFitted: false,
    scrollTimer: null,

    init() {
        this._cleanupNav = () => {
            this.fullscreen = false;
            this.activeScroll = false;
        };
        document.addEventListener('sicma:navigated', this._cleanupNav);
    },

    destroy() {
        clearTimeout(this.scrollTimer);
        if (this._cleanupNav) {
            document.removeEventListener('sicma:navigated', this._cleanupNav);
        }
    },

    toggleFullscreen() {
        this.fullscreen = !this.fullscreen;
        if (this.fullscreen) {
            this.hasAutoFitted = true;
        }
        this.$nextTick(() => {
            if (window.refreshIcons) {
                window.refreshIcons();
            }
        });
    },

    closeFullscreen() {
        if (!this.fullscreen) return;
        this.fullscreen = false;
    },

    resetAutoFit() {
        this.hasAutoFitted = false;
    },

    onTableScroll(event) {
        const scroller = event?.target;
        if (!scroller) return;

        this.activeScroll = true;
        clearTimeout(this.scrollTimer);
        this.scrollTimer = setTimeout(() => {
            this.activeScroll = false;
        }, 700);

        if (
            this.fullscreen
            || this.hasAutoFitted
            || scroller.scrollTop <= 0
            || scroller.scrollHeight <= scroller.clientHeight
        ) {
            return;
        }

        const box = this.$refs.box;
        if (!box) return;

        const rect = box.getBoundingClientRect();
        const topOffset = (document.querySelector('header.sticky')?.offsetHeight ?? 56) + 12;
        const bottomPadding = 16;
        const viewportBottom = window.innerHeight - bottomPadding;

        if (rect.top >= topOffset && rect.bottom <= viewportBottom) {
            this.hasAutoFitted = true;
            return;
        }

        const currentY = window.scrollY;
        const maxTopY = currentY + rect.top - topOffset;
        let targetY = currentY;

        if (rect.top < topOffset) {
            targetY = maxTopY;
        } else if (rect.bottom > viewportBottom) {
            const neededDown = rect.bottom - viewportBottom;
            targetY = Math.min(currentY + neededDown, maxTopY);
        }

        this.hasAutoFitted = true;
        if (Math.abs(targetY - currentY) > 4) {
            window.scrollTo({
                top: Math.max(0, targetY),
                behavior: 'smooth',
            });
        }
    },
}));

Alpine.data('filterDrawer', (storageKey = '', defaultOpen = false) => ({
    open: Boolean(defaultOpen),

    init() {
        try {
            const v = sessionStorage.getItem('sicma_drawer_' + storageKey);
            if (v !== null) {
                this.open = v === '1';
            }
        } catch {
            // Abaikan error penyimpanan jika sessionStorage diblokir.
        }
    },

    toggle() {
        this.open = !this.open;
        try {
            sessionStorage.setItem('sicma_drawer_' + storageKey, this.open ? '1' : '0');
        } catch {
            // Abaikan error penyimpanan jika sessionStorage diblokir.
        }
        this.$nextTick(() => window.renderIcons?.(this.$el));
    },
}));

let toastCounter = 0;

Alpine.data('sicmaToast', (initialToast = null) => ({
    items: [],
    supportsPopover: false,

    init() {
        this.supportsPopover = typeof this.$el.showPopover === 'function';

        if (initialToast && (initialToast.title || initialToast.message)) {
            this.push(initialToast);
        }

        this._onToast = (event) => {
            if (event?.detail) {
                this.push(event.detail);
            }
        };
        this._onClear = (event) => {
            this.clear(event?.detail?.scope ?? null);
        };

        window.addEventListener('sicma:toast', this._onToast);
        window.addEventListener('sicma:toast-clear', this._onClear);
    },

    destroy() {
        for (const item of this.items) {
            if (item.timer) {
                clearTimeout(item.timer);
            }
        }
        if (this._onToast) {
            window.removeEventListener('sicma:toast', this._onToast);
        }
        if (this._onClear) {
            window.removeEventListener('sicma:toast-clear', this._onClear);
        }
        this.hideHost();
    },

    isError(type) {
        return type === 'error' || type === 'danger';
    },

    showHost() {
        if (!this.supportsPopover || this.items.length === 0) return;
        try {
            if (this.$el.matches?.(':popover-open')) {
                if (document.querySelector('dialog[open]')) {
                    this.$el.hidePopover();
                    this.$el.showPopover();
                }
                return;
            }
            this.$el.showPopover();
        } catch {
            // Abaikan jika browser menolak pembukaan popover saat transisi DOM.
        }
    },

    hideHost() {
        if (!this.supportsPopover) return;
        try {
            if (this.$el.matches?.(':popover-open')) {
                this.$el.hidePopover();
            }
        } catch {
            // Abaikan error saat elemen tidak lagi terpasang di DOM.
        }
    },

    push(raw) {
        const type = raw?.type || 'success';
        const title = raw?.title ? String(raw.title) : '';
        const message = raw?.message ? String(raw.message) : '';
        if (!title && !message) return;

        const existingIndex = this.items.findIndex((entry) =>
            entry.type === type && entry.title === title && entry.message === message);
        if (existingIndex !== -1) {
            const [existing] = this.items.splice(existingIndex, 1);
            this.pauseTimer(existing.id, existing);
            this.items.push(existing);
            this.startTimer(existing.id);
            this.$nextTick(() => this.showHost());
            return;
        }

        while (this.items.length >= 3) {
            const dropped = this.items.shift();
            if (dropped?.timer) clearTimeout(dropped.timer);
        }

        const item = {
            id: ++toastCounter,
            type,
            title,
            message,
            scope: raw?.scope || 'server',
            autoHide: !this.isError(type),
            timer: null,
        };
        this.items.push(item);
        this.startTimer(item.id);
        this.$nextTick(() => {
            window.renderIcons?.(this.$el);
            this.showHost();
        });
    },

    startTimer(id) {
        const item = this.items.find((entry) => entry.id === id);
        if (!item || !item.autoHide || item.timer) return;
        item.timer = setTimeout(() => {
            this.dismiss(id);
        }, 5000);
    },

    pauseTimer(id, target = null) {
        const item = target || this.items.find((entry) => entry.id === id);
        if (item?.timer) {
            clearTimeout(item.timer);
            item.timer = null;
        }
    },

    dismiss(id) {
        const index = this.items.findIndex((entry) => entry.id === id);
        if (index === -1) return;
        const [removed] = this.items.splice(index, 1);
        this.pauseTimer(id, removed);
        if (this.items.length === 0) {
            this.hideHost();
        }
    },

    clear(scope = null) {
        const kept = [];
        for (const item of this.items) {
            if (scope === null || item.scope === scope) {
                this.pauseTimer(item.id, item);
            } else {
                kept.push(item);
            }
        }
        this.items = kept;
        if (this.items.length === 0) {
            this.hideHost();
        }
    },
}));

Alpine.start();
