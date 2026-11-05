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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_task')]
#[Description('Read all details of an existing task, including its project and optional milestone.')]
#[IsReadOnly]
class GetTask extends SoloTasksTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['task_id' => $schema->integer()->min(1)->required()];
    }

    public function handle(Request $request): ResponseFactory
    {
        $data = $this->validateArguments($request, ['task_id' => ['required', 'integer', 'min:1', 'exists:tasks,id']]);
        $task = Task::query()->with(['project', 'milestone'])->findOrFail($data['task_id']);

        return Response::structured(['data' => (new TaskResource($task))->resolve()]);
    }
}
