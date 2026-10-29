<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class BackupDatabase extends Command
{
    protected $signature = 'solotasks:backup {--path= : Cartella delle copie SQLite}';

    protected $description = 'Crea una copia SQLite coerente, verificata e con accesso privato';

    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->error('Questo comando richiede SQLite.');

            return self::FAILURE;
        }
        $directory = $this->option('path') ?: config('solotasks.backup_path') ?: storage_path('app/private/backups');
        File::ensureDirectoryExists($directory, 0700, true);
        $path = $directory.'/solotasks-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sqlite';
        $oldMask = umask(0077);
        try {
            DB::connection()->getPdo()->exec('VACUUM INTO '.DB::connection()->getPdo()->quote($path));
            $copy = new \PDO('sqlite:'.$path);
            if ($copy->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
                throw new \RuntimeException('Controllo integrità non riuscito.');
            }
        } finally {
            umask($oldMask);
        }
        $this->info('Backup verificato: '.$path);

        return self::SUCCESS;
    }
}
