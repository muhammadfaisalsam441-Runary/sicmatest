<?php

namespace App\Http\Requests\Keti;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Data pemindahan pertemuan. Aturan bisnis (hari kuliah, bentrok, dosen) diperiksa MeetingRescheduleService.
 */
class MoveMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isApprovedKeti() ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'session_id' => ['required', 'integer', Rule::exists('schedule_sessions', 'id')],
            'original_date' => ['required', 'date_format:Y-m-d'],
            'replaces_reservation_id' => ['nullable', 'integer', Rule::exists('reservations', 'id')],
            'start_at' => ['required', 'date_format:Y-m-d H:i'],
            'room_id' => ['required', 'integer', Rule::exists('rooms', 'id')->whereNull('deleted_at')],
            'lecturer_ids' => ['nullable', 'array'],
            'lecturer_ids.*' => ['integer'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
