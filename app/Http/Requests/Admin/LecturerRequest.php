<?php

namespace App\Http\Requests\Admin;

use App\Enums\LecturerHomebase;
use App\Enums\LecturerProfileStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tambah/ubah data dosen. Data belum diketahui dibiarkan kosong (B16).
 */
class LecturerRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'nidn' => ['nullable', 'string', 'max:20', Rule::unique('lecturers', 'nidn')->ignore($this->route('lecturer')?->id)],
            'nip' => ['nullable', 'regex:/^\d{18}$/', Rule::unique('lecturers', 'nip')->ignore($this->route('lecturer')?->id)],
            'homebase' => ['nullable', Rule::enum(LecturerHomebase::class)],
            'rank' => ['nullable', 'string', 'max:60'],
            'grade' => ['nullable', 'string', 'max:10'],
            'functional_position' => ['nullable', 'string', 'max:60'],
            'profile_status' => ['required', Rule::enum(LecturerProfileStatus::class)],
            'leadership_position' => ['nullable', 'string', 'max:80'],
            'leadership_from' => ['nullable', 'required_with:leadership_position', 'date'],
            'leadership_to' => ['nullable', 'date', 'after_or_equal:leadership_from'],
            'target_sks' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'max_sks' => ['nullable', 'numeric', 'min:0', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama dosen',
            'nip' => 'NIP',
            'rank' => 'pangkat',
            'grade' => 'golongan',
            'functional_position' => 'jabatan fungsional',
            'leadership_position' => 'jabatan pimpinan',
            'leadership_from' => 'mulai menjabat',
            'leadership_to' => 'akhir menjabat',
            'target_sks' => 'target SKS',
            'max_sks' => 'maksimum SKS',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nip.regex' => 'NIP terdiri atas 18 angka.',
        ];
    }
}
