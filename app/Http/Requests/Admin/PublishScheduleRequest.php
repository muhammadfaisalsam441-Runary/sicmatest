<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Tetapkan draf sebagai Jadwal resmi.
 */
class PublishScheduleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('publish-schedule') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'effective_from' => ['required', 'date', 'after_or_equal:today'],
            'lock_version' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'effective_from' => 'tanggal berlaku',
            'note' => 'catatan revisi',
        ];
    }
}
