<?php

namespace App\Http\Requests\Keti;

use App\Services\Room\ReservationService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Data pesanan dari Keti. Kelas TIDAK diterima dari request — selalu diambil dari akun di server (B02).
 */
class StoreReservationRequest extends FormRequest
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
            'room_id' => ['required', 'integer', Rule::exists('rooms', 'id')->whereNull('deleted_at')],
            'start_at' => ['required', 'date'],
            'duration' => ['required', 'integer', 'min:'.config('sicma.reservation.min_minutes'), 'max:'.config('sicma.reservation.max_minutes')],
            'purpose' => ['required', 'string', 'max:120', function (string $attribute, mixed $value, Closure $fail): void {
                if (Str::lower(Str::squish((string) $value)) === Str::lower(ReservationService::REPLACEMENT_PURPOSE)) {
                    $fail('Untuk mengganti jadwal kuliah, gunakan Pindahkan di My Class.');
                }
            }],
            'participants' => ['nullable', 'integer', 'min:1', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
