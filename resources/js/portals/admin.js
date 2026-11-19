import { applyTableDensity, filterDrawer, readTableDensity, tableDensityToggle, tableScrollBox } from '../alpine/table-ui.js';
import { teachingManager } from '../alpine/teaching-manager.js';
import { lecturerManager } from '../alpine/lecturer-manager.js';
import { roomManager } from '../alpine/room-manager.js';
import { classManager } from '../alpine/class-manager.js';
import { cohortArchiveModal, cohortGenerateModal } from '../alpine/cohort-modals.js';

let listenersInstalled = false;

export function registerAdminPortal(Alpine) {
    Alpine.data('tableDensityToggle', tableDensityToggle);
    Alpine.data('tableScrollBox', tableScrollBox);
    Alpine.data('filterDrawer', filterDrawer);
    Alpine.data('teachingManager', teachingManager);
    Alpine.data('lecturerManager', lecturerManager);
    Alpine.data('roomManager', roomManager);
    Alpine.data('classManager', classManager);
    Alpine.data('cohortArchiveModal', cohortArchiveModal);
    Alpine.data('cohortGenerateModal', cohortGenerateModal);

    applyTableDensity(readTableDensity());

    if (listenersInstalled) return;
    listenersInstalled = true;

    document.addEventListener('sicma:navigated', () => applyTableDensity(readTableDensity()));

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
                for (let i = from; i <= to; i++) {
                    const cb = checkboxes[i];
                    if (cb !== target && cb.checked !== target.checked) {
                        cb.checked = target.checked;
                        cb.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
            }
        }
        lastCheckedByTable.set(table, target);
    });
}
