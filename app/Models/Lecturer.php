<?php

namespace App\Models;

use App\Enums\LecturerHomebase;
use App\Enums\LecturerProfileStatus;
use Carbon\CarbonInterface;
use Database\Factories\LecturerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Dosen. Data yang belum pasti berstatus "Perlu verifikasi" (B16).
 */
#[Fillable(['name', 'nidn', 'nip', 'homebase', 'rank', 'grade', 'grade_since', 'functional_position', 'functional_position_since', 'profile_status', 'is_active', 'source_name'])]
class Lecturer extends Model
{
    /** @use HasFactory<LecturerFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'homebase' => LecturerHomebase::class,
            'profile_status' => LecturerProfileStatus::class,
            'is_active' => 'boolean',
            'grade_since' => 'date',
            'functional_position_since' => 'date',
        ];
    }

    /**
     * @return HasMany<LeadershipTerm, $this>
     */
    public function leadershipTerms(): HasMany
    {
        return $this->hasMany(LeadershipTerm::class);
    }

    /**
     * @return HasMany<LecturerExpertise, $this>
     */
    public function expertises(): HasMany
    {
        return $this->hasMany(LecturerExpertise::class);
    }

    /**
     * @return HasMany<LecturerAvailability, $this>
     */
    public function availabilities(): HasMany
    {
        return $this->hasMany(LecturerAvailability::class);
    }

    /**
     * @return HasMany<LecturerLoadTarget, $this>
     */
    public function loadTargets(): HasMany
    {
        return $this->hasMany(LecturerLoadTarget::class);
    }

    /**
     * @return HasMany<TeachingAssignment, $this>
     */
    public function teachingAssignments(): HasMany
    {
        return $this->hasMany(TeachingAssignment::class);
    }

    /**
     * @return BelongsToMany<ScheduleSession, $this>
     */
    public function scheduleSessions(): BelongsToMany
    {
        return $this->belongsToMany(ScheduleSession::class, 'session_lecturers')->withPivot('role');
    }

    /**
     * Dosen menjabat pimpinan pada tanggal tersebut (dipakai aturan Kamis, B11).
     */
    public function isLeaderOn(CarbonInterface $date): bool
    {
        return $this->leadershipTerms->contains(fn (LeadershipTerm $term): bool => $term->isActiveOn($date));
    }
}
