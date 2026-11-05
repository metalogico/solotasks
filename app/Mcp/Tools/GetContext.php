<?php

namespace App\Mcp\Tools;

use App\Models\Task;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_context')]
#[Description('Get the current local date, timezone, week boundaries, task values and MCP permissions before interpreting relative dates or assignees.')]
#[IsReadOnly]
class GetContext extends SoloTasksTool
{
    public function handle(Request $request): ResponseFactory
    {
        $this->validateArguments($request);
        $today = CarbonImmutable::today(config('app.timezone'));

        return Response::structured([
            'current_date' => $today->toDateString(),
            'timezone' => config('app.timezone'),
            'week_start' => $today->startOfWeek(CarbonInterface::MONDAY)->toDateString(),
            'week_end' => $today->endOfWeek(CarbonInterface::SUNDAY)->toDateString(),
            'assignees' => ['me' => 'The human owner', 'agent' => 'The AI agent'],
            'statuses' => Task::STATUSES,
            'priorities' => Task::PRIORITIES,
            'permissions' => [
                'projects' => ['read'],
                'tasks' => ['read', 'create', 'update'],
                'milestones' => ['read', 'create', 'update'],
                'task_update_fields' => ['name', 'description', 'priority', 'status', 'assignee', 'due_date'],
                'milestone_update_fields' => ['name', 'due_date'],
            ],
        ]);
    }
}
