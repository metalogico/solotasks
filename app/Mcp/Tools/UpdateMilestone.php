<?php

namespace App\Mcp\Tools;

use App\Models\Milestone;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_milestone')]
#[Description('Partially update a milestone name or required due date. Cannot change its project, delete it, or change its tasks or their deadlines.')]
#[IsIdempotent]
class UpdateMilestone extends SoloTasksTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'milestone_id' => $schema->integer()->min(1)->required(),
            'name' => $schema->string()->min(1)->max(150),
            'due_date' => $schema->string()->description('Calendar date YYYY-MM-DD; cannot be null.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $data = $this->validateArguments($request, [
            'milestone_id' => ['required', 'integer', 'min:1', 'exists:milestones,id'],
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'due_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
        ]);
        $milestone = Milestone::findOrFail($data['milestone_id']);
        unset($data['milestone_id']);
        $this->requireChanges($data);
        $milestone->update($data);

        return Response::structured(['data' => $milestone->only(['id', 'name', 'project_id']) + [
            'due_date' => $milestone->due_date->toDateString(),
        ]]);
    }
}
