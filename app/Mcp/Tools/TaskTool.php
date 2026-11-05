<?php

namespace App\Mcp\Tools;

use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;

abstract class TaskTool extends SoloTasksTool
{
    protected function taskSchema(JsonSchema $schema, bool $creating): array
    {
        $fields = [
            'name' => $schema->string()->min(1)->max(200)->required($creating),
            'description' => $schema->string()->max(20000)->nullable()->description('Set null to clear the description.'),
            'priority' => $schema->string()->enum(Task::PRIORITIES)->description('Default for new tasks: medium.'),
            'status' => $schema->string()->enum(Task::STATUSES)->description('Default for new tasks: backlog.'),
            'assignee' => $schema->string()->enum(Task::ASSIGNEES)->description('me = human, agent = AI. Default for new tasks: me.'),
            'due_date' => $schema->string()->nullable()->description('Calendar date YYYY-MM-DD; set null to remove the deadline.'),
        ];

        if ($creating) {
            $fields['project_id'] = $schema->integer()->min(1)->required()->description('An existing project. Cannot change after creation through MCP.');
            $fields['milestone_id'] = $schema->integer()->min(1)->nullable()->description('An existing milestone in the same project. Cannot change after creation through MCP.');
        }

        return $fields;
    }

    protected function taskRules(bool $creating, mixed $projectId = null): array
    {
        $rules = [
            'name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'priority' => ['sometimes', 'required', Rule::in(Task::PRIORITIES)],
            'status' => ['sometimes', 'required', Rule::in(Task::STATUSES)],
            'assignee' => ['sometimes', 'required', Rule::in(Task::ASSIGNEES)],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];

        if ($creating) {
            $rules['project_id'] = ['required', 'integer', 'min:1', 'exists:projects,id'];
            $rules['milestone_id'] = ['sometimes', 'nullable', 'integer', 'min:1', Rule::exists('milestones', 'id')->where('project_id', $projectId)];
        }

        return $rules;
    }
}
