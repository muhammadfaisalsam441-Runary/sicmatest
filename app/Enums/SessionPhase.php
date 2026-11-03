<?php

namespace App\Enums;

/**
 * Rentang berlaku sesi terhadap UTS.
 */
enum SessionPhase: string
{
    case Full = 'full';
    case BeforeUts = 'before_uts';
    case AfterUts = 'after_uts';

    /**
     * Label berbahasa Indonesia untuk antarmuka.
     */
    public function label(): string
    {
        return match ($this) {
            self::Full => 'Satu semester',
            self::BeforeUts => 'Sebelum UTS',
            self::AfterUts => 'Setelah UTS',
        };
    }
}
