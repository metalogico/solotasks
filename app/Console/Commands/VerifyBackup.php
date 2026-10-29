<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class VerifyBackup extends Command
{
    protected $signature = 'solotasks:verify-backup {file : Copia SQLite da verificare}';

    protected $description = 'Ripristina una copia in un file temporaneo e ne verifica integrità e dati';

    public function handle(): int
    {
        $source = $this->argument('file');
        if (! is_file($source)) {
            $this->error('Copia non trovata.');

            return self::FAILURE;
        }
        $temporary = tempnam(sys_get_temp_dir(), 'solotasks-restore-');
        try {
            copy($source, $temporary);
            $database = new \PDO('sqlite:'.$temporary);
            $database->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            if ($database->query('PRAGMA integrity_check')->fetchColumn() !== 'ok'
                || $database->query('PRAGMA foreign_key_check')->fetch() !== false) {
                throw new \RuntimeException('La copia non supera i controlli di integrità.');
            }
            foreach (['projects', 'milestones', 'tasks'] as $table) {
                $count = $database->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
                $this->line($table.': '.$count);
            }
            $this->info('Ripristino temporaneo verificato. Il database attivo non è stato modificato.');
        } catch (\Throwable $exception) {
            $this->error('Copia non valida: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            $database = null;
            @unlink($temporary);
        }

        return self::SUCCESS;
    }
}
