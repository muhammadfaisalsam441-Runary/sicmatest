<?php

namespace App\Models;

use App\Enums\KetiApplicationStatus;
use App\Enums\UserRole;
use App\Services\EmailVerificationService;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * Pengguna SICMA: super admin, admin, atau Keti. Login memakai email atau NIM.
 */
#[Fillable(['name', 'nim', 'email', 'password', 'role', 'can_publish', 'is_active', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'can_publish' => 'boolean',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<KetiApplication, $this>
     */
    public function ketiApplications(): HasMany
    {
        return $this->hasMany(KetiApplication::class);
    }

    /**
     * @return HasOne<KetiApplication, $this>
     */
    public function latestKetiApplication(): HasOne
    {
        return $this->hasOne(KetiApplication::class)->latestOfMany();
    }

    /**
     * @return HasMany<ClassRepresentative, $this>
     */
    public function classRepresentatives(): HasMany
    {
        return $this->hasMany(ClassRepresentative::class);
    }

    /**
     * @return HasMany<Reservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'created_by');
    }

    /**
     * @return BelongsToMany<Room, $this>
     */
    public function favoriteRooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'favorites')->withTimestamps();
    }

    /**
     * @return HasMany<AppNotification, $this>
     */
    public function appNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class);
    }

    /**
     * @return HasMany<EmailVerificationCode, $this>
     */
    public function verificationCodes(): HasMany
    {
        return $this->hasMany(EmailVerificationCode::class);
    }

    /**
     * Pengguna boleh membuka Panel Admin.
     */
    public function isAdmin(): bool
    {
        return $this->role->isAdmin();
    }

    /**
     * Pengguna berperan Keti.
     */
    public function isKeti(): bool
    {
        return $this->role === UserRole::Keti;
    }

    /**
     * Keterhubungan Keti dengan kelas yang berlaku pada tanggal tersebut.
     */
    public function activeRepresentation(?CarbonInterface $on = null): ?ClassRepresentative
    {
        return $this->classRepresentatives()
            ->activeOn($on ?? now())
            ->with('classGroup')
            ->latest('valid_from')
            ->first();
    }

    /**
     * Kelas aktif yang diwakili Keti saat ini (B20), atau null.
     */
    public function activeClassGroup(?CarbonInterface $on = null): ?ClassGroup
    {
        $classGroup = $this->activeRepresentation($on)?->classGroup;

        return $classGroup?->is_active ? $classGroup : null;
    }

    /**
     * Inisial nama untuk avatar, mis. "DP".
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $word): string => Str::upper(Str::substr($word, 0, 1)))
            ->implode('');
    }

    /**
     * Keti boleh memesan: email terverifikasi, pengajuan terakhir disetujui, dan terhubung ke kelas aktif (B20).
     */
    public function isApprovedKeti(): bool
    {
        return $this->isKeti()
            && $this->is_active
            && $this->hasVerifiedEmail()
            && $this->latestKetiApplication?->status === KetiApplicationStatus::Approved
            && $this->activeClassGroup() !== null;
    }

    /**
     * Kirim kode verifikasi 6 digit (menggantikan tautan verifikasi bawaan Laravel).
     */
    public function sendEmailVerificationNotification(): void
    {
        app(EmailVerificationService::class)->send($this, ignoreCooldown: true);
    }
}
