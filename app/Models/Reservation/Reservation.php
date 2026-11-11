<?php

namespace App\Models\Reservation;

use App\Enums\ReservationStatus;
use App\Enums\RoomBlockSource;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\SessionReschedule;
use App\Models\Room\Room;
use App\Models\Room\RoomBlock;
use App\Models\User\User;
use Database\Factories\ReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Reservasi ruang oleh Keti atas nama kelas. Riwayat tidak pernah dihapus (B12).
 */
#[Fillable(['room_id', 'class_group_id', 'created_by', 'start_at', 'end_at', 'participants', 'purpose', 'session_reschedule_id', 'status', 'actual_start_at', 'actual_end_at', 'cancelled_at', 'cancelled_by', 'cancel_reason', 'idempotency_key', 'lock_version'])]
class Reservation extends Model
{
    /** @use HasFactory<ReservationFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'participants' => 'integer',
            'status' => ReservationStatus::class,
            'actual_start_at' => 'datetime',
            'actual_end_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<ClassGroup, $this>
     */
    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Pemindahan pertemuan kuliah yang diwakili reservasi ini (hanya untuk "Kuliah Pengganti").
     *
     * @return BelongsTo<SessionReschedule, $this>
     */
    public function sessionReschedule(): BelongsTo
    {
        return $this->belongsTo(SessionReschedule::class);
    }

    /**
     * @return HasOne<RoomBlock, $this>
     */
    public function block(): HasOne
    {
        return $this->hasOne(RoomBlock::class, 'source_id')->where('source_type', RoomBlockSource::Reservation);
    }
}
