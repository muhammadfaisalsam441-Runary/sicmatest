import Alpine from 'alpinejs';
import { createIcons } from 'lucide';
import * as icons from './icons';

window.Alpine = Alpine;

/**
 * Render ulang ikon Lucide (<i data-lucide="nama-ikon">) di dalam elemen tertentu.
 * Dipanggil otomatis saat halaman dimuat; panggil lagi setelah konten dinamis ditambahkan.
 */
window.renderIcons = (root = document) => createIcons({ icons, root });

document.addEventListener('DOMContentLoaded', () => window.renderIcons());

Alpine.start();
