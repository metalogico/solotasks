<?php

namespace App\Http\Middleware;

use App\PersonalAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePersonalLogin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app(PersonalAccess::class)->authenticated($request), 401, 'Sign in to continue.');

        return $next($request);
    }
}
