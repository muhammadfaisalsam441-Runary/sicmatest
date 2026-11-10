<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Aksi massal Data Dosen: verifikasi, homebase, atau target/maksimum SKS.
 */
class BatchLecturerRequest extends FormRequest
{
    public const ACTIONS = ['verify', 'unverify', 'homebase_jtik', 'homebase_external', 'set_target_sks'];

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(self::ACTIONS)],
            'lecturer_ids' => ['required', 'array', 'min:1'],
            'lecturer_ids.*' => ['integer', 'exists:lecturers,id'],
            'target_sks' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'max_sks' => ['nullable', 'numeric', 'min:0', 'max:60', ...($this->filled('target_sks') ? ['gte:target_sks'] : [])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lecturer_ids.required' => 'Pilih minimal satu dosen.',
            'max_sks.gte' => 'Maksimum SKS harus lebih besar atau sama dengan target SKS.',
        ];
    }
}
