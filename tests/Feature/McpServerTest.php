<?php

use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\PersonalAccess;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Enums\MetaKey;
use Laravel\Mcp\Enums\RequestHeader;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    config(['solotasks.mcp_token' => 'mcp-test-token', 'app.timezone' => 'Europe/Rome']);
});

function mcpRpc(string $method, array $params = [], ?string $token = 'mcp-test-token', bool $modern = false): TestResponse
{
    $headers = ['MCP-Protocol-Version' => '2025-11-25'];
    if ($token !== null) {
        $headers['Authorization'] = 'Bearer '.$token;
    }
    if ($modern) {
        $params['_meta'] = [
            MetaKey::PROTOCOL_VERSION->value => '2026-07-28',
            MetaKey::CLIENT_CAPABILITIES->value => (object) [],
        ];
        $headers[RequestHeader::PROTOCOL_VERSION->value] = '2026-07-28';
        $headers[RequestHeader::METHOD->value] = $method;
        if (isset($params['name'])) {
            $headers[RequestHeader::NAME->value] = $params['name'];
        }
    }

    return test()->postJson('/mcp/solotasks', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params,
    ], $headers);
}

function mcpCall(string $name, array $arguments = []): TestResponse
{
    return mcpRpc('tools/call', ['name' => $name, 'arguments' => (object) $arguments]);
}

it('requires a valid bearer token even with a personal browser session', function (): void {
    config(['solotasks.username' => 'owner', 'solotasks.password_hash' => Hash::make('personal-password')]);
    $this->withSession(['personal_access' => app(PersonalAccess::class)->fingerprint()]);

    mcpRpc('tools/list', token: null)->assertUnauthorized()->assertHeader('WWW-Authenticate');
    mcpRpc('tools/list', token: 'wrong-token')->assertUnauthorized();
    $this->postJson('/mcp/solotasks', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], [
        'Authorization' => 'Basic '.base64_encode('owner:personal-password'),
    ])->assertUnauthorized();
    $this->postJson('/mcp/solotasks', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], [
        'Authorization' => 'Basic Bearer mcp-test-token',
    ])->assertUnauthorized();
    $this->getJson('/mcp/solotasks')->assertUnauthorized();
    $this->deleteJson('/mcp/solotasks')->assertUnauthorized();
    mcpRpc('tools/list')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
});

it('fails closed for missing invalid or nonmatching MCP configuration', function (mixed $token): void {
    config(['solotasks.mcp_token' => $token]);

    mcpRpc('tools/list')->assertUnauthorized();
})->with([
    'missing' => [null],
    'empty' => [''],
    'number' => [123],
    'array' => [['invalid-token']],
    'different token' => ['different-token'],
]);

it('revokes the old token immediately when its configured value changes', function (): void {
    mcpRpc('tools/list')->assertOk();
    config(['solotasks.mcp_token' => 'replacement-token']);

    mcpRpc('tools/list')->assertUnauthorized();
    mcpRpc('tools/list', token: 'replacement-token')->assertOk();
});

it('does not allow the MCP token to authenticate browser data endpoints', function (string $method, string $path): void {
    $this->json($method, '/api/v1/'.$path, ['name' => 'Unauthorized'], ['Authorization' => 'Bearer mcp-test-token'])
        ->assertUnauthorized();
    $this->assertDatabaseCount('projects', 0);
    $this->assertDatabaseCount('tasks', 0);
    $this->assertDatabaseCount('milestones', 0);
})->with([
    ['GET', 'board'], ['POST', 'projects'], ['PATCH', 'projects/1'], ['DELETE', 'projects/1'],
    ['POST', 'tasks'], ['PATCH', 'tasks/1'], ['DELETE', 'tasks/1'],
    ['POST', 'milestones'], ['PATCH', 'milestones/1'], ['DELETE', 'milestones/1'],
]);

it('initializes and discovers only the nine permitted tools over HTTP', function (): void {
    mcpRpc('initialize', [
        'protocolVersion' => '2025-11-25', 'capabilities' => (object) [],
        'clientInfo' => ['name' => 'SoloTasks test client', 'version' => '1.0.0'],
    ])->assertOk()->assertJsonPath('result.serverInfo.name', 'SoloTasks')
        ->assertJsonPath('result.protocolVersion', '2025-11-25');
    mcpRpc('server/discover', modern: true)->assertOk()
        ->assertJsonPath('result.supportedVersions.0', '2026-07-28');

    $tools = mcpRpc('tools/list')->assertOk()->assertJsonCount(9, 'result.tools')->json('result.tools');
    expect(array_column($tools, 'name'))->toEqualCanonicalizing([
        'get_context', 'list_projects', 'list_tasks', 'get_task', 'list_milestones',
        'create_task', 'update_task', 'create_milestone', 'update_milestone',
    ]);
    foreach ($tools as $tool) {
        expect($tool['inputSchema']['additionalProperties'])->toBeFalse();
    }
    expect(collect($tools)->firstWhere('name', 'list_tasks')['annotations']['readOnlyHint'])->toBeTrue();
    mcpRpc('tools/call', ['name' => 'get_context', 'arguments' => (object) []], modern: true)
        ->assertOk()->assertJsonPath('result.isError', false);
});

