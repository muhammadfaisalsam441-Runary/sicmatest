import { bulkEditMethods, bulkSelectionMethods, cloneTableData, submitBatch } from './bulk-table.js';

export function lecturerManager(pageLecturerIds = [], initialLecturers = {}) {
    const fillSks = (component, onlyEmpty) => {
        const tVal = component.fillTargetSks !== '' && component.fillTargetSks !== null ? Number(component.fillTargetSks) : null;
        const mVal = component.fillMaxSks !== '' && component.fillMaxSks !== null ? Number(component.fillMaxSks) : null;
        component.targetIds().forEach((id) => {
            const lecturer = component.lecturersData[id];
            if (!lecturer) return;
            if (tVal !== null && (!onlyEmpty || lecturer.target_sks === '' || lecturer.target_sks === null || lecturer.target_sks === undefined)) {
                lecturer.target_sks = tVal;
            }
            if (mVal !== null && (!onlyEmpty || lecturer.max_sks === '' || lecturer.max_sks === null || lecturer.max_sks === undefined)) {
                lecturer.max_sks = mVal;
            }
        });
    };

    return {
        selectedIds: [],
        editMode: false,
        fillTargetSks: 12,
        fillMaxSks: 16,
        originalLecturers: cloneTableData(initialLecturers),
        lecturersData: cloneTableData(initialLecturers),
        formModalOpen: false,
        batchTargetModalOpen: false,
        importModalOpen: false,
        batchAction: '',
        courseQuery: '',
        formLecturer: { course_ids: [] },

        get visibleIds() {
            return pageLecturerIds.map(Number);
        },

        ...bulkSelectionMethods(function () {
            return this.visibleIds;
        }),

        ...bulkEditMethods('lecturersData', 'originalLecturers', { renderIconsOnEnter: true }),

        applyFillEmptySks() {
            fillSks(this, true);
        },

        applyFillAllSks() {
            fillSks(this, false);
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
            submitBatch(this, () => {
                this.batchAction = action;
            });
        },
    };
}
