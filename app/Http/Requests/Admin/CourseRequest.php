<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tambah/ubah mata kuliah.
 */
class CourseRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:20', Rule::unique('courses', 'code')->ignore($this->route('course')?->id)],
            'name' => ['required', 'string', 'max:120'],
            'sks' => ['required', 'integer', 'between:1,8'],
            'curriculum' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'kode MK',
            'name' => 'nama mata kuliah',
        ];
    }
}
