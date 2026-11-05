<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = config('solotasks.mcp_token');
        $authorization = $request->header('Authorization', '');
        $token = preg_match('/\ABearer +([^\s,]+)\z/i', $authorization, $matches) ? $matches[1] : null;

        if (! is_string($configuredToken) || $configuredToken === ''
            || ! is_string($token) || $token === ''
            || ! hash_equals($configuredToken, $token)) {
            return response()->json(['message' => 'A valid MCP bearer token is required.'], 401, [
                'WWW-Authenticate' => 'Bearer realm="SoloTasks MCP"',
                'Cache-Control' => 'no-store, private',
            ]);
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
