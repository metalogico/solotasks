<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $project = Project::create($this->validateProject($request));

        return response()->json(['data' => $project], 201);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $project->update($this->validateProject($request));

        return response()->json(['data' => $project]);
    }

    public function destroy(Project $project): Response
    {
        DB::transaction(function () use ($project): void {
            abort_if(
                $project->tasks()->exists() || $project->milestones()->exists(),
                409,
                'Move or delete tasks and milestones before deleting the project.',
            );
            $project->delete();
        });

        return response()->noContent();
    }

    /**
     * @return array{name: string, color?: string}
     */
    private function validateProject(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'color' => ['sometimes', 'required', 'regex:/^#[a-fA-F0-9]{6}$/'],
        ]);
    }
}
