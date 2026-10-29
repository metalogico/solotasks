<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use Illuminate\Http\Response;

class TaskController extends Controller
{
    public function store(TaskRequest $request): TaskResource
    {
        $task = Task::create($request->validated());

        return new TaskResource($task->load(['project', 'milestone']));
    }

    public function update(TaskRequest $request, Task $task): TaskResource
    {
        $task->update($request->validated());

        return new TaskResource($task->load(['project', 'milestone']));
    }

    public function destroy(Task $task): Response
    {
        $task->delete();

        return response()->noContent();
    }
}
