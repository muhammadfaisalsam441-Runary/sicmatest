import { bookingModal } from '../alpine/booking-modal.js';

export function registerKetiPortal(Alpine) {
    Alpine.data('bookingModal', bookingModal);
}
