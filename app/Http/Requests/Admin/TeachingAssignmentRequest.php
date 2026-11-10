<?php

namespace App\Http\Requests\Admin;

use App\Enums\SessionPhase;
use App\Enums\TeachingRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tambah anggota tim pengajar (pengampu/mitra) pada penawaran kuliah.
 */
class TeachingAssignmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'course_offering_id' => ['required', Rule::exists('course_offerings', 'id')],
            'lecturer_id' => ['required', Rule::exists('lecturers', 'id')->whereNull('deleted_at'), Rule::unique('teaching_assignments')->where(fn ($query) => $query->where('course_offering_id', $this->input('course_offering_id'))->where('phase', $this->input('phase', 'full')))],
            'role' => ['required', Rule::enum(TeachingRole::class)],
            'phase' => ['required', Rule::enum(SessionPhase::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lecturer_id' => 'dosen',
            'role' => 'peran',
            'phase' => 'periode',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lecturer_id.unique' => 'Dosen ini sudah ada di tim pengajar untuk periode tersebut.',
        ];
    }
}
