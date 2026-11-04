<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tambah penawaran mata kuliah untuk kelas pada semester.
 */
class OfferingRequest extends FormRequest
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
            'period_id' => ['required', Rule::exists('periods', 'id')],
            'course_id' => ['required', Rule::exists('courses', 'id')->whereNull('deleted_at')],
            'class_group_id' => ['required', Rule::exists('class_groups', 'id')->whereNull('deleted_at'), Rule::unique('course_offerings')->where(fn ($query) => $query->where('period_id', $this->input('period_id'))->where('course_id', $this->input('course_id')))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'course_id' => 'mata kuliah',
            'class_group_id' => 'kelas',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'class_group_id.unique' => 'Mata kuliah ini sudah ditawarkan untuk kelas tersebut.',
        ];
    }
}
