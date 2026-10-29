<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->sentence(4),
            'description' => fake()->sentence(),
            'project_id' => Project::factory(),
            'milestone_id' => null,
            'due_date' => null,
            'priority' => 'medium',
            'status' => 'backlog',
        ];
    }
}
