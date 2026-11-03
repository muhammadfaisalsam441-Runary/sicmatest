<?php

namespace Database\Factories;

use App\Enums\KetiApplicationStatus;
use App\Models\ClassGroup;
use App\Models\KetiApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KetiApplication>
 */
class KetiApplicationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'class_group_id' => ClassGroup::factory(),
            'status' => KetiApplicationStatus::Pending,
        ];
    }

    /**
     * Pengajuan yang sudah disetujui admin.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => KetiApplicationStatus::Approved,
            'reviewed_by' => User::factory()->admin(),
            'reviewed_at' => now(),
        ]);
    }

    /**
     * Pengajuan yang diminta diperbaiki.
     */
    public function needsRevision(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => KetiApplicationStatus::NeedsRevision,
            'review_note' => 'Kelas yang dipilih belum sesuai data NIM.',
            'reviewed_by' => User::factory()->admin(),
            'reviewed_at' => now(),
        ]);
    }
}
