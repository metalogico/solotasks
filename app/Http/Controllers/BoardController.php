<?php

namespace App\Http\Controllers;

use App\Http\Resources\TaskResource;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BoardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')],
            'month' => ['sometimes', 'date_format:Y-m'],
            'include_done' => ['sometimes', 'boolean'],
        ]);
        $month = CarbonImmutable::createFromFormat('!Y-m', $filters['month'] ?? now()->format('Y-m'));
        $monthKey = $month->format('Y-m');
        $includeDone = $request->boolean('include_done', config('solotasks.calendar_include_done'));

        $tasks = Task::query()
            ->with(['project', 'milestone'])
            ->when($filters['project_id'] ?? null, fn ($query, $projectId) => $query->where('project_id', $projectId))
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        // Calendar filters do not remove undated or completed tasks from the board.
        $scheduledTasks = $tasks->filter(fn (Task $task) => $task->due_date
            && $task->due_date->format('Y-m') === $monthKey
            && ($includeDone || $task->status !== 'done'));
        $calendarDays = $scheduledTasks
            ->groupBy(fn (Task $task) => $task->due_date->toDateString())
            ->map(fn ($dayTasks, $date) => [
                'date' => $date,
                'count' => $dayTasks->count(),
                'tasks' => TaskResource::collection($dayTasks->values())->resolve($request),
            ])
            ->values();

        $projects = Project::query()
            ->withCount('tasks')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'color']);
        $milestones = Milestone::query()
            ->orderBy('due_date')
            ->orderBy('id')
            ->get(['id', 'name', 'due_date', 'project_id']);

        return response()->json([
            'projects' => $projects,
            'milestones' => $milestones,
            'tasks' => TaskResource::collection($tasks)->resolve($request),
            'calendar' => [
                'month' => $monthKey,
                'project_id' => isset($filters['project_id']) ? (int) $filters['project_id'] : null,
                'include_done' => $includeDone,
                'days' => $calendarDays,
                'total' => $scheduledTasks->count(),
            ],
            'defaults' => ['calendar_include_done' => config('solotasks.calendar_include_done')],
        ]);
    }
}
