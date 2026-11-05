<?php

use App\Http\Middleware\EnsureMcpToken;
use App\Mcp\Servers\SoloTasksServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Route::middleware(EnsureMcpToken::class)->group(function (): void {
    Mcp::web('/mcp/solotasks', SoloTasksServer::class);
});
