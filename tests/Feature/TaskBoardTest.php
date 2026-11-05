<?php

use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\PersonalAccess;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    config(['solotasks.username' => 'test-user', 'solotasks.password_hash' => Hash::make('test-password-123')]);
    $this->withSession(['personal_access' => app(PersonalAccess::class)->fingerprint()]);
});

it('persists task details with medium priority and backlog defaults', function (): void {
    $project = Project::factory()->create();
    $milestone = Milestone::factory()->for($project)->create(['due_date' => '2026-12-31']);

    $response = $this->postJson('/api/v1/tasks', [
        'name' => 'Plan the backup', 'description' => 'Verify recovery.',
        'project_id' => $project->id, 'milestone_id' => $milestone->id, 'due_date' => '2026-11-04',
    ])->assertCreated()->assertJsonPath('data.priority', 'medium')->assertJsonPath('data.status', 'backlog')
        ->assertJsonPath('data.assignee', 'me')
        ->assertJsonPath('data.milestone.name', $milestone->name)->assertJsonPath('data.due_date', '2026-11-04');
    $this->assertDatabaseHas('tasks', [
        'id' => $response->json('data.id'), 'name' => 'Plan the backup', 'description' => 'Verify recovery.',
        'project_id' => $project->id, 'milestone_id' => $milestone->id, 'priority' => 'medium', 'status' => 'backlog',
        'assignee' => 'me',
    ]);
    $this->getJson('/api/v1/board?month=2026-11')->assertJsonPath('tasks.0.name', 'Plan the backup')
        ->assertJsonPath('tasks.0.assignee', 'me')->assertJsonPath('calendar.days.0.tasks.0.assignee', 'me');
});

it('creates a task with an explicit assignee', function (string $assignee): void {
    $project = Project::factory()->create();

    $id = $this->postJson('/api/v1/tasks', [
        'name' => 'Review the API', 'project_id' => $project->id, 'assignee' => $assignee,
    ])->assertCreated()->assertJsonPath('data.assignee', $assignee)->json('data.id');

    $this->assertDatabaseHas('tasks', ['id' => $id, 'assignee' => $assignee]);
    $this->getJson('/api/v1/board')->assertJsonPath('tasks.0.assignee', $assignee);
})->with(['me', 'agent']);

it('reassigns a task and preserves its assignee during partial updates', function (): void {
    $task = Task::factory()->create();

    $this->patchJson('/api/v1/tasks/'.$task->id, ['assignee' => 'agent'])
        ->assertOk()->assertJsonPath('data.assignee', 'agent');
    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'assignee' => 'agent']);

    $this->patchJson('/api/v1/tasks/'.$task->id, ['status' => 'in_progress'])
        ->assertOk()->assertJsonPath('data.assignee', 'agent')->assertJsonPath('data.status', 'in_progress');
    $this->patchJson('/api/v1/tasks/'.$task->id, ['description' => 'Check edge cases.', 'due_date' => '2026-11-10'])
        ->assertOk()->assertJsonPath('data.assignee', 'agent');
    $this->getJson('/api/v1/board?month=2026-11')
        ->assertJsonPath('tasks.0.assignee', 'agent')->assertJsonPath('calendar.days.0.tasks.0.assignee', 'agent');

    $this->patchJson('/api/v1/tasks/'.$task->id, ['assignee' => 'me'])
        ->assertOk()->assertJsonPath('data.assignee', 'me');
    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'assignee' => 'me', 'status' => 'in_progress']);
});

it('rejects invalid assignment updates without changing the task', function (mixed $assignee): void {
    $task = Task::factory()->create(['assignee' => 'agent']);

    $this->patchJson('/api/v1/tasks/'.$task->id, ['assignee' => $assignee, 'status' => 'done'])
        ->assertUnprocessable()->assertJsonValidationErrors('assignee');
    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'assignee' => 'agent', 'status' => 'backlog']);
})->with([
    'unknown assignee' => ['human'],
    'null assignee' => [null],
    'empty assignee' => [''],
    'multiple assignees' => [['me', 'agent']],
]);

it('rejects tasks missing a name or project with 422', function (): void {
    $this->postJson('/api/v1/tasks', [])->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'project_id']);
    $this->assertDatabaseCount('tasks', 0);
});

it('validates task values without persisting invalid input', function (array $fields, string $key): void {
    $project = Project::factory()->create();

    $this->postJson('/api/v1/tasks', array_merge(['name' => 'Test', 'project_id' => $project->id], $fields))
        ->assertUnprocessable()->assertJsonValidationErrors([$key]);
    $this->assertDatabaseCount('tasks', 0);
})->with([
    'invalid priority' => [['priority' => 'urgent'], 'priority'],
    'invalid status' => [['status' => 'waiting'], 'status'],
    'unknown assignee' => [['assignee' => 'human'], 'assignee'],
    'null assignee' => [['assignee' => null], 'assignee'],
    'empty assignee' => [['assignee' => ''], 'assignee'],
    'multiple assignees' => [['assignee' => ['me', 'agent']], 'assignee'],
    'impossible date' => [['due_date' => '2026-02-30'], 'due_date'],
    'timestamp instead of date' => [['due_date' => '2026-11-01T23:00:00Z'], 'due_date'],
    'unknown project' => [['project_id' => 9999], 'project_id'],
]);