it('reports the current app date and Monday to Sunday week across a UTC day boundary', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 23:30:00 UTC'));

    mcpCall('get_context')->assertOk()->assertJsonPath('result.structuredContent.current_date', '2026-11-05')
        ->assertJsonPath('result.structuredContent.timezone', 'Europe/Rome')
        ->assertJsonPath('result.structuredContent.week_start', '2026-11-02')
        ->assertJsonPath('result.structuredContent.week_end', '2026-11-08')
        ->assertJsonPath('result.structuredContent.assignees.me', 'The human owner')
        ->assertJsonPath('result.structuredContent.assignees.agent', 'The AI agent')
        ->assertJsonPath('result.structuredContent.permissions.projects', ['read']);
});

it('combines task filters with AND and accepts multiple priorities and statuses', function (): void {
    $project = Project::factory()->create();
    $milestone = Milestone::factory()->for($project)->create();
    $base = [
        'project_id' => $project->id, 'milestone_id' => $milestone->id, 'assignee' => 'agent',
        'priority' => 'high', 'status' => 'todo', 'due_date' => '2026-11-02', 'name' => 'API work',
    ];
    $first = Task::factory()->create($base);
    $last = Task::factory()->create(array_replace($base, [
        'priority' => 'medium', 'status' => 'in_progress', 'due_date' => '2026-11-08',
        'name' => 'Implementation', 'description' => 'Update the API.',
    ]));
    foreach ([
        ['assignee' => 'me'], ['priority' => 'low'], ['status' => 'done'], ['due_date' => '2026-11-01'],
        ['due_date' => '2026-11-09'], ['due_date' => null], ['milestone_id' => null],
        ['name' => 'Unrelated', 'description' => 'No match'],
    ] as $difference) {
        Task::factory()->create(array_replace($base, $difference));
    }
    Task::factory()->create(['description' => 'API', 'assignee' => 'agent']);

    $result = mcpCall('list_tasks', [
        'project_id' => $project->id, 'milestone_id' => $milestone->id, 'assignee' => 'agent',
        'priorities' => ['high', 'medium'], 'statuses' => ['todo', 'in_progress'], 'search' => 'API',
        'due_from' => '2026-11-02', 'due_to' => '2026-11-08',
    ])->assertOk()->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.structuredContent.pagination.total', 2)
        ->assertJsonPath('result.structuredContent.data.0.project.name', $project->name)
        ->assertJsonPath('result.structuredContent.data.0.milestone.name', $milestone->name);
    expect(array_column($result->json('result.structuredContent.data'), 'id'))->toBe([$first->id, $last->id]);
});

it('finds today tasks for either assignee without mixing people or projects', function (string $assignee): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00 Europe/Rome'));
    $project = Project::factory()->create();
    $task = Task::factory()->for($project)->create(['assignee' => $assignee, 'due_date' => '2026-11-05']);
    Task::factory()->for($project)->create(['assignee' => $assignee === 'me' ? 'agent' : 'me', 'due_date' => '2026-11-05']);
    Task::factory()->for($project)->create(['assignee' => $assignee, 'due_date' => '2026-11-04']);
    Task::factory()->create(['assignee' => $assignee, 'due_date' => '2026-11-05']);
    $context = mcpCall('get_context')->assertOk()->json('result.structuredContent');

    mcpCall('list_tasks', [
        'project_id' => $project->id, 'assignee' => $assignee,
        'due_from' => $context['current_date'], 'due_to' => $context['current_date'],
    ])->assertOk()->assertJsonPath('result.structuredContent.pagination.total', 1)
        ->assertJsonPath('result.structuredContent.data.0.id', $task->id);
})->with(['me', 'agent']);

