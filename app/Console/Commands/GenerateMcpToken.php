<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateMcpToken extends Command
{
    protected $signature = 'solotasks:mcp-token';

    protected $description = 'Generate an MCP bearer token for the private .env file and MCP clients';

    public function handle(): int
    {
        $token = bin2hex(random_bytes(32));

        $this->info('Copy this line into your private .env file, then recreate the container:');
        $this->line('SOLOTASKS_MCP_TOKEN='.$token);
        $this->newLine();
        $this->comment('Use the same token value in your MCP client as Authorization: Bearer <token>.');

        return self::SUCCESS;
    }
}
