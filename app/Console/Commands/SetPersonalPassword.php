<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class SetPersonalPassword extends Command
{
    protected $signature = 'solotasks:password';

    protected $description = 'Genera l’hash bcrypt della password da inserire nel .env personale';

    public function handle(): int
    {
        $password = $this->secret('Nuova password personale (almeno 12 caratteri)');
        if (! is_string($password) || mb_strlen($password) < 12 || strlen($password) > 72) {
            $this->error('Usa almeno 12 caratteri e al massimo 72 byte.');

            return self::FAILURE;
        }
        if ($password !== $this->secret('Ripeti la password')) {
            $this->error('Le password non coincidono.');

            return self::FAILURE;
        }
        $this->line("SOLOTASKS_PASSWORD_HASH='".Hash::make($password)."'");
        $this->info('Copia questa riga nel tuo .env privato, poi ricrea il container.');

        return self::SUCCESS;
    }
}
