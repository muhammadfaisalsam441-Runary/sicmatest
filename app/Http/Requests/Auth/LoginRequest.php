<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
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
            'identity' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Masuk dengan email (mengandung "@") atau NIM. Akun nonaktif ditolak dengan pesan yang sama.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $identity = trim((string) $this->input('identity'));
        $field = str_contains($identity, '@') ? 'email' : 'nim';

        $credentials = [
            $field => $field === 'email' ? strtolower($identity) : $identity,
            'password' => (string) $this->input('password'),
            'is_active' => true,
        ];

        if (! Auth::attempt($credentials, $this->boolean('remember'))) {
            throw ValidationException::withMessages([
                'identity' => 'Email, NIM, atau kata sandi belum sesuai. Silakan coba lagi.',
            ]);
        }
    }
}
