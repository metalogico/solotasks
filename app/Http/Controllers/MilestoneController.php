<?php

namespace App\Http\Controllers;

use App\Models\Milestone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MilestoneController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')],
        ]);

        return response()->json(['data' => Milestone::create($data)], 201);
    }

    public function update(Request $request, Milestone $milestone): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'project_id' => ['sometimes', 'integer', Rule::in([$milestone->project_id])],
        ]);
        $milestone->update($data);

        return response()->json(['data' => $milestone]);
    }

    public function destroy(Milestone $milestone): Response
    {
        DB::transaction(function () use ($milestone): void {
            $milestone->tasks()->update(['milestone_id' => null]);
            $milestone->delete();
        });

        return response()->noContent();
    }
}
