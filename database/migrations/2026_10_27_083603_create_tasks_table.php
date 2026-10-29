<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('milestone_id')->nullable();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->enum('priority', ['high', 'medium', 'low'])->default('medium');
            $table->enum('status', ['backlog', 'todo', 'in_progress', 'in_review', 'done'])->default('backlog');
            $table->date('due_date')->nullable();
            $table->timestamps();
            $table->foreign(['milestone_id', 'project_id'])->references(['id', 'project_id'])->on('milestones')->restrictOnDelete();
            $table->index(['project_id', 'due_date']);
            $table->index('due_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
