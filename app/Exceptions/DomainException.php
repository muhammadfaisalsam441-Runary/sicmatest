<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Pelanggaran aturan bisnis SICMA dengan kode yang bisa dibaca klien (Bagian 3 & 9).
 *
 * Contoh kode: ROOM_CONFLICT, ROOM_CLOSED, CAPACITY_EXCEEDED, OUTSIDE_OPERATING_HOURS,
 * NOT_CLASS_REPRESENTATIVE, ACCOUNT_NOT_APPROVED, INVALID_STATUS_TRANSITION, VERSION_CONFLICT,
 * LEADERSHIP_THURSDAY, DATA_INCOMPLETE.
 */
class DomainException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Bentuk respons JSON standar: { ok, code, message, details }.
     *
     * @return array{ok: false, code: string, message: string, details: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'ok' => false,
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'details' => $this->details,
        ];
    }
}
