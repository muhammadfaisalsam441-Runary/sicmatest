<?php

namespace App\Http\Requests\Keti;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RoomSearchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:60'],
            'tanggal' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.now()->addDays((int) config('sicma.reservation.max_days_ahead'))->toDateString()],
            'mulai' => ['nullable', 'regex:/^(sekarang|([01]\d|2[0-3]):[0-5]\d)$/'],
            'durasi' => ['nullable', 'integer', 'min:'.config('sicma.reservation.min_minutes'), 'max:'.config('sicma.reservation.max_minutes')],
            'gedung' => ['nullable', 'string', 'max:20'],
            'orang' => ['nullable', 'integer', 'min:1', 'max:500'],
            'urutan' => ['nullable', 'in:terlama,nama,kapasitas'],
        ];
    }

    /**
     * @return array<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('tanggal') || $validator->errors()->has('mulai')) {
                return;
            }

            $date = $this->filled('tanggal') ? $this->input('tanggal') : now()->toDateString();
            $time = $this->input('mulai');

            if ($time && $time !== 'sekarang') {
                $start = CarbonImmutable::parse($date.' '.$time);
                if ($start->lessThan(CarbonImmutable::now()->subMinutes(5))) {
                    $validator->errors()->add('mulai', 'Jam mulai tidak boleh di masa lampau.');
                }
            }
        }];
    }

    /**
     * Waktu mulai pencarian: "sekarang" atau tanggal + jam yang dipilih.
     */
    public function start(): CarbonImmutable
    {
        $now = CarbonImmutable::now()->startOfMinute();
        $time = $this->validated('mulai') ?? 'sekarang';
        $date = $this->validated('tanggal');

        if ($time === 'sekarang') {
            return $date === null || $date === $now->toDateString() ? $now : CarbonImmutable::parse($date.' '.config('sicma.operating_hours.start'));
        }

        return CarbonImmutable::parse(($date ?? $now->toDateString()).' '.$time);
    }

    public function duration(): int
    {
        return (int) ($this->validated('durasi') ?? config('sicma.reservation.default_minutes'));
    }
}
