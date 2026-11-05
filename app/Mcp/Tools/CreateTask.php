<?php

namespace App\Mcp\Tools;

use App\Http\Resources\TaskResource;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('create_task')]
#[Description('Create a task in an existing project and optional milestone. Defaults: assignee me, priority medium, status backlog. Creating an agent-assigned task does not execute it.')]
#[IsDestructive(false)]
class CreateTask extends TaskTool
{
    public function schema(JsonSchema $schema): array
    {
        return $this->taskSchema($schema, creating: true);
    }

    public function handle(Request $request): ResponseFactory
    {
        $data = $this->validateArguments($request, $this->taskRules(creating: true, projectId: $request->get('project_id')));
        $task = Task::create($data)->refresh()->load(['project', 'milestone']);

        return Response::structured(['data' => (new TaskResource($task))->resolve()]);
    }
}
