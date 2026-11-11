<?php

namespace App\Models\Academic;

use App\Enums\RescheduleStatus;
use App\Models\Lecturer\Lecturer;
use App\Models\Reservation\Reservation;
use App\Models\Room\Room;
use App\Models\Scheduling\ScheduleSession;
use App\Models\User\User;
use Carbon\CarbonInterface;
use Database\Factories\SessionRescheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Satu pertemuan kuliah yang dipindah Keti ke reservasi "Kuliah Pengganti".
 *
 * Pertemuan asli dikenali lewat penawaran mata kuliah + jam mulai, atau judul + kelas peserta bila
 * sesi tidak terhubung ke penawaran, sehingga tetap berlaku saat jadwal ditetapkan ulang.
 */
#[Fillable(['schedule_session_id', 'course_offering_id', 'session_title', 'participant_key', 'original_start_at', 'original_end_at', 'original_room_id', 'reservation_id', 'status', 'created_by', 'cancelled_at', 'cancelled_by'])]
class SessionReschedule extends Model
{
    /** @use HasFactory<SessionRescheduleFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'original_start_at' => 'datetime',
            'original_end_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'status' => RescheduleStatus::class,
        ];
    }

    /**
     * Kunci kelas peserta: ID urut naik tanpa duplikat, dipisah koma.
     *
     * @param  iterable<int|string>  $classGroupIds
     */
    public static function participantKey(iterable $classGroupIds): string
    {
        return collect($classGroupIds)->map(fn ($id): int => (int) $id)->unique()->sort()->implode(',');
    }

    /**
     * Apakah pemindahan ini milik kemunculan sesi yang dimulai pada $start.
     */
    public function matches(ScheduleSession $session, CarbonInterface $start): bool
    {
        if ($this->original_start_at->format('Y-m-d H:i') !== $start->format('Y-m-d H:i')) {
            return false;
        }

        if ($this->course_offering_id !== null && $session->course_offering_id !== null) {
            return $this->course_offering_id === $session->course_offering_id;
        }

        return $this->session_title === $session->title
            && $this->participant_key === self::participantKey($session->participants->modelKeys());
    }

    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('status', RescheduleStatus::Active);
    }

    /**
     * @return BelongsTo<ScheduleSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ScheduleSession::class, 'schedule_session_id');
    }

    /**
     * @return BelongsTo<CourseOffering, $this>
     */
    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function originalRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'original_room_id');
    }

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Dosen yang dipilih Keti untuk masuk di pertemuan pengganti.
     *
     * @return BelongsToMany<Lecturer, $this>
     */
    public function lecturers(): BelongsToMany
    {
        return $this->belongsToMany(Lecturer::class, 'session_reschedule_lecturers')->withPivot('role');
    }
}
