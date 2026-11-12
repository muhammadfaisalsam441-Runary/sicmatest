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
                'max:20',
                Rule::unique('users', 'nim')->ignore($this->user()?->id),
            ],
            'class_group_id' => ['required', Rule::exists('class_groups', 'id')->where('is_active', true)->whereNull('deleted_at')],
        ];
    }
}
