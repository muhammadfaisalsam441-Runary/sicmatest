<?php

namespace App\Models\Academic;

use App\Models\Reservation\Reservation;
use App\Models\Scheduling\ScheduleSession;
use App\Models\User\ClassRepresentative;
use App\Models\User\KetiApplication;
use Closure;
use Database\Factories\ClassGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Kelas (rombel) yang diwakili seorang Keti, mis. "Tekom C".
 */
#[Fillable(['code', 'name', 'study_program', 'cohort_year', 'student_count', 'is_active'])]
class ClassGroup extends Model
{
    /** @use HasFactory<ClassGroupFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Format kode kelas unik tanpa menggandakan tahun angkatan jika sudah ada di nama kelas.
     */
    public static function formatCode(string $name, ?int $cohortYear = null, ?int $ignoreId = null): string
    {
        $cleanName = Str::squish($name);
        $source = $cleanName;

        if ($cohortYear !== null && ! preg_match('/\b'.preg_quote((string) $cohortYear, '/').'\b$/', $cleanName)) {
            $source = trim($cleanName.' '.$cohortYear);
        }

        $baseCode = Str::upper(Str::slug($source));
        if ($baseCode === '') {
            $baseCode = 'KELAS'.($cohortYear ? '-'.$cohortYear : '');
        }

        $code = $baseCode;
        $suffix = 2;

        while (static::withTrashed()
            ->where('code', $code)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()) {
            $code = $ignoreId !== null && $suffix === 2
                ? $baseCode.'-'.$ignoreId
                : $baseCode.'-'.$suffix;
            $suffix++;
        }

        return $code;
    }

    /**
     * Format label tampilan program studi dari nama atau kode tersimpan.
     */
    public static function studyProgramLabel(string $name): string
    {
        return match ($name) {
            'PTIK', 'Pendidikan Teknik Informatika dan Komputer' => 'PTIK (Pend. Teknik Informatika dan Komputer)',
            'TEKOM', 'Teknik Komputer' => 'TEKOM (Teknik Komputer)',
            default => $name,
        };
    }

    /**
     * Cocokkan program studi termasuk padanan singkatan dan nama lengkapnya.
     *
     * @param  Builder<ClassGroup>  $query
     * @return Builder<ClassGroup>
     */
    public function scopeWhereStudyProgramMatches(Builder $query, string $prodi): Builder
    {
        $ptik = ['PTIK', 'Pendidikan Teknik Informatika dan Komputer'];
        $tekom = ['TEKOM', 'Teknik Komputer'];

        if (in_array($prodi, $ptik, true)) {
            return $query->whereIn('study_program', $ptik);
        }

        if (in_array($prodi, $tekom, true)) {
            return $query->whereIn('study_program', $tekom);
        }

        return $query->where(fn (Builder $q): Builder => $q
            ->where('study_program', $prodi)
            ->orWhere('study_program', 'like', "%{$prodi}%"));
    }

    /**
     * Urutkan kelas untuk pilihan dropdown: PTIK lalu TEKOM, angkatan terbaru dulu, reguler sebelum Pilihan/ULANG, lalu A–Z.
     *
     * @param  Builder<ClassGroup>  $query
     * @return Builder<ClassGroup>
     */
    public function scopeOrderedForSelection(Builder $query): Builder
    {
        return $query
            ->orderByRaw("CASE
                WHEN study_program IN ('PTIK', 'Pendidikan Teknik Informatika dan Komputer') THEN 0
                WHEN study_program IN ('TEKOM', 'Teknik Komputer') THEN 1
                WHEN study_program IS NOT NULL AND study_program != '' THEN 2
                ELSE 3
            END")
            ->orderBy('study_program')
            ->orderByRaw('CASE WHEN cohort_year IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('cohort_year')
            ->orderByRaw("CASE
                WHEN LOWER(name) LIKE '%ulang%' THEN 2
                WHEN LOWER(name) LIKE '%pilihan%' THEN 1
                ELSE 0
            END")
            ->orderBy('name');
    }

    /**
     * Kelompokkan daftar kelas terurut menjadi array bertingkat untuk `<optgroup>`.
     *
     * @param  iterable<int, ClassGroup>  $classes
     * @param  (Closure(ClassGroup): string)|null  $labelFormatter
     * @return array<string, array<int, string>>
     */
    public static function groupedOptions(iterable $classes, ?Closure $labelFormatter = null): array
    {
        $grouped = [];

        foreach ($classes as $class) {
            $program = trim((string) $class->study_program);
            $shortProgram = match ($program) {
                'PTIK', 'Pendidikan Teknik Informatika dan Komputer' => 'PTIK',
                'TEKOM', 'Teknik Komputer' => 'TEKOM',
                '' => 'Lainnya',
                default => $program,
            };

            $groupLabel = $class->cohort_year !== null
                ? "{$shortProgram} — Angkatan {$class->cohort_year}"
                : $shortProgram;

            $grouped[$groupLabel][$class->id] = $labelFormatter !== null
                ? $labelFormatter($class)
                : $class->name;
        }

        return $grouped;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cohort_year' => 'integer',
            'student_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ClassRepresentative, $this>
     */
    public function representatives(): HasMany
    {
        return $this->hasMany(ClassRepresentative::class);
    }

    /**
     * @return HasMany<KetiApplication, $this>
     */
    public function ketiApplications(): HasMany
    {
        return $this->hasMany(KetiApplication::class);
    }

    /**
     * @return HasMany<CourseOffering, $this>
     */
    public function courseOfferings(): HasMany
    {
        return $this->hasMany(CourseOffering::class);
    }

    /**
     * @return HasMany<Reservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /**
     * @return BelongsToMany<ScheduleSession, $this>
     */
    public function scheduleSessions(): BelongsToMany
    {
        return $this->belongsToMany(ScheduleSession::class, 'session_participants');
    }
}
