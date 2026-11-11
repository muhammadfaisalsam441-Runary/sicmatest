<?php

namespace App\Enums;

/**
 * Status pemindahan pertemuan: active → cancelled (kembali ke jam asli) atau replaced (dipindah lagi).
 */
enum RescheduleStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';
    case Replaced = 'replaced';
}