it('prevents cross project milestone associations with 422', function (): void {
    $project = Project::factory()->create();
    $otherMilestone = Milestone::factory()->create();

    $this->postJson('/api/v1/tasks', ['name' => 'Test', 'project_id' => $project->id, 'milestone_id' => $otherMilestone->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['milestone_id']);
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

it('filters assignments before calculating board tasks and calendar counts while retaining project totals', function (): void {
    $project = Project::factory()->create(['name' => 'A project']);
    $other = Project::factory()->create(['name' => 'B project']);
    $scheduled = Task::factory()->for($project)->create(['assignee' => 'agent', 'due_date' => '2026-11-10']);
    $done = Task::factory()->for($project)->create(['assignee' => 'agent', 'due_date' => '2026-11-10', 'status' => 'done']);
    $undated = Task::factory()->for($project)->create(['assignee' => 'agent', 'due_date' => null]);
    $nextMonth = Task::factory()->for($project)->create(['assignee' => 'agent', 'due_date' => '2026-12-02']);
    $personal = Task::factory()->for($project)->create(['assignee' => 'me', 'due_date' => '2026-11-10']);
    Task::factory()->for($other)->create(['assignee' => 'agent', 'due_date' => '2026-11-10']);

    $this->getJson('/api/v1/board?month=2026-11')->assertOk()
        ->assertJsonCount(6, 'tasks')->assertJsonPath('calendar.total', 3)->assertJsonPath('calendar.assignee', null);

    $this->getJson('/api/v1/board?month=2026-11&assignee=agent')->assertOk()
        ->assertJsonCount(5, 'tasks')->assertJsonPath('calendar.total', 2)
        ->assertJsonPath('calendar.days.0.count', 2)->assertJsonCount(2, 'calendar.days.0.tasks');

    $this->getJson('/api/v1/board?month=2026-11&assignee=me')->assertOk()
        ->assertJsonCount(1, 'tasks')->assertJsonPath('tasks.0.id', $personal->id)
        ->assertJsonPath('calendar.total', 1)->assertJsonPath('calendar.assignee', 'me')
        ->assertJsonPath('calendar.days.0.tasks.0.id', $personal->id);

    $query = '/api/v1/board?assignee=agent&project_id='.$project->id;
    $this->getJson($query.'&month=2026-11')->assertOk()
        ->assertJsonCount(4, 'tasks')->assertJsonPath('tasks.0.id', $scheduled->id)
        ->assertJsonPath('tasks.1.id', $done->id)->assertJsonPath('tasks.2.id', $nextMonth->id)
        ->assertJsonPath('tasks.3.id', $undated->id)->assertJsonPath('calendar.assignee', 'agent')
        ->assertJsonPath('calendar.total', 1)->assertJsonPath('calendar.days.0.count', 1)
        ->assertJsonCount(1, 'calendar.days.0.tasks')->assertJsonPath('calendar.days.0.tasks.0.id', $scheduled->id)
        ->assertJsonPath('calendar.days.0.tasks.0.assignee', 'agent')
        ->assertJsonCount(2, 'projects')->assertJsonPath('projects.0.tasks_count', 5)
        ->assertJsonPath('projects.1.tasks_count', 1);

    $this->getJson($query.'&month=2026-11&include_done=1')->assertOk()
        ->assertJsonCount(4, 'tasks')->assertJsonPath('calendar.total', 2)
        ->assertJsonPath('calendar.days.0.count', 2)->assertJsonCount(2, 'calendar.days.0.tasks');

    $this->getJson($query.'&month=2026-12')->assertOk()
        ->assertJsonCount(4, 'tasks')->assertJsonPath('calendar.total', 1)
        ->assertJsonPath('calendar.days.0.date', '2026-12-02')
        ->assertJsonPath('calendar.days.0.tasks.0.id', $nextMonth->id);

    $this->getJson('/api/v1/board?month=2026-11&assignee=me&project_id='.$other->id)->assertOk()
        ->assertJsonCount(0, 'tasks')->assertJsonPath('calendar.total', 0)->assertJsonCount(0, 'calendar.days')
        ->assertJsonCount(2, 'projects')->assertJsonPath('projects.0.tasks_count', 5);
});

it('rejects invalid assignment filters', function (string $query): void {
    $this->getJson('/api/v1/board?'.$query)->assertUnprocessable()->assertJsonValidationErrors('assignee');
})->with(['assignee=human', 'assignee=', 'assignee[]=agent']);

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
    $project = $this->postJson('/api/v1/projects', ['name' => 'Home', 'color' => '#ab1234'])->assertCreated()->json('data.id');
    $this->patchJson('/api/v1/projects/'.$project, ['name' => 'New home', 'color' => '#abc123'])->assertOk();
    $milestone = $this->postJson('/api/v1/milestones', ['name' => 'Preparation', 'due_date' => '2026-12-01', 'project_id' => $project])->assertCreated()->json('data.id');

    $this->deleteJson('/api/v1/projects/'.$project)->assertConflict();
    $this->assertDatabaseHas('projects', ['id' => $project, 'name' => 'New home', 'color' => '#abc123']);
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

it('ignores unexpected attributes when creating a task', function (): void {
    $project = Project::factory()->create();

    $this->postJson('/api/v1/tasks', ['name' => '<script>alert(1)</script>', 'project_id' => $project->id, 'id' => 9000, 'created_at' => '2000-01-01'])
        ->assertCreated()->assertJsonPath('data.name', '<script>alert(1)</script>');
    $this->assertDatabaseMissing('tasks', ['id' => 9000]);
});
