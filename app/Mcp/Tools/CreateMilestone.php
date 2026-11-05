<?php

namespace App\Mcp\Tools;

use App\Models\Milestone;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('create_milestone')]
#[Description('Create a milestone with a name and required due date in an existing project. Its date does not set or change task deadlines.')]
#[IsDestructive(false)]
class CreateMilestone extends SoloTasksTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->min(1)->required(),
            'name' => $schema->string()->min(1)->max(150)->required(),
            'due_date' => $schema->string()->description('Required calendar date YYYY-MM-DD.')->required(),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $data = $this->validateArguments($request, [
            'project_id' => ['required', 'integer', 'min:1', 'exists:projects,id'],
            'name' => ['required', 'string', 'max:150'],
            'due_date' => ['required', 'date_format:Y-m-d'],
        ]);
        $milestone = Milestone::create($data);

        return Response::structured(['data' => $milestone->only(['id', 'name', 'project_id']) + [
            'due_date' => $milestone->due_date->toDateString(),
        ]]);
    }
}
