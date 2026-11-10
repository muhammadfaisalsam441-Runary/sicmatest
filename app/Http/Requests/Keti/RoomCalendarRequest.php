<?php

namespace App\Http\Requests\Keti;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RoomCalendarRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isApprovedKeti() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tanggal' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.now()->addDays((int) config('sicma.reservation.max_days_ahead'))->toDateString()],
            'durasi' => ['nullable', 'integer', 'min:'.config('sicma.reservation.min_minutes'), 'max:'.config('sicma.reservation.max_minutes')],
            'q' => ['nullable', 'string', 'max:60'],
            'gedung' => ['nullable', 'string', 'max:20', 'regex:/^(lainnya|[1-9]\d*)$/'],
            'orang' => ['nullable', 'integer', 'min:1', 'max:500'],
        ];
    }

    /**
     * @return array<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('tanggal') || ! $this->filled('tanggal')) {
                return;
            }
            $date = CarbonImmutable::parse($this->input('tanggal'));
            if (! in_array($date->dayOfWeekIso, config('sicma.scheduler.days'), true)) {
                $validator->errors()->add('tanggal', 'Kalender pemesanan hanya menampilkan Senin–Jumat. Pilih hari kuliah.');
            }
        }];
    }

    protected function getRedirectUrl(): string
    {
        return route('keti.rooms.calendar');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tanggal.date_format' => 'Pilih tanggal yang valid.',
            'tanggal.after_or_equal' => 'Tanggal pemesanan tidak boleh sudah lewat.',
            'tanggal.before_or_equal' => 'Pemesanan hanya tersedia sampai '.config('sicma.reservation.max_days_ahead').' hari ke depan.',
        ];
    }
}
