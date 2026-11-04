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

Alpine.data('teachingManager', (pageOfferingIds, lecturerOptionsByOffering) => ({
    selectedIds: [],
    quickModalOpen: false,
    quickLecturerId: '',
    batchModalOpen: false,
    rekapModalOpen: false,
    lecturerSearch: '',
    activeOffering: { id: null, course: '', classGroup: '', role: 'lead' },

    get quickLecturers() {
        return lecturerOptionsByOffering[this.activeOffering.id] ?? [];
    },

    isAllSelected() {
        return pageOfferingIds.length > 0 && pageOfferingIds.every((id) => this.selectedIds.includes(id));
    },

    toggleSelectAll(checked) {
        this.selectedIds = checked
            ? [...new Set([...this.selectedIds, ...pageOfferingIds])]
            : this.selectedIds.filter((id) => !pageOfferingIds.includes(id));
    },

    openQuickAssign(id, course, classGroup, role = 'lead') {
        this.quickLecturerId = '';
        this.activeOffering = { id, course, classGroup, role };
        this.quickModalOpen = true;
        this.$nextTick(() => window.renderIcons());
    },
}));

Alpine.start();
