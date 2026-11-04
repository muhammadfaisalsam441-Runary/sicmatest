<?php

namespace App\Http\Requests\Admin;

use App\Enums\CalendarExceptionType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tambah hari libur / pengecualian tanggal (satu hari atau rentang).
 */
class CalendarExceptionRequest extends FormRequest
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
            'date' => ['required', 'date'],
            'until' => ['nullable', 'date', 'after_or_equal:date'],
            'type' => ['required', Rule::enum(CalendarExceptionType::class)],
            'note' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'date' => 'tanggal',
            'until' => 'sampai tanggal',
            'note' => 'keterangan',
        ];
    }
}
