<?php

use App\Models\Task;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;

uses(DatabaseMigrations::class);

it('backs up actual records and verifies a temporary restore without altering the active database', function (): void {
    $task = Task::factory()->create(['name' => 'Saved in backup']);
    $directory = sys_get_temp_dir().'/solotasks-backup-test-'.bin2hex(random_bytes(6));
    try {
        $this->artisan('solotasks:backup', ['--path' => $directory])->assertSuccessful();
        $files = glob($directory.'/solotasks-*.sqlite');
        expect($files)->toHaveCount(1);
        $database = new PDO('sqlite:'.$files[0]);
        expect($database->query('SELECT name FROM tasks')->fetchColumn())->toBe('Saved in backup');
        $this->artisan('solotasks:verify-backup', ['file' => $files[0]])->assertSuccessful();
        $this->assertModelExists($task);
    } finally {
        $database = null;
        File::deleteDirectory($directory);
    }
});

it('rejects a missing or invalid backup without modifying the database', function (): void {
    $task = Task::factory()->create();
    $path = tempnam(sys_get_temp_dir(), 'solotasks-invalid-');
    file_put_contents($path, 'invalid sqlite data');
    try {
        $this->artisan('solotasks:verify-backup', ['file' => $path])->assertFailed();
        $this->artisan('solotasks:verify-backup', ['file' => $path.'-missing'])->assertFailed();
        $this->assertModelExists($task);
    } finally {
        unlink($path);
    }
});
