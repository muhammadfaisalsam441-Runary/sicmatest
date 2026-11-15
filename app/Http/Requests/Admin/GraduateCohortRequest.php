<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi permintaan kelulusan dan pengarsipan angkatan.
 */
class GraduateCohortRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('deactivate_ketis')) {
            $this->merge([
                'deactivate_ketis' => $this->boolean('deactivate_ketis'),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cohort_year' => ['required', 'integer', 'between:2000,2100', 'exists:class_groups,cohort_year'],
            'reason' => ['nullable', 'string', 'max:255'],
            'deactivate_ketis' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cohort_year' => 'tahun angkatan',
            'reason' => 'alasan kelulusan',
            'deactivate_ketis' => 'nonaktifkan akun keti',
        ];
    }
}