it('treats search metacharacters as literal text', function (string $search): void {
    $match = Task::factory()->create(['name' => 'Review '.$search, 'description' => null]);
    Task::factory()->create(['name' => 'Review everything', 'description' => 'Other work']);

    mcpCall('list_tasks', ['search' => $search])->assertOk()
        ->assertJsonPath('result.structuredContent.pagination.total', 1)
        ->assertJsonPath('result.structuredContent.data.0.id', $match->id);
})->with(['100%_!', "' OR 1=1 --"]);

it('defines overdue by the app date and excludes completed and undated tasks', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 23:30:00 UTC'));
    $overdue = Task::factory()->create(['due_date' => '2026-11-04', 'status' => 'in_review']);
    Task::factory()->create(['due_date' => '2026-11-04', 'status' => 'done']);
    Task::factory()->create(['due_date' => '2026-11-05']);
    Task::factory()->create(['due_date' => '2026-11-06']);
    $undated = Task::factory()->create(['due_date' => null]);

    mcpCall('list_tasks', ['overdue' => true])->assertOk()
        ->assertJsonPath('result.structuredContent.pagination.total', 1)
        ->assertJsonPath('result.structuredContent.data.0.id', $overdue->id);
    mcpCall('list_tasks', ['without_due_date' => true])->assertOk()
        ->assertJsonPath('result.structuredContent.pagination.total', 1)
        ->assertJsonPath('result.structuredContent.data.0.id', $undated->id);
    mcpCall('list_tasks', ['without_due_date' => true, 'due_from' => '2026-11-01'])->assertOk()
        ->assertJsonPath('result.structuredContent.pagination.total', 0);
    mcpCall('list_tasks', ['due_to' => '2026-11-04'])->assertOk()
        ->assertJsonPath('result.structuredContent.pagination.total', 2);
    mcpCall('list_tasks', ['overdue' => false, 'without_due_date' => false])->assertOk()
        ->assertJsonPath('result.structuredContent.pagination.total', 5);
});

it('paginates tasks with stable ordering and places undated tasks last', function (): void {
    $project = Project::factory()->create();
    $undated = Task::factory()->for($project)->create(['due_date' => null]);
    $dated = Task::factory()->count(51)->for($project)->create(['due_date' => '2026-11-05']);

    $first = mcpCall('list_tasks')->assertOk()
        ->assertJsonCount(50, 'result.structuredContent.data')
        ->assertJsonPath('result.structuredContent.pagination', [
            'page' => 1, 'per_page' => 50, 'total' => 52, 'last_page' => 2, 'next_page' => 2,
        ])->json('result.structuredContent.data');
    $second = mcpCall('list_tasks', ['page' => 2])->assertOk()
        ->assertJsonPath('result.structuredContent.pagination.next_page', null)->json('result.structuredContent.data');

    expect(array_column([...$first, ...$second], 'id'))->toBe([...$dated->modelKeys(), $undated->id]);
    mcpCall('list_tasks', ['per_page' => 100])->assertOk()->assertJsonCount(52, 'result.structuredContent.data');
});

it('paginates projects and filters milestone dates independently of task dates', function (): void {
    $project = Project::factory()->create(['name' => 'Alpha']);
    Project::factory()->create(['name' => 'Beta']);
    $milestone = Milestone::factory()->for($project)->create(['due_date' => '2026-11-05']);
    $second = Milestone::factory()->for($project)->create(['due_date' => '2026-11-05']);
    Milestone::factory()->for($project)->create(['due_date' => '2026-11-06']);
    Milestone::factory()->create(['due_date' => '2026-11-05']);
    Task::factory()->for($project)->create(['milestone_id' => $milestone->id, 'due_date' => '2026-12-01']);

    mcpCall('list_projects', ['per_page' => 1])->assertOk()
        ->assertJsonPath('result.structuredContent.data.0.id', $project->id)
        ->assertJsonPath('result.structuredContent.data.0.tasks_count', 1)
        ->assertJsonPath('result.structuredContent.pagination.next_page', 2);
    mcpCall('list_projects', ['per_page' => 1, 'page' => 2])->assertOk()
        ->assertJsonPath('result.structuredContent.data.0.name', 'Beta');
    $filters = ['project_id' => $project->id, 'due_from' => '2026-11-05', 'due_to' => '2026-11-05', 'per_page' => 1];
    mcpCall('list_milestones', $filters)->assertOk()
        ->assertJsonPath('result.structuredContent.data.0.id', $milestone->id)
        ->assertJsonPath('result.structuredContent.data.0.project.name', 'Alpha')
        ->assertJsonPath('result.structuredContent.pagination.total', 2)
        ->assertJsonPath('result.structuredContent.pagination.next_page', 2);
    mcpCall('list_milestones', $filters + ['page' => 2])->assertOk()
        ->assertJsonPath('result.structuredContent.data.0.id', $second->id);
});

