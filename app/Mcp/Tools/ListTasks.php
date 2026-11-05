<?php

namespace App\Mcp\Tools;

use App\Http\Resources\TaskResource;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_tasks')]
#[Description('Search tasks with combinable project, milestone, assignee, priorities, statuses, text and inclusive due-date filters. Includes done by default. overdue=true excludes done; without_due_date=true selects undated tasks. Results are ordered by due date, then ID, undated last. Follow pagination.next_page for all results.')]
#[IsReadOnly]
class ListTasks extends SoloTasksTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->min(1)->description('Only tasks in this existing project.'),
            'milestone_id' => $schema->integer()->min(1)->description('Only tasks in this existing milestone.'),
            'assignee' => $schema->string()->enum(Task::ASSIGNEES)->description('me = human owner; agent = AI agent.'),
            'priorities' => $schema->array()->items($schema->string()->enum(Task::PRIORITIES))->min(1)->max(3),
            'statuses' => $schema->array()->items($schema->string()->enum(Task::STATUSES))->min(1)->max(5),
            'search' => $schema->string()->min(1)->max(200)->description('Literal substring in the task name or description.'),
            ...$this->dateRangeSchema($schema),
            'overdue' => $schema->boolean()->description('When true, only unfinished tasks due before the current local date.'),
            'without_due_date' => $schema->boolean()->description('When true, only tasks without a due date. Date filters still combine with AND.'),
            ...$this->paginationSchema($schema),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $filters = $this->validateArguments($request, [
            'project_id' => ['sometimes', 'required', 'integer', 'min:1', Rule::exists('projects', 'id')],
            'milestone_id' => ['sometimes', 'required', 'integer', 'min:1', Rule::exists('milestones', 'id')],
            'assignee' => ['sometimes', 'required', Rule::in(Task::ASSIGNEES)],
            'priorities' => ['sometimes', 'required', 'array', 'list', 'min:1', 'max:3'],
            'priorities.*' => ['required', 'string', 'distinct', Rule::in(Task::PRIORITIES)],
            'statuses' => ['sometimes', 'required', 'array', 'list', 'min:1', 'max:5'],
            'statuses.*' => ['required', 'string', 'distinct', Rule::in(Task::STATUSES)],
            'search' => ['sometimes', 'required', 'string', 'max:200'],
            ...$this->dateRangeRules($request),
            'overdue' => ['sometimes', 'required', 'boolean'],
            'without_due_date' => ['sometimes', 'required', 'boolean'],
            ...$this->paginationRules(),
        ]);

        $query = Task::query()->with(['project', 'milestone']);
        foreach (['project_id', 'milestone_id', 'assignee'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        foreach (['priorities' => 'priority', 'statuses' => 'status'] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->whereIn($column, $filters[$filter]);
            }
        }
        if (isset($filters['search'])) {
            $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']).'%';
            $query->where(fn ($query) => $query
                ->whereRaw("name LIKE ? ESCAPE '!'", [$search])
                ->orWhereRaw("description LIKE ? ESCAPE '!'", [$search]));
        }
        $this->applyDateRange($query, $filters);
        if ($filters['overdue'] ?? false) {
            $query->where('due_date', '<', CarbonImmutable::today(config('app.timezone'))->toDateString())
                ->where('status', '!=', 'done');
        }
        if ($filters['without_due_date'] ?? false) {
            $query->whereNull('due_date');
        }

        return Response::structured($this->paginated(
            $query->orderByRaw('due_date IS NULL')->orderBy('due_date')->orderBy('id'),
            $filters,
            fn (Task $task): array => (new TaskResource($task))->resolve(),
        ));
    }
}
