<?php

use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('rejects demo loading outside local without deleting existing records', function (string $environment): void {
    $milestone = Milestone::factory()->create();
    $task = Task::factory()->for($milestone->project)->for($milestone)->create();
    app()->instance('env', $environment);

    expect(fn () => app(DemoSeeder::class)->run())
        ->toThrow(RuntimeException::class, 'Demo data can only be loaded in the local environment.');

    $this->assertModelExists($task);
    $this->assertModelExists($milestone);
    $this->assertModelExists($milestone->project);
    $this->assertDatabaseCount('projects', 1);
    $this->assertDatabaseCount('tasks', 1);
})->with(['production', 'staging', 'testing']);

it('replaces only local project data and can reload the same demo without duplicates', function (): void {
    $milestone = Milestone::factory()->create();
    $task = Task::factory()->for($milestone->project)->for($milestone)->create();
    $user = User::factory()->create();
    config(['solotasks.username' => 'keep-this-username', 'solotasks.password_hash' => 'keep-this-hash']);
    app()->instance('env', 'local');

    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    $this->assertModelMissing($task);
    $this->assertModelMissing($milestone);
    $this->assertModelMissing($milestone->project);
    $this->assertModelExists($user);
    $this->assertDatabaseCount('projects', 5);
    $this->assertDatabaseCount('milestones', 8);
    $this->assertDatabaseCount('tasks', 30);
    expect(config('solotasks.username'))->toBe('keep-this-username');
    expect(config('solotasks.password_hash'))->toBe('keep-this-hash');
    expect(Project::query()->withCount('tasks')->pluck('tasks_count', 'name')->all())->toEqual([
        "Here's My Shelf" => 8, 'Issued' => 7, 'promptprompt' => 6, 'Star Guilds' => 5, 'metalogico.dev' => 4,
    ]);
});

it('loads the agreed date distribution and project-owned milestone associations', function (): void {
    app()->instance('env', 'local');

    $this->seed(DemoSeeder::class);

    expect(Task::query()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->all())
        ->toEqual(['backlog' => 6, 'todo' => 8, 'in_progress' => 4, 'in_review' => 4, 'done' => 8]);
    expect(Task::query()->selectRaw('priority, COUNT(*) AS total')->groupBy('priority')->pluck('total', 'priority')->all())
        ->toEqual(['high' => 5, 'medium' => 19, 'low' => 6]);
    expect(Task::query()->whereBetween('due_date', ['2026-10-01', '2026-10-31'])->count())->toBe(15);
    expect(Task::query()->whereBetween('due_date', ['2026-11-01', '2026-11-30'])->count())->toBe(15);
    expect(Task::query()->where('status', 'done')->whereDate('due_date', '<', '2026-10-28')->count())->toBe(8);
    expect(Task::query()->where('status', '!=', 'done')->whereDate('due_date', '<', '2026-10-28')->count())->toBe(1);
    expect(Task::query()->whereDate('due_date', '2026-10-29')->distinct()->count('project_id'))->toBe(3);
    expect(Task::query()->whereDate('due_date', '2026-11-05')->distinct()->count('project_id'))->toBe(2);
    expect(Task::query()->whereNull('milestone_id')->count())->toBe(9);
    foreach (Task::query()->with('milestone')->whereNotNull('milestone_id')->get() as $task) {
        expect($task->milestone->project_id)->toBe($task->project_id);
    }
});

it('restores existing local records if demo insertion fails', function (): void {
    $milestone = Milestone::factory()->create();
    $task = Task::factory()->for($milestone->project)->for($milestone)->create();
    app()->instance('env', 'local');
    Task::creating(function (): void {
        throw new RuntimeException('Simulated insertion failure.');
    });

    expect(fn () => app(DemoSeeder::class)->run())
        ->toThrow(RuntimeException::class, 'Simulated insertion failure.');

    $this->assertModelExists($task);
    $this->assertModelExists($milestone);
    $this->assertModelExists($milestone->project);
    $this->assertDatabaseCount('projects', 1);
    $this->assertDatabaseCount('milestones', 1);
    $this->assertDatabaseCount('tasks', 1);
});
