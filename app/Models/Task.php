<?php

namespace App\Models;

use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    public const STATUSES = ['backlog', 'todo', 'in_progress', 'in_review', 'done'];

    public const PRIORITIES = ['high', 'medium', 'low'];

    protected $fillable = ['name', 'description', 'priority', 'status', 'due_date', 'project_id', 'milestone_id'];

    protected $attributes = ['priority' => 'medium', 'status' => 'backlog'];

    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }
}
