import { bulkSelectionMethods } from './bulk-table.js';

export function teachingManager(pageOfferingIds, lecturerOptionsByOffering, allLecturers = []) {
    return {
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

        ...bulkSelectionMethods(() => pageOfferingIds),

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
    };
}
