<?php

namespace App\Mcp\Tools;

use App\Models\Milestone;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_milestones')]
#[Description('List milestones and their projects, optionally filtered by project and inclusive due-date range. Milestone dates do not change task dates. Follow pagination.next_page for all results.')]
#[IsReadOnly]
class ListMilestones extends SoloTasksTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->min(1),
            ...$this->dateRangeSchema($schema),
            ...$this->paginationSchema($schema),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $filters = $this->validateArguments($request, [
            'project_id' => ['sometimes', 'required', 'integer', 'min:1', 'exists:projects,id'],
            ...$this->dateRangeRules($request),
            ...$this->paginationRules(),
        ]);
        $query = Milestone::query()->with('project')->orderBy('due_date')->orderBy('id');
        if (isset($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }
        $this->applyDateRange($query, $filters);

        return Response::structured($this->paginated($query, $filters, fn (Milestone $milestone): array => [
            'id' => $milestone->id,
            'name' => $milestone->name,
            'due_date' => $milestone->due_date->toDateString(),
            'project_id' => $milestone->project_id,
            'project' => $milestone->project->only(['id', 'name', 'color']),
        ]));
    }
}
