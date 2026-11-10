<?php

namespace App\Models\Lecturer;

use App\Enums\SessionPhase;
use App\Enums\TeachingRole;
use App\Models\Academic\CourseOffering;
use Database\Factories\TeachingAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penugasan dosen (pengampu/mitra) pada penawaran kuliah.
 */
#[Fillable(['course_offering_id', 'lecturer_id', 'role', 'credited_sks', 'phase', 'is_locked'])]
class TeachingAssignment extends Model
{
    /** @use HasFactory<TeachingAssignmentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => TeachingRole::class,
            'credited_sks' => 'decimal:2',
            'phase' => SessionPhase::class,
            'is_locked' => 'boolean',
        ];
    }

    public function phaseLabel(): string
    {
        return match ($this->phase) {
            SessionPhase::BeforeUts => 'Pertemuan 1–7 (sampai UTS)',
            SessionPhase::AfterUts => 'Pertemuan 8–14 (sampai UAS)',
            SessionPhase::Full => $this->phase->label(),
        };
    }

    /**
     * @return BelongsTo<CourseOffering, $this>
     */
    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class);
    }

    /**
     * @return BelongsTo<Lecturer, $this>
     */
    public function lecturer(): BelongsTo
    {
        return $this->belongsTo(Lecturer::class);
    }
}