it('creates tasks with the same defaults as the UI and reads their details', function (): void {
    $project = Project::factory()->create();
    $id = mcpCall('create_task', ['name' => 'New task', 'project_id' => $project->id])->assertOk()
        ->assertJsonPath('result.isError', false)->assertJsonPath('result.structuredContent.data.assignee', 'me')
        ->assertJsonPath('result.structuredContent.data.priority', 'medium')
        ->assertJsonPath('result.structuredContent.data.status', 'backlog')
        ->assertJsonPath('result.structuredContent.data.due_date', null)
        ->assertJsonPath('result.structuredContent.data.milestone_id', null)->json('result.structuredContent.data.id');
    mcpCall('get_task', ['task_id' => $id])->assertOk()
        ->assertJsonPath('result.structuredContent.data.project.name', $project->name);
    $this->assertDatabaseHas('tasks', ['id' => $id, 'assignee' => 'me', 'status' => 'backlog']);
});

it('creates assigned tasks and partially updates allowed fields without changing their associations', function (): void {
    $milestone = Milestone::factory()->create();
    $id = mcpCall('create_task', [
        'name' => 'Implement MCP', 'description' => 'Read the specification', 'project_id' => $milestone->project_id,
        'milestone_id' => $milestone->id, 'assignee' => 'agent', 'priority' => 'high',
        'status' => 'todo', 'due_date' => '2026-11-05',
    ])->assertOk()->assertJsonPath('result.isError', false)->json('result.structuredContent.data.id');
    mcpCall('update_task', ['task_id' => $id, 'status' => 'in_progress', 'due_date' => '2026-11-06'])
        ->assertOk()->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.structuredContent.data.assignee', 'agent')
        ->assertJsonPath('result.structuredContent.data.due_date', '2026-11-06');
    mcpCall('update_task', [
        'task_id' => $id, 'name' => 'Review MCP', 'assignee' => 'me', 'priority' => 'low', 'description' => null, 'due_date' => null,
    ])->assertOk()->assertJsonPath('result.structuredContent.data.name', 'Review MCP')
        ->assertJsonPath('result.structuredContent.data.assignee', 'me')
        ->assertJsonPath('result.structuredContent.data.priority', 'low')
        ->assertJsonPath('result.structuredContent.data.description', null)
        ->assertJsonPath('result.structuredContent.data.due_date', null)
        ->assertJsonPath('result.structuredContent.data.status', 'in_progress')
        ->assertJsonPath('result.structuredContent.data.project_id', $milestone->project_id)
        ->assertJsonPath('result.structuredContent.data.milestone_id', $milestone->id);
    mcpCall('update_task', ['task_id' => $id, 'status' => 'done'])->assertOk()
        ->assertJsonPath('result.structuredContent.data.status', 'done');
    $this->assertDatabaseHas('tasks', ['id' => $id, 'status' => 'done', 'description' => null, 'due_date' => null]);
});

it('rejects forbidden task fields atomically', function (string $field, mixed $value): void {
    $task = Task::factory()->create(['name' => 'Keep this task', 'status' => 'todo']);

    mcpCall('update_task', ['task_id' => $task->id, 'status' => 'done', $field => $value])->assertOk()
        ->assertJsonPath('result.isError', true);
    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'name' => 'Keep this task', 'status' => 'todo', 'project_id' => $task->project_id]);
})->with([
    ['project_id', 999], ['milestone_id', null], ['milestone_id', 999], ['id', 999],
    ['created_at', '2000-01-01'], ['delete', true], ['unexpected', 'value'],
]);

it('validates task creation and cross-project milestone associations before writing', function (): void {
    $project = Project::factory()->create();
    $foreignMilestone = Milestone::factory()->create();

    mcpCall('create_task', ['name' => 'Bad association', 'project_id' => $project->id, 'milestone_id' => $foreignMilestone->id])
        ->assertOk()->assertJsonPath('result.isError', true);
    mcpCall('create_task', ['name' => 'Unexpected field', 'project_id' => $project->id, 'id' => 500])
        ->assertOk()->assertJsonPath('result.isError', true);
    mcpCall('create_task')->assertOk()->assertJsonPath('result.isError', true);
    $this->assertDatabaseCount('tasks', 0);
});

