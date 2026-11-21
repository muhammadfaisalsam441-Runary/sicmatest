<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResubmitApplicationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isKeti() ?? false;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $updates = [];

        if ($this->has('name')) {
            $updates['name'] = trim((string) $this->input('name'));
        }

        if ($this->has('nim')) {
            $updates['nim'] = trim((string) $this->input('nim'));
        }

        if ($updates !== []) {
            $this->merge($updates);
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'nim' => [
                'sometimes',
                'required',
                'string',
                'digits_between:8,20',
                Rule::unique('users', 'nim')->ignore($this->user()?->id),
            ],
            'class_group_id' => ['required', Rule::exists('class_groups', 'id')->where('is_active', true)->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama lengkap wajib diisi.',
            'nim.required' => 'NIM wajib diisi.',
            'nim.digits_between' => 'NIM harus terdiri atas 8 sampai 20 digit angka.',
            'nim.unique' => 'NIM sudah terdaftar.',
            'class_group_id.required' => 'Kelas yang diwakili wajib dipilih.',
            'class_group_id.exists' => 'Kelas yang dipilih tidak tersedia atau sudah tidak aktif.',
        ];
    }
}
