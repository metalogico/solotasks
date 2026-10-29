<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'priority' => $this->priority,
            'status' => $this->status,
            'due_date' => $this->due_date?->toDateString(),
            'project_id' => $this->project_id,
            'milestone_id' => $this->milestone_id,
            'project' => $this->whenLoaded('project', fn () => $this->project->only(['id', 'name', 'color'])),
            'milestone' => $this->whenLoaded('milestone', fn () => $this->milestone ? [
                'id' => $this->milestone->id,
                'name' => $this->milestone->name,
                'due_date' => $this->milestone->due_date?->toDateString(),
            ] : null),
        ];
    }
}
