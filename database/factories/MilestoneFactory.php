<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class MilestoneFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->words(3, true), 'due_date' => '2026-11-30', 'project_id' => Project::factory()];
    }
}
