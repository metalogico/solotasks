<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $task = $this->route('task');
        $projectId = $this->input('project_id', $task?->project_id);

        return [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'priority' => ['sometimes', 'required', Rule::in(Task::PRIORITIES)],
            'status' => ['sometimes', 'required', Rule::in(Task::STATUSES)],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'project_id' => [
                $this->isMethod('POST') ? 'required' : 'sometimes',
                'required',
                'integer',
                Rule::exists('projects', 'id'),
            ],
            'milestone_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('milestones', 'id')->where('project_id', $projectId),
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $task = $this->route('task');

                if ($task
                    && $this->has('project_id')
                    && ! $this->exists('milestone_id')
                    && $task->milestone_id
                    && (int) $this->input('project_id') !== $task->project_id) {
                    $validator->errors()->add('milestone_id', 'Remove the milestone before changing projects.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Enter a task name.',
            'project_id.required' => 'Select a project.',
            'project_id.exists' => 'The selected project does not exist.',
            'milestone_id.exists' => 'The milestone must belong to the same project as the task.',
            'due_date.date_format' => 'Enter a valid due date in YYYY-MM-DD format.',
            'priority.in' => 'Choose a high, medium or low priority.',
            'status.in' => 'Choose one of the five board columns.',
        ];
    }
}
