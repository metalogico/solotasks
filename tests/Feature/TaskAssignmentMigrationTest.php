<?php

use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config(['database.connections.assignment_migration' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
});

afterEach(function (): void {
    DB::purge('assignment_migration');
});

it('installs the assignment column with a database default on a fresh database', function (): void {
    $this->artisan('migrate', ['--database' => 'assignment_migration', '--force' => true])->assertSuccessful();
    $database = DB::connection('assignment_migration');

    $projectId = $database->table('projects')->insertGetId(['name' => 'Fresh project']);
    $taskId = $database->table('tasks')->insertGetId(['name' => 'Fresh task', 'project_id' => $projectId]);

    expect($database->table('tasks')->where('id', $taskId)->value('assignee'))->toBe('me');
});

it('upgrades existing tasks to Me and rolls back without losing task or project data', function (): void {
    $migration = 'database/migrations/2026_11_05_000000_add_assignee_to_tasks_table.php';
    $previousMigrations = array_values(array_filter(
        glob(database_path('migrations/*.php')),
        fn (string $path): bool => basename($path) < basename($migration),
    ));
    $this->artisan('migrate', [
        '--database' => 'assignment_migration', '--path' => $previousMigrations, '--realpath' => true, '--force' => true,
    ])->assertSuccessful();

    $database = DB::connection('assignment_migration');
    expect($database->getSchemaBuilder()->hasColumn('tasks', 'assignee'))->toBeFalse();
    $projectId = $database->table('projects')->insertGetId([
        'name' => 'Existing project', 'color' => '#244f3d', 'created_at' => '2026-10-27 12:00:00',
    ]);
    $milestoneId = $database->table('milestones')->insertGetId([
        'name' => 'Existing milestone', 'project_id' => $projectId, 'due_date' => '2026-12-01',
    ]);
    $database->table('tasks')->insert([
        [
            'name' => 'Existing scheduled task', 'description' => 'Keep every field.',
            'project_id' => $projectId, 'milestone_id' => $milestoneId, 'due_date' => '2026-11-10',
            'priority' => 'high', 'status' => 'in_progress', 'created_at' => '2026-10-28 12:00:00',
            'updated_at' => '2026-10-29 13:00:00',
        ],
        [
            'name' => 'Existing undated task', 'description' => null,
            'project_id' => $projectId, 'milestone_id' => null, 'due_date' => null,
            'priority' => 'low', 'status' => 'done', 'created_at' => '2026-10-28 12:00:00',
            'updated_at' => '2026-10-29 13:00:00',
        ],
    ]);
    $originalTasks = $database->table('tasks')->orderBy('id')->get();
    $originalProjects = $database->table('projects')->get();
    $originalMilestones = $database->table('milestones')->get();

    $this->artisan('migrate', [
        '--database' => 'assignment_migration', '--path' => $migration, '--force' => true,
    ])->assertSuccessful();

    expect($database->table('tasks')->orderBy('id')->pluck('assignee')->all())->toBe(['me', 'me']);
    foreach ($originalTasks as $task) {
        expect((array) $database->table('tasks')->where('id', $task->id)->first())
            ->toBe([...((array) $task), 'assignee' => 'me']);
    }
    $database->table('tasks')->where('id', $originalTasks[0]->id)->update(['assignee' => 'agent']);

    $this->artisan('migrate:rollback', [
        '--database' => 'assignment_migration', '--path' => $migration, '--step' => 1, '--force' => true,
    ])->assertSuccessful();

    expect($database->getSchemaBuilder()->hasColumn('tasks', 'assignee'))->toBeFalse();
    expect($database->table('tasks')->orderBy('id')->get()->all())->toEqual($originalTasks->all());
    expect($database->table('projects')->get()->all())->toEqual($originalProjects->all());
    expect($database->table('milestones')->get()->all())->toEqual($originalMilestones->all());

    $this->artisan('migrate', [
        '--database' => 'assignment_migration', '--path' => $migration, '--force' => true,
    ])->assertSuccessful();
    expect($database->table('tasks')->orderBy('id')->pluck('assignee')->all())->toBe(['me', 'me']);
});
