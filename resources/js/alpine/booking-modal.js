/**
 * UUID v4 untuk idempotency key reservasi. crypto.randomUUID hanya tersedia di konteks aman (HTTPS/localhost),
 * sedangkan getRandomValues tersedia juga di http://sicma-jtik.test.
 */
export function uuidV4() {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

// Modal "Pesan ruang" bersama Portal Keti. Setiap pembukaan memakai idempotency key baru agar
// pemesanan ruang lain tidak memakai ulang kunci pemesanan sebelumnya (B07).
export function bookingModal(config = {}) {
    return {
        open: false,
        submitting: false,
        room: config.initial ?? null,
        key: config.key || uuidV4(),
        purpose: config.purpose ?? '',

        init() {
            this.$el.addEventListener('sicma:form-error', () => {
                this.submitting = false;
            });
            this.$el.addEventListener('sicma:form-complete', () => {
                this.submitting = false;
            });
        },

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
    };
}
