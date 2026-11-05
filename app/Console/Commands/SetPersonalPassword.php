<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class SetPersonalPassword extends Command
{
    protected $signature = 'solotasks:password';

    protected $description = 'Generate a bcrypt password hash for the personal login';

    public function handle(): int
    {
        $password = $this->secret('New password (at least 12 characters)');
        if (! is_string($password) || mb_strlen($password) < 12 || strlen($password) > 72) {
            $this->error('Use at least 12 characters and no more than 72 bytes.');

            return self::FAILURE;
        }
        if ($password !== $this->secret('Repeat the password')) {
            $this->error('The passwords do not match.');

            return self::FAILURE;
        }
        $this->line("SOLOTASKS_PASSWORD_HASH='".Hash::make($password)."'");
        $this->info('Copy this line into your private .env file, then recreate the container.');

        return self::SUCCESS;
    }
}
