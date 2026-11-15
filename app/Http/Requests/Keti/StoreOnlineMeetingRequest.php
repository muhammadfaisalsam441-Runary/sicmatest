<?php

namespace App\Http\Requests\Keti;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pengalihan satu pertemuan kuliah ke mode Online (tanpa ruangan fisik).
 */
class StoreOnlineMeetingRequest extends FormRequest
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
            'replaces_reschedule_id' => ['nullable', 'integer', Rule::exists('session_reschedules', 'id')],
            'start_at' => ['nullable', 'date_format:Y-m-d H:i'],
            'lecturer_ids' => ['required', 'array', 'min:1'],
            'lecturer_ids.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lecturer_ids.required' => 'Pilih minimal satu dosen dari tim pengajar mata kuliah ini.',
            'lecturer_ids.min' => 'Pilih minimal satu dosen dari tim pengajar mata kuliah ini.',
        ];
    }
}
