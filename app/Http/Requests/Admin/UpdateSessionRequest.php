<?php

namespace App\Http\Requests\Admin;

use App\Enums\SessionMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ubah penempatan sesi draf (hari, jam, ruang, mode).
 */
class UpdateSessionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'start_time' => $this->input('start_time') ? str_replace('.', ':', (string) $this->input('start_time')) : null,
            'end_time' => $this->input('end_time') ? str_replace('.', ':', (string) $this->input('end_time')) : null,
        ]);
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
            'mode' => ['required', Rule::enum(SessionMode::class)],
            'day_of_week' => ['nullable', 'required_unless:mode,unscheduled', 'integer', Rule::in(config('sicma.scheduler.days'))],
            'start_time' => ['nullable', 'required_unless:mode,unscheduled', 'date_format:H:i', "after_or_equal:{$open}", "before:{$close}"],
            'end_time' => ['nullable', 'required_unless:mode,unscheduled', 'date_format:H:i', 'after:start_time', "before_or_equal:{$close}"],
            'room_id' => ['nullable', 'required_if:mode,offline', Rule::exists('rooms', 'id')->whereNull('deleted_at')],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                if ($validator->errors()->isNotEmpty() || $this->input('mode') === SessionMode::Unscheduled->value) {
                    return;
                }

                if ((int) $this->input('day_of_week') !== 5) {
                    return;
                }

                $placement = $this->placement();
                $fridayBreak = config('sicma.scheduler.friday_break', ['start' => 720, 'end' => 790]);

                if ($placement['start_minute'] !== null && $placement['end_minute'] !== null
                    && $placement['start_minute'] < $fridayBreak['end'] && $placement['end_minute'] > $fridayBreak['start']) {
                    $validator->errors()->add('start_time', 'Pada hari Jumat pukul 12.00–13.10 tidak ada perkuliahan.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['day_of_week.in' => 'Perkuliahan hanya boleh dijadwalkan Senin–Jumat.'];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'day_of_week' => 'hari',
            'start_time' => 'jam mulai',
            'end_time' => 'jam selesai',
            'room_id' => 'ruang',
        ];
    }

    /**
     * Data untuk ScheduleDraftService::updateSession (jam dalam menit sejak 00.00).
     *
     * @return array{mode: string, day_of_week: int|null, start_minute: int|null, end_minute: int|null, room_id: int|null}
     */
    public function placement(): array
    {
        $minutes = function (?string $time): ?int {
            if ($time === null || $time === '') {
                return null;
            }

            [$hour, $minute] = array_map('intval', explode(':', $time));

            return $hour * 60 + $minute;
        };

        return [
            'mode' => $this->validated('mode'),
            'day_of_week' => $this->validated('day_of_week') !== null ? (int) $this->validated('day_of_week') : null,
            'start_minute' => $minutes($this->validated('start_time')),
            'end_minute' => $minutes($this->validated('end_time')),
            'room_id' => $this->validated('room_id') !== null ? (int) $this->validated('room_id') : null,
        ];
    }
}
