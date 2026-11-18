import {
    bulkEditMethods,
    bulkSelectionMethods,
    cloneTableData,
    fillPositiveField,
    submitBatch,
} from './bulk-table.js';

export function classManager(initialClasses = {}) {
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
        originalClasses: cloneTableData(initialClasses),
        classesData: cloneTableData(initialClasses),

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

        ...bulkSelectionMethods(function () {
            return this.visibleIds;
        }),

        ...bulkEditMethods('classesData', 'originalClasses'),

        applyFillEmpty() {
            fillPositiveField(this.classesData, this.targetIds(), 'student_count', this.fillEmptyValue, { onlyEmpty: true });
        },

        applyFillAll() {
            fillPositiveField(this.classesData, this.targetIds(), 'student_count', this.fillEmptyValue);
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
            submitBatch(this, () => {
                if (action) this.batchAction = action;
                if (value !== null) this.batchValue = value;
            });
        },
    };
}
