<?php

use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\PersonalAccess;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    config(['solotasks.username' => 'michele-test', 'solotasks.password_hash' => Hash::make('test-password-123')]);
    $this->withSession(['personal_access' => app(PersonalAccess::class)->fingerprint()]);
});

it('persists task details with medium priority and backlog defaults', function (): void {
    $project = Project::factory()->create();
    $milestone = Milestone::factory()->for($project)->create(['due_date' => '2026-12-31']);

    $response = $this->postJson('/api/v1/tasks', [
        'name' => 'Pianificare il backup', 'description' => 'Verificare il recupero.',
        'project_id' => $project->id, 'milestone_id' => $milestone->id, 'due_date' => '2026-11-04',
    ])->assertCreated()->assertJsonPath('data.priority', 'medium')->assertJsonPath('data.status', 'backlog')
        ->assertJsonPath('data.milestone.name', $milestone->name)->assertJsonPath('data.due_date', '2026-11-04');
    $this->assertDatabaseHas('tasks', [
        'id' => $response->json('data.id'), 'name' => 'Pianificare il backup', 'description' => 'Verificare il recupero.',
        'project_id' => $project->id, 'milestone_id' => $milestone->id, 'priority' => 'medium', 'status' => 'backlog',
    ]);
    $this->getJson('/api/v1/board?month=2026-11')->assertJsonPath('tasks.0.name', 'Pianificare il backup');
});

it('rejects tasks missing a name or project with 422', function (): void {
    $this->withHeader('Accept-Language', 'it-IT')->postJson('/api/v1/tasks', [])->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'Enter a task name.')
        ->assertJsonPath('errors.project_id.0', 'Select a project.');
    $this->assertDatabaseCount('tasks', 0);
});

it('validates task values without persisting invalid input', function (array $fields, string $key, string $message): void {
    $project = Project::factory()->create();

    $this->postJson('/api/v1/tasks', array_merge(['name' => 'Test', 'project_id' => $project->id], $fields))
        ->assertUnprocessable()->assertJsonPath('errors.'.$key.'.0', $message);
    $this->assertDatabaseCount('tasks', 0);
})->with([
    'invalid priority' => [['priority' => 'urgent'], 'priority', 'Choose a high, medium or low priority.'],
    'invalid status' => [['status' => 'waiting'], 'status', 'Choose one of the five board columns.'],
    'impossible date' => [['due_date' => '2026-02-30'], 'due_date', 'Enter a valid due date in YYYY-MM-DD format.'],
    'timestamp instead of date' => [['due_date' => '2026-11-01T23:00:00Z'], 'due_date', 'Enter a valid due date in YYYY-MM-DD format.'],
    'unknown project' => [['project_id' => 9999], 'project_id', 'The selected project does not exist.'],
]);

it('prevents cross project milestone associations with 422', function (): void {
    $project = Project::factory()->create();
    $otherMilestone = Milestone::factory()->create();

    $this->postJson('/api/v1/tasks', ['name' => 'Test', 'project_id' => $project->id, 'milestone_id' => $otherMilestone->id])
        ->assertUnprocessable()->assertJsonPath('errors.milestone_id.0', 'The milestone must belong to the same project as the task.');
    $this->assertDatabaseCount('tasks', 0);
});

it('requires clearing the old milestone when moving a task to another project', function (): void {
    $milestone = Milestone::factory()->create();
    $task = Task::factory()->create(['project_id' => $milestone->project_id, 'milestone_id' => $milestone->id]);
    $other = Project::factory()->create();

    $this->patchJson('/api/v1/tasks/'.$task->id, ['project_id' => $other->id])
        ->assertUnprocessable()->assertJsonValidationErrors('milestone_id');
    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'project_id' => $milestone->project_id]);
    $this->patchJson('/api/v1/tasks/'.$task->id, ['project_id' => $other->id, 'milestone_id' => null])->assertOk();
    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'project_id' => $other->id, 'milestone_id' => null]);
});

it('filters board and compact calendar while allowing the extended month to request all projects independently', function (): void {
    $one = Task::factory()->create(['due_date' => '2026-11-10']);
    Task::factory()->create(['due_date' => '2026-11-10']);

    $this->getJson('/api/v1/board?month=2026-11&project_id='.$one->project_id)->assertOk()
        ->assertJsonCount(1, 'tasks')->assertJsonPath('calendar.total', 1)->assertJsonPath('calendar.days.0.count', 1);
    $this->getJson('/api/v1/board?month=2026-11')->assertOk()
        ->assertJsonCount(2, 'tasks')->assertJsonPath('calendar.total', 2)->assertJsonPath('calendar.days.0.count', 2);
});

