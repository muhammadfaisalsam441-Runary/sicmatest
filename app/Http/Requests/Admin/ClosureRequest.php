<?php

namespace App\Http\Requests\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ClosureRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $merge = [
            'mode' => $this->input('mode') ?: 'once',
        ];

        if (is_array($this->input('days_of_week'))) {
            $merge['days_of_week'] = array_values(array_map('intval', $this->input('days_of_week')));
        }

        $this->merge($merge);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isRecurring = $this->input('mode') === 'recurring';

        return [
            'mode' => ['required', Rule::in(['once', 'recurring'])],
            'room_id' => ['required', Rule::exists('rooms', 'id')->whereNull('deleted_at')],
            'start_at' => $isRecurring ? ['nullable'] : ['required', 'date'],
            'end_at' => $isRecurring ? ['nullable'] : ['required', 'date', 'after:start_at'],
            'start_date' => $isRecurring ? ['required', 'date'] : ['nullable'],
            'end_date' => $isRecurring ? ['required', 'date', 'after_or_equal:start_date'] : ['nullable'],
            'start_time' => $isRecurring ? ['required', 'date_format:H:i'] : ['nullable'],
            'end_time' => $isRecurring ? ['required', 'date_format:H:i', 'after:start_time'] : ['nullable'],
            'days_of_week' => $isRecurring ? ['required', 'array', 'min:1'] : ['nullable', 'array'],
            'days_of_week.*' => ['integer', 'between:1,7'],
            'reason' => ['required', 'string', 'max:200'],
            'confirm_impact' => ['nullable', 'boolean'],
            'cancel_impacted' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || $this->input('mode') !== 'recurring') {
                    return;
                }

                $start = CarbonImmutable::parse((string) $this->input('start_date'))->startOfDay();
                $end = CarbonImmutable::parse((string) $this->input('end_date'))->startOfDay();

                if ($start->diffInDays($end) > 366) {
                    $validator->errors()->add('end_date', 'Rentang penutupan berulang maksimal 366 hari.');

                    return;
                }

                $days = array_map('intval', (array) $this->input('days_of_week', []));
                $hasMatch = false;

                for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
                    if (in_array($date->dayOfWeekIso, $days, true)) {
                        $hasMatch = true;
                        break;
                    }
                }

                if (! $hasMatch) {
                    $validator->errors()->add('days_of_week', 'Tidak ada tanggal pada rentang tersebut yang sesuai dengan hari yang dipilih.');
                }
            },
        ];
    }
}
