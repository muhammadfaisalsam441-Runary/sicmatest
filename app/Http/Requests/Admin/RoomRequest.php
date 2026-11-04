<?php

namespace App\Http\Requests\Admin;

use App\Enums\RoomType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Formulir "Tambah ruang" / "Ubah ruang". Gedung diisi nama; kosong = "Gedung belum didata" (B16).
 */
class RoomRequest extends FormRequest
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
            'code' => Str::upper(Str::slug((string) ($this->input('code') ?: $this->input('name')))),
            'building' => Str::of((string) $this->input('building'))->squish()->toString() ?: null,
            'is_bookable' => $this->boolean('is_bookable', true),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $room = $this->route('room');

        return [
            'name' => ['required', 'string', 'max:60'],
            'code' => ['required', 'string', 'max:40', Rule::unique('rooms', 'code')->ignore($room?->id)],
            'type' => ['required', Rule::enum(RoomType::class)],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'floor' => ['nullable', 'integer', 'min:0', 'max:20'],
            'building' => ['nullable', 'string', 'max:60'],
            'facilities' => ['nullable', 'string', 'max:255'],
            'is_bookable' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama ruang',
            'type' => 'jenis ruang',
            'capacity' => 'kapasitas',
            'floor' => 'lantai',
            'building' => 'gedung',
            'facilities' => 'fasilitas',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'capacity.integer' => 'Isi dengan angka, misalnya 40.',
            'code.unique' => 'Nama ruang sudah terdaftar.',
        ];
    }

    /**
     * Daftar fasilitas dari isian dipisah koma.
     *
     * @return list<string>
     */
    public function facilityNames(): array
    {
        return collect(explode(',', (string) $this->validated('facilities')))
            ->map(fn (string $name): string => Str::of($name)->trim()->ucfirst()->toString())
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
