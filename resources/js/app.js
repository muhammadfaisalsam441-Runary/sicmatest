import Alpine from 'alpinejs';
import { createIcons } from 'lucide';
import * as icons from './icons';
import { installPortalNavigation, registerStatusPolling } from './navigation';

window.Alpine = Alpine;

/**
 * Render ulang ikon Lucide (<i data-lucide="nama-ikon">) di dalam elemen tertentu.
 * Dipanggil otomatis saat halaman dimuat; panggil lagi setelah konten dinamis ditambahkan.
 */
window.renderIcons = (root = document) => createIcons({ icons, root });

window.renderIcons();

const navigation = installPortalNavigation(Alpine, (root = document) => window.renderIcons(root));
registerStatusPolling(Alpine, navigation);

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

Alpine.data('lecturerManager', (pageLecturerIds) => ({
    selectedIds: [],
    formModalOpen: false,
    batchTargetModalOpen: false,
    importModalOpen: false,
    batchAction: '',
    courseQuery: '',
    formLecturer: { course_ids: [] },

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
}));

Alpine.data('roomManager', (pageRoomIds = [], defaults = {}, initialRooms = {}) => {
    const cloneRooms = (src) => JSON.parse(JSON.stringify(src || {}));

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
            id: null,
            name: defaults.name ?? '',
            code: defaults.code ?? '',
            type: defaults.type ?? 'classroom',
            capacity: defaults.capacity ?? '',
            floor: defaults.floor ?? '',
            building: defaults.building ?? '',
            facilities: defaults.facilities ?? '',
            is_bookable: true,
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
                form.submit();
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

// Modal "Pesan ruang" bersama Portal Keti. Setiap pembukaan memakai idempotency key baru agar
// pemesanan ruang lain tidak memakai ulang kunci pemesanan sebelumnya (B07).
Alpine.data('bookingModal', (config = {}) => ({
    open: false,
    submitting: false,
    room: config.initial ?? null,
    key: config.key || uuidV4(),
    purpose: config.purpose ?? '',

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

Alpine.start();
