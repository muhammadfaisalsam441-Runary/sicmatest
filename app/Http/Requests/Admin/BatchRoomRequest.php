<?php

namespace App\Http\Requests\Admin;

use App\Enums\RoomType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Aksi massal Kelola Ruang: gedung, tipe, fasilitas, atau kapasitas untuk beberapa ruang sekaligus.
 */
class BatchRoomRequest extends FormRequest
{
    public const ACTIONS = ['set_building', 'set_type', 'add_facilities', 'set_capacity'];

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(self::ACTIONS)],
            'room_ids' => ['required', 'array', 'min:1'],
            'room_ids.*' => ['integer', Rule::exists('rooms', 'id')->whereNull('deleted_at')],
            'building' => ['nullable', 'string', 'max:60'],
            'type' => ['exclude_unless:action,set_type', 'required', Rule::enum(RoomType::class)],
            'facilities' => ['exclude_unless:action,add_facilities', 'required', 'string', 'max:255'],
            'capacity' => ['exclude_unless:action,set_capacity', 'required', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'room_ids.required' => 'Pilih minimal satu ruang.',
            'type.required' => 'Pilih tipe ruang.',
            'facilities.required' => 'Tulis minimal satu fasilitas, pisahkan dengan koma.',
            'capacity.required' => 'Masukkan kapasitas ruang.',
            'capacity.integer' => 'Isi dengan angka bulat, misalnya 40.',
            'capacity.min' => 'Kapasitas minimal 1 orang.',
            'capacity.max' => 'Kapasitas maksimal 1.000 orang.',
        ];
    }
}
