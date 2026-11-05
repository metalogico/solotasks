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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_task')]
#[Description('Partially update an existing task: name, description, priority, status, assignee or due_date. Set description or due_date to null to clear them. Cannot change project or milestone, or delete a task. Works for both human and agent tasks.')]
#[IsIdempotent]
class UpdateTask extends TaskTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['task_id' => $schema->integer()->min(1)->required(), ...$this->taskSchema($schema, creating: false)];
    }

    public function handle(Request $request): ResponseFactory
    {
        $data = $this->validateArguments($request, [
            'task_id' => ['required', 'integer', 'min:1', 'exists:tasks,id'],
            ...$this->taskRules(creating: false),
        ]);
        $task = Task::findOrFail($data['task_id']);
        unset($data['task_id']);
        $this->requireChanges($data);
        $task->update($data);

        return Response::structured(['data' => (new TaskResource($task->refresh()->load(['project', 'milestone'])))->resolve()]);
    }
}
