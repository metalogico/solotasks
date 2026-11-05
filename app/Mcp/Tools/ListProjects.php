<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_projects')]
#[Description('List existing projects, their IDs, names, colors and task counts. Projects are read-only. Follow pagination.next_page for all projects.')]
#[IsReadOnly]
class ListProjects extends SoloTasksTool
{
    public function schema(JsonSchema $schema): array
    {
        return $this->paginationSchema($schema);
    }

    public function handle(Request $request): ResponseFactory
    {
        $filters = $this->validateArguments($request, $this->paginationRules());

        return Response::structured($this->paginated(
            Project::query()->withCount('tasks')->orderBy('name')->orderBy('id'),
            $filters,
            fn (Project $project): array => $project->only(['id', 'name', 'color', 'tasks_count']),
        ));
    }
}
