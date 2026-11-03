<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'nim' => fake()->unique()->numerify('25020#####'),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Keti,
            'can_publish' => false,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Admin tanpa izin menetapkan jadwal.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'nim' => null,
            'role' => UserRole::Admin,
        ]);
    }

    /**
     * Super admin dengan izin menetapkan jadwal (publish-schedule).
     */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'nim' => null,
            'role' => UserRole::SuperAdmin,
            'can_publish' => true,
        ]);
    }
}
