<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ubah jam slot kuliah.
 */
class TimeSlotsRequest extends FormRequest
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
            'slots' => ['required', 'array'],
            'slots.*.start' => ['required', 'date_format:H:i'],
            'slots.*.end' => ['required', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'slots.*.start' => 'jam mulai slot',
            'slots.*.end' => 'jam selesai slot',
        ];
    }
}
