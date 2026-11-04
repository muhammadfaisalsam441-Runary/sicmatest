<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Tanggal semester, UTS, dan UAS.
 */
class PeriodRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:60'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'uts_start' => ['nullable', 'date', 'after_or_equal:start_date', 'before_or_equal:end_date'],
            'uts_end' => ['nullable', 'date', 'after_or_equal:uts_start', 'before_or_equal:end_date'],
            'uas_start' => ['nullable', 'date', 'after_or_equal:start_date', 'before_or_equal:end_date'],
            'uas_end' => ['nullable', 'date', 'after_or_equal:uas_start', 'before_or_equal:end_date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'start_date' => 'mulai kuliah',
            'end_date' => 'akhir kuliah',
            'uts_start' => 'mulai UTS',
            'uts_end' => 'selesai UTS',
            'uas_start' => 'mulai UAS',
            'uas_end' => 'selesai UAS',
        ];
    }
}