it('uses only task due dates and applies the same Done rule to heat counts and day cells', function (): void {
    $milestone = Milestone::factory()->create(['due_date' => '2026-12-20']);
    Task::factory()->create(['project_id' => $milestone->project_id, 'milestone_id' => $milestone->id, 'due_date' => '2026-11-10']);
    Task::factory()->create(['due_date' => '2026-11-10', 'status' => 'done']);
    Task::factory()->create(['due_date' => null]);
    Task::factory()->create(['due_date' => '2026-12-10']);

    $this->getJson('/api/v1/board?month=2026-11')->assertJsonCount(4, 'tasks')->assertJsonPath('calendar.total', 1)
        ->assertJsonPath('calendar.days.0.date', '2026-11-10')->assertJsonPath('calendar.days.0.count', 1)->assertJsonCount(1, 'calendar.days.0.tasks');
    $this->getJson('/api/v1/board?month=2026-11&include_done=1')->assertJsonPath('calendar.total', 2)
        ->assertJsonPath('calendar.days.0.count', 2)->assertJsonCount(2, 'calendar.days.0.tasks');
    $this->getJson('/api/v1/board?month=2026-12')->assertJsonCount(1, 'calendar.days')
        ->assertJsonPath('calendar.days.0.date', '2026-12-10');
});

it('moves a task between dates and columns and removes the old calendar occurrence', function (): void {
    $task = Task::factory()->create(['due_date' => '2026-11-10']);

    $this->patchJson('/api/v1/tasks/'.$task->id, ['due_date' => '2026-12-02', 'status' => 'in_review', 'priority' => 'high'])
        ->assertOk()->assertJsonPath('data.due_date', '2026-12-02')->assertJsonPath('data.status', 'in_review');
    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'priority' => 'high', 'status' => 'in_review']);
    $this->getJson('/api/v1/board?month=2026-11')->assertJsonCount(0, 'calendar.days');
    $this->getJson('/api/v1/board?month=2026-12')->assertJsonPath('calendar.days.0.date', '2026-12-02')->assertJsonPath('calendar.total', 1);
});

it('removes a task deadline without removing the task from its board', function (): void {
    $task = Task::factory()->create(['due_date' => '2026-11-10']);

    $this->patchJson('/api/v1/tasks/'.$task->id, ['due_date' => null])->assertOk();
    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'due_date' => null]);
    $this->getJson('/api/v1/board?month=2026-11')->assertJsonCount(1, 'tasks')->assertJsonPath('calendar.total', 0);
});

it('keeps task deadlines unchanged when a milestone is updated or removed', function (): void {
    $milestone = Milestone::factory()->create();
    $task = Task::factory()->create(['project_id' => $milestone->project_id, 'milestone_id' => $milestone->id, 'due_date' => '2026-11-04']);

    $this->patchJson('/api/v1/milestones/'.$milestone->id, ['name' => 'Updated', 'due_date' => '2027-01-01'])->assertOk();
    $this->getJson('/api/v1/board?month=2026-11')->assertJsonPath('tasks.0.due_date', '2026-11-04');
    $this->deleteJson('/api/v1/milestones/'.$milestone->id)->assertNoContent();
    $this->assertDatabaseMissing('milestones', ['id' => $milestone->id]);
    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'milestone_id' => null]);
    $this->getJson('/api/v1/board?month=2026-11')->assertJsonPath('tasks.0.due_date', '2026-11-04');
});

it('creates and updates projects and milestones then prevents deleting a populated project', function (): void {
    $project = $this->postJson('/api/v1/projects', ['name' => 'Casa', 'color' => '#ab1234'])->assertCreated()->json('data.id');
    $this->patchJson('/api/v1/projects/'.$project, ['name' => 'Casa nuova', 'color' => '#abc123'])->assertOk();
    $milestone = $this->postJson('/api/v1/milestones', ['name' => 'Preparazione', 'due_date' => '2026-12-01', 'project_id' => $project])->assertCreated()->json('data.id');

    $this->deleteJson('/api/v1/projects/'.$project)->assertConflict();
    $this->assertDatabaseHas('projects', ['id' => $project, 'name' => 'Casa nuova', 'color' => '#abc123']);
    $this->assertDatabaseHas('milestones', ['id' => $milestone, 'project_id' => $project]);
    $this->deleteJson('/api/v1/milestones/'.$milestone)->assertNoContent();
    $this->deleteJson('/api/v1/projects/'.$project)->assertNoContent();
    $this->assertDatabaseMissing('projects', ['id' => $project]);
});

it('deletes a task from both the board and calendar', function (): void {
    $task = Task::factory()->create(['due_date' => '2026-11-10']);

    $this->deleteJson('/api/v1/tasks/'.$task->id)->assertNoContent();
    $this->assertModelMissing($task);
    $this->getJson('/api/v1/board?month=2026-11')->assertJsonCount(0, 'tasks')->assertJsonPath('calendar.total', 0);
});

it('ignores unexpected attributes and keeps user content out of the public HTML shell', function (): void {
    $project = Project::factory()->create();

    $this->postJson('/api/v1/tasks', ['name' => '<script>alert(1)</script>', 'project_id' => $project->id, 'id' => 9000, 'created_at' => '2000-01-01'])
        ->assertCreated()->assertJsonPath('data.name', '<script>alert(1)</script>');
    $this->assertDatabaseMissing('tasks', ['id' => 9000]);
    $this->get('/')->assertOk()->assertDontSee('<script>alert(1)</script>', false);
});
