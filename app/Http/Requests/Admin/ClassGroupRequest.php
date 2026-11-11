<?php

namespace App\Http\Requests\Admin;

use App\Models\Academic\ClassGroup;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Tambah/ubah kelas.
 */
class ClassGroupRequest extends FormRequest
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
        $classGroup = $this->route('classGroup');
        $cohortYear = is_numeric($this->input('cohort_year')) ? (int) $this->input('cohort_year') : null;
        $rawCode = trim((string) $this->input('code', ''));

        $code = $rawCode !== '' && $classGroup === null
            ? Str::upper(Str::slug($rawCode))
            : ClassGroup::formatCode((string) $this->input('name', ''), $cohortYear, $classGroup?->id);

        $this->merge([
            'code' => $code,
            'is_active' => $this->boolean('is_active', true),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'code' => ['required', 'string', 'max:40', Rule::unique('class_groups', 'code')->ignore($this->route('classGroup')?->id)],
            'study_program' => ['required', 'string', 'max:120'],
            'cohort_year' => ['required', 'integer', 'between:2000,2100'],
            'student_count' => ['nullable', 'integer', 'min:1', 'max:500'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama kelas',
            'study_program' => 'prodi',
            'cohort_year' => 'angkatan',
            'student_count' => 'jumlah mahasiswa',
        ];
    }
}
