<?php

namespace App\Http\Requests\Admin;

use App\Services\Scheduling\SchedulerService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pengaturan Generate: cakupan, kelas, dan waktu pencarian.
 */
class StartSchedulingRequest extends FormRequest
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
            'scope' => ['required', Rule::in(array_keys(SchedulerService::SCOPES))],
            'class_ids' => ['nullable', 'required_if:scope,kelas', 'array'],
            'class_ids.*' => ['integer', Rule::exists('class_groups', 'id')],
            'time_limit' => ['sometimes', 'integer', Rule::in(array_values(config('sicma.scheduler.time_limits')))],
            'strategy' => ['sometimes', 'string', Rule::in(array_keys(SchedulerService::STRATEGIES))],
            'label' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'scope' => 'cakupan',
            'class_ids' => 'kelas',
            'time_limit' => 'waktu pencarian',
            'strategy' => 'mode penyusunan',
            'label' => 'label draf',
        ];
    }

    /**
     * @return array{type: string, class_ids: list<int>, strategy: string, label: string|null}
     */
    public function scopeData(): array
    {
        return [
            'type' => $this->validated('scope'),
            'class_ids' => array_values(array_map('intval', $this->validated('class_ids') ?? [])),
            'strategy' => $this->validated('strategy') ?? SchedulerService::STRATEGY_PRESERVE,
            'label' => ($label = trim((string) ($this->validated('label') ?? ''))) !== '' ? $label : null,
        ];
    }
}
