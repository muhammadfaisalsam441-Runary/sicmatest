const TABLE_DENSITY_KEY = 'sicma.tableDensity';

export function readTableDensity() {
    try {
        return localStorage.getItem(TABLE_DENSITY_KEY) === 'compact' ? 'compact' : 'normal';
    } catch {
        return 'normal';
    }
}

export function applyTableDensity(density) {
    const value = density === 'compact' ? 'compact' : 'normal';
    if (document.documentElement) {
        document.documentElement.dataset.uiDensity = value;
    }
    if (document.body) {
        document.body.dataset.tableDensity = value;
    }
    return value;
}

export function tableDensityToggle() {
    return {
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
    };
}

export function tableScrollBox() {
    return {
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
                window.renderIcons?.();
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
    };
}

export function filterDrawer(storageKey = '', defaultOpen = false) {
    return {
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
    };
}
