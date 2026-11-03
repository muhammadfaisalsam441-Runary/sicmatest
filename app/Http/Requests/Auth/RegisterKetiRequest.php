<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterKetiRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
            'nim' => trim((string) $this->input('nim')),
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
            'name' => ['required', 'string', 'max:120'],
            'nim' => ['required', 'digits_between:8,20', Rule::unique('users', 'nim')],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'class_group_id' => ['required', Rule::exists('class_groups', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'password' => ['required', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nim.unique' => 'NIM sudah terdaftar.',
            'email.unique' => 'Email sudah terdaftar.',
        ];
    }
}
