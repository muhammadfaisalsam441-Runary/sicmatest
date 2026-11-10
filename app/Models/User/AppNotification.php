<?php

namespace App\Models\User;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notifikasi di aplikasi untuk Keti/admin.
 */
#[Fillable(['user_id', 'type', 'title', 'body', 'data', 'read_at'])]
class AppNotification extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Notifikasi yang belum dibaca.
     */
    #[Scope]
    protected function unread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }
}
