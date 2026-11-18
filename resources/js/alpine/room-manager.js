import {
    bulkEditMethods,
    bulkSelectionMethods,
    cloneTableData,
    fillPositiveField,
    submitBatch,
    submitFormElement,
} from './bulk-table.js';

function parseEditId(val) {
    if (val !== undefined && val !== null && val !== '') {
        const num = Number(val);
        return Number.isFinite(num) && num > 0 ? num : null;
    }
    return null;
}

export function roomManager(pageRoomIds = [], defaults = {}, initialRooms = {}) {
    const inferredFormId = typeof defaults.form === 'string' && /^edit-(\d+)$/.test(defaults.form)
        ? Number(defaults.form.match(/^edit-(\d+)$/)[1])
        : null;
    const initialRoomId = parseEditId(defaults.roomId ?? defaults.id) ?? inferredFormId;

    return {
        editMode: false,
        fillEmptyValue: 40,
        originalRooms: cloneTableData(initialRooms),
        roomsData: cloneTableData(initialRooms),
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
        closureMode: defaults.closureMode ?? 'once',
        closureRoomId: defaults.closureRoomId ?? '',
        closureStart: defaults.closureStart ?? '',
        closureEnd: defaults.closureEnd ?? '',
        closureStartDate: defaults.closureStartDate ?? '',
        closureEndDate: defaults.closureEndDate ?? '',
        closureStartTime: defaults.closureStartTime ?? '07:30',
        closureEndTime: defaults.closureEndTime ?? '12:00',
        closureDays: (defaults.closureDays ?? [1, 2, 3, 4, 5]).map(Number),
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

        ...bulkSelectionMethods(function () {
            return this.visibleIds;
        }),

        ...bulkEditMethods('roomsData', 'originalRooms'),

        applyFillEmpty() {
            fillPositiveField(this.roomsData, this.targetIds(), 'capacity', this.fillEmptyValue, { onlyEmpty: true });
        },

        applyFillAll() {
            fillPositiveField(this.roomsData, this.targetIds(), 'capacity', this.fillEmptyValue);
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

        openClosureModal(roomId, mode = null) {
            if (roomId) this.closureRoomId = roomId;
            if (mode) this.closureMode = mode;
            this.closureModalOpen = true;
            this.$nextTick(() => window.renderIcons());
        },

        toggleClosureDay(day) {
            const target = Number(day);
            if (this.closureDays.includes(target)) {
                this.closureDays = this.closureDays.filter((d) => d !== target);
            } else {
                this.closureDays = [...this.closureDays, target].sort((a, b) => a - b);
            }
        },

        setClosureDays(days) {
            this.closureDays = [...days].map(Number).sort((a, b) => a - b);
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
            submitFormElement(event.target);
        },

        submitBatch(action, params = {}) {
            submitBatch(this, () => {
                this.batchAction = action;
                this.batchParams.building = params.building || '';
                this.batchParams.type = params.type || '';
            });
        },
    };
}
