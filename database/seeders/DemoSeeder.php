<?php

namespace Database\Seeders;

use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Demo data can only be loaded in the local environment.');
        }

        DB::transaction(function (): void {
            Task::query()->delete();
            Milestone::query()->delete();
            Project::query()->delete();

            $shelf = Project::create(['name' => "Here's My Shelf", 'color' => '#7551ff']);
            $issued = Project::create(['name' => 'Issued', 'color' => '#2d86f5']);
            $prompts = Project::create(['name' => 'promptprompt', 'color' => '#ed5991']);
            $guilds = Project::create(['name' => 'Star Guilds', 'color' => '#f78a36']);
            $site = Project::create(['name' => 'metalogico.dev', 'color' => '#2eb884']);

            $community = $shelf->milestones()->create(['name' => 'Community polish', 'due_date' => '2026-10-30']);
            $collections = $shelf->milestones()->create(['name' => 'Collection refresh', 'due_date' => '2026-11-27']);
            $reader = $issued->milestones()->create(['name' => 'Reader maintenance', 'due_date' => '2026-10-30']);
            $library = $issued->milestones()->create(['name' => 'Library discovery', 'due_date' => '2026-11-20']);
            $composer = $prompts->milestones()->create(['name' => 'Composer beta', 'due_date' => '2026-10-29']);
            $workflows = $prompts->milestones()->create(['name' => 'Library workflows', 'due_date' => '2026-11-26']);
            $playtest = $guilds->milestones()->create(['name' => 'November playtest', 'due_date' => '2026-11-26']);
            $content = $site->milestones()->create(['name' => 'Site content refresh', 'due_date' => '2026-11-24']);

            $rows = [
                [$shelf, 'done', 'Fix duplicate games after BGG import', 'Match existing collection entries by BGG ID before importing a second copy.', 'high', '2026-10-08', null],
                [$shelf, 'done', 'Keep collection filters when opening a game', 'Restore the selected filters and scroll position when returning to the collection.', 'medium', '2026-10-15', null],
                [$shelf, 'done', 'Correct player counts in play history', 'Count guests alongside registered players.', 'medium', '2026-10-23', null],
                [$shelf, 'in_review', 'Check shelf links on mobile', 'Review public shelf links on narrow screens, including long collection names.', 'low', '2026-10-28', $community],
                [$shelf, 'in_progress', 'Improve BGG sync conflict messages', 'Explain which local changes were kept when imported collection data differs.', 'high', '2026-10-29', $community],
                [$shelf, 'todo', 'Make game-list sorting consistent', 'Use the same title ordering in custom lists and public shelves.', 'medium', '2026-11-05', $collections],
                [$shelf, 'todo', 'Add an unplayed-games collection filter', 'Show owned games with no recorded plays.', 'medium', '2026-11-17', $collections],
                [$shelf, 'backlog', 'Show shared games on public profiles', 'Explore a small shared-games section when viewing another collector\'s profile.', 'medium', '2026-11-24', $collections],
                [$issued, 'done', 'Resume the web reader at the last page', 'Save the page before closing the reader and restore it on the next visit.', 'medium', '2026-10-13', $reader],
                [$issued, 'done', 'Handle misnamed comic archives', 'Detect the actual archive format when a CBR file has a CBZ extension.', 'medium', '2026-10-20', null],
                [$issued, 'in_review', 'Check OPDS page streaming in Panels', 'Test page order and stream URLs with a mixed CBZ and PDF library.', 'high', '2026-10-28', $reader],
                [$issued, 'in_progress', 'Improve cover loading in large libraries', 'Avoid fetching every full-size cover on the initial library view.', 'medium', '2026-10-30', $reader],
                [$issued, 'todo', 'Refine gap detection for ongoing series', 'Check issue-number gaps without flagging annuals as missing regular issues.', 'medium', '2026-11-05', $library],
                [$issued, 'todo', 'Verify progress sync after reopening a comic', 'Read a few pages on mobile, then resume in the web reader.', 'medium', '2026-11-17', $library],
                [$issued, 'backlog', 'Add a recently finished comics view', 'Make it easier to find the issues finished this week.', 'low', '2026-11-20', $library],
                [$prompts, 'done', 'Keep the global composer active without a window', 'Verify the keyboard shortcut after closing the last library window.', 'medium', '2026-10-15', $composer],
                [$prompts, 'in_review', 'Verify MCP access with Allow Changes disabled', 'Read-only tools should keep working while write operations remain unavailable.', 'medium', '2026-10-29', $composer],
                [$prompts, 'in_progress', 'Improve search ranking for prompt fragments', 'Prefer title matches before matches found only in the fragment body.', 'medium', '2026-11-03', $workflows],
                [$prompts, 'todo', 'Restore selection after switching libraries', 'Remember the selected fragment separately for each library.', 'medium', '2026-11-10', $workflows],
                [$prompts, 'backlog', 'Improve keyboard focus in the icon picker', 'Keep arrow-key navigation predictable after filtering icons.', 'low', '2026-11-20', null],
                [$prompts, 'backlog', 'Refresh beta install and recovery notes', 'Document the current macOS beta install steps and library recovery path.', 'low', '2026-11-26', null],
                [$guilds, 'done', 'Make ship inventory updates idempotent', 'Retry the same transfer without adding a second stack of cargo.', 'medium', '2026-10-23', null],
                [$guilds, 'in_review', 'Verify recovery after a dropped game session', 'Reconnect during a station visit and check the active ship and cargo state.', 'high', '2026-10-29', $playtest],
                [$guilds, 'in_progress', 'Reduce duplicate messages during gate jumps', 'Trace repeated arrival events when a client reconnects during a jump.', 'high', '2026-11-03', $playtest],
                [$guilds, 'todo', 'Improve station-chat reconnect handling', 'Rejoin the station channel after the WebSocket connection recovers.', 'medium', '2026-11-12', $playtest],
                [$guilds, 'backlog', 'Tune rookie ship fittings for the next playtest', 'Compare starter loadouts in short combat and cargo runs.', 'medium', '2026-11-26', $playtest],
                [$site, 'done', 'Fix canonical URLs on blog posts', 'Remove duplicate trailing-slash variants from generated article URLs.', 'medium', '2026-10-20', null],
                [$site, 'todo', 'Edit the coding-agent workflow article', 'Tighten the examples and check the command snippets before publishing.', 'low', '2026-10-27', null],
                [$site, 'todo', 'Improve code-block readability on mobile', 'Review font size, contrast and horizontal scrolling in longer posts.', 'medium', '2026-11-12', $content],
                [$site, 'backlog', 'Refresh project summaries on the homepage', 'Update the short descriptions and links for the active side projects.', 'low', '2026-11-24', $content],
            ];

            foreach ($rows as [$project, $status, $name, $description, $priority, $date, $milestone]) {
                $project->tasks()->create([
                    'status' => $status,
                    'name' => $name,
                    'description' => $description,
                    'priority' => $priority,
                    'due_date' => $date,
                    'milestone_id' => $milestone?->id,
                ]);
            }
        });

        $this->command?->info('Local demo loaded: 5 projects, 8 milestones and 30 tasks for October and November 2026.');
    }
}
