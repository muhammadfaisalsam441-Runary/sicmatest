<?php

namespace App\Enums;

/**
 * Status solver CP-SAT. Disimpan terpisah dari status draf; FEASIBLE bukan optimal.
 */
enum SolverStatus: string
{
    case Optimal = 'OPTIMAL';
    case Feasible = 'FEASIBLE';
    case Infeasible = 'INFEASIBLE';
    case Unknown = 'UNKNOWN';
    case ModelInvalid = 'MODEL_INVALID';

    /**
     * Label berbahasa Indonesia untuk antarmuka.
     */
    public function label(): string
    {
        return match ($this) {
            self::Optimal => 'Optimal',
            self::Feasible => 'Layak (belum terbukti optimal)',
            self::Infeasible => 'Tidak ada susunan yang memenuhi aturan',
            self::Unknown => 'Belum diketahui',
            self::ModelInvalid => 'Model tidak valid',
        };
    }
}
