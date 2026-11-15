<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi pembuatan kelas angkatan baru dari templat.
 */
class GenerateCohortRequest extends FormRequest
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
        if ($this->has('copy_student_count')) {
            $this->merge([
                'copy_student_count' => $this->boolean('copy_student_count'),
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
            'source_cohort_year' => ['required', 'integer', 'between:2000,2100', 'exists:class_groups,cohort_year'],
            'target_cohort_year' => ['required', 'integer', 'between:2000,2100', 'different:source_cohort_year'],
            'copy_student_count' => ['sometimes', 'boolean'],
            'default_student_count' => ['nullable', 'integer', 'min:1', 'max:500'],
            'classes' => ['sometimes', 'array', 'min:1'],
            'classes.*.name' => ['required_with:classes', 'string', 'max:60'],
            'classes.*.study_program' => ['required_with:classes', 'string', 'max:120'],
            'classes.*.student_count' => ['nullable', 'integer', 'min:1', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'source_cohort_year' => 'angkatan sumber',
            'target_cohort_year' => 'angkatan tujuan',
            'copy_student_count' => 'salin jumlah mahasiswa',
            'default_student_count' => 'jumlah mahasiswa default',
            'classes' => 'daftar kelas',
            'classes.*.name' => 'nama kelas',
            'classes.*.study_program' => 'prodi',
            'classes.*.student_count' => 'jumlah mahasiswa',
        ];
    }
}
