<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsOpenWorld(false)]
abstract class SoloTasksTool extends Tool
{
    public function toArray(): array
    {
        $tool = parent::toArray();
        $tool['inputSchema']['additionalProperties'] = false;

        return $tool;
    }

    protected function validateArguments(Request $request, array $rules = []): array
    {
        $allowed = array_filter(array_keys($rules), fn (string $key): bool => ! str_contains($key, '.'));
        $unknown = array_diff(array_keys($request->all()), $allowed);

        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'This argument is not allowed.'));
        }

        return $request->validate($rules);
    }

    protected function requireChanges(array $changes): void
    {
        if ($changes === []) {
            throw ValidationException::withMessages(['changes' => 'Provide at least one field to update.']);
        }
    }

    protected function paginationSchema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->min(1)->description('Page number, starting at 1.'),
            'per_page' => $schema->integer()->min(1)->max(100)->description('Results per page; default 50, maximum 100.'),
        ];
    }

    protected function paginationRules(): array
    {
        return [
            'page' => ['sometimes', 'required', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function dateRangeSchema(JsonSchema $schema): array
    {
        return [
            'due_from' => $schema->string()->description('Inclusive earliest due date, YYYY-MM-DD.'),
            'due_to' => $schema->string()->description('Inclusive latest due date, YYYY-MM-DD.'),
        ];
    }

    protected function dateRangeRules(Request $request): array
    {
        return [
            'due_from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'due_to' => ['sometimes', 'required', 'date_format:Y-m-d', ...($request->has('due_from') ? ['after_or_equal:due_from'] : [])],
        ];
    }

    protected function applyDateRange(Builder $query, array $filters): void
    {
        if (isset($filters['due_from'])) {
            $query->where('due_date', '>=', $filters['due_from']);
        }
        if (isset($filters['due_to'])) {
            $query->where('due_date', '<=', $filters['due_to']);
        }
    }

    protected function paginated(Builder $query, array $filters, callable $serialize): array
    {
        $page = $query->paginate($filters['per_page'] ?? 50, ['*'], 'page', $filters['page'] ?? 1);

        return [
            'data' => $page->getCollection()->map($serialize)->values()->all(),
            'pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
            ],
        ];
    }
}