it('creates and partially updates milestones while preserving projects and task dates', function (): void {
    $project = Project::factory()->create();
    $id = mcpCall('create_milestone', ['name' => 'MCP release', 'due_date' => '2026-11-10', 'project_id' => $project->id])
        ->assertOk()->assertJsonPath('result.isError', false)->json('result.structuredContent.data.id');
    $task = Task::factory()->for($project)->create(['milestone_id' => $id, 'due_date' => '2026-11-08']);

    mcpCall('update_milestone', ['milestone_id' => $id, 'name' => 'Release candidate'])->assertOk()
        ->assertJsonPath('result.structuredContent.data.due_date', '2026-11-10');
    mcpCall('update_milestone', ['milestone_id' => $id, 'due_date' => '2026-11-12'])->assertOk()
        ->assertJsonPath('result.structuredContent.data.name', 'Release candidate')
        ->assertJsonPath('result.structuredContent.data.project_id', $project->id);
    mcpCall('update_milestone', ['milestone_id' => $id, 'project_id' => $project->id, 'name' => 'Forbidden'])
        ->assertOk()->assertJsonPath('result.isError', true);
    $this->assertDatabaseHas('milestones', ['id' => $id, 'project_id' => $project->id, 'name' => 'Release candidate', 'due_date' => '2026-11-12']);
    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'milestone_id' => $id, 'due_date' => '2026-11-08']);
});

it('rejects invalid filters and values as MCP errors without changing existing tasks', function (string $tool, array $arguments): void {
    $task = Task::factory()->create(['status' => 'todo', 'due_date' => '2026-11-05']);
    $milestone = Milestone::factory()->create();
    if ($tool === 'update_task') {
        $arguments['task_id'] = $task->id;
    }
    if ($tool === 'update_milestone') {
        $arguments['milestone_id'] = $milestone->id;
    }

    mcpCall($tool, $arguments)->assertOk()->assertJsonPath('result.isError', true);
    $this->assertDatabaseCount('tasks', 1);
    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'todo', 'due_date' => '2026-11-05']);
})->with([
    ['list_tasks', ['due_from' => '2026-02-30']],
    ['list_tasks', ['due_from' => '2026-11-08', 'due_to' => '2026-11-02']],
    ['list_tasks', ['priorities' => ['urgent']]],
    ['list_tasks', ['priorities' => []]],
    ['list_tasks', ['statuses' => ['waiting']]],
    ['list_tasks', ['assignee' => 'someone']],
    ['list_tasks', ['project_id' => 99999]],
    ['list_tasks', ['per_page' => 101]],
    ['list_tasks', ['page' => 0]],
    ['list_tasks', ['overdue' => 'yes']],
    ['list_tasks', ['unknown_filter' => true]],
    ['list_projects', ['per_page' => 0]],
    ['list_milestones', ['due_to' => 'not-a-date']],
    ['get_task', ['task_id' => 99999]],
    ['get_context', ['unknown' => true]],
    ['update_task', []],
    ['update_task', ['assignee' => null]],
    ['update_task', ['status' => 'waiting']],
    ['update_task', ['name' => '']],
    ['update_task', ['due_date' => '2026-11-01T23:00:00Z']],
    ['update_milestone', []],
    ['update_milestone', ['due_date' => null]],
    ['create_milestone', ['name' => 'Missing project', 'due_date' => '2026-11-10']],
]);

it('does not expose project writes or task and milestone deletion', function (string $tool): void {
    $milestone = Milestone::factory()->create();
    $task = Task::factory()->create(['project_id' => $milestone->project_id, 'milestone_id' => $milestone->id]);

    mcpCall($tool, ['project_id' => $milestone->project_id, 'task_id' => $task->id, 'milestone_id' => $milestone->id])
        ->assertStatus(400)->assertJsonPath('error.code', -32602);
    $this->assertModelExists($task);
    $this->assertModelExists($milestone);
    $this->assertDatabaseCount('projects', 1);
    $this->assertDatabaseCount('tasks', 1);
    $this->assertDatabaseCount('milestones', 1);
})->with(['create_project', 'update_project', 'delete_project', 'delete_task', 'delete_milestone']);

it('generates independent random bearer tokens directly in env format', function (): void {
    $tokens = [];
    for ($attempt = 0; $attempt < 2; $attempt++) {
        expect(Artisan::call('solotasks:mcp-token'))->toBe(0);
        $output = Artisan::output();
        expect(preg_match('/^SOLOTASKS_MCP_TOKEN=([a-f0-9]{64})$/m', $output, $token))->toBe(1);
        $tokens[] = $token[1];
    }
    expect($tokens[0])->not->toBe($tokens[1]);
    expect(config('solotasks.mcp_token'))->toBe('mcp-test-token');
});
