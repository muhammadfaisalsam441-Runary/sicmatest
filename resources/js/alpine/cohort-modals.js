export function cohortArchiveModal(config = {}) {
    return {
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
                        Accept: 'application/json',
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
    };
}

export function cohortGenerateModal(config = {}) {
    return {
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
                        Accept: 'application/json',
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
    };
}
