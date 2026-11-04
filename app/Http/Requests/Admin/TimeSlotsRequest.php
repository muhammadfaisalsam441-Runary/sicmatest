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
        $open = (string) config('sicma.operating_hours.start', '07:30');
        $close = (string) config('sicma.operating_hours.end', '17:30');

        return [
            'slots' => ['required', 'array'],
            'slots.*.start' => ['required', 'date_format:H:i', "after_or_equal:{$open}", "before:{$close}"],
            'slots.*.end' => ['required', 'date_format:H:i', 'after:slots.*.start', "before_or_equal:{$close}"],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slots.*.end.after' => 'Jam selesai slot harus setelah jam mulai slot.',
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
