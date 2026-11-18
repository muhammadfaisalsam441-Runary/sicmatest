let toastCounter = 0;

export function sicmaToast(initialToast = null) {
    return {
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
    };
}
