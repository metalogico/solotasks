<?php

namespace App\Http\Controllers;

use App\PersonalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function show(Request $request, PersonalAccess $access): JsonResponse
    {
        return response()->json([
            'authenticated' => $access->authenticated($request),
            'configured' => $access->configured(),
            'username' => $access->authenticated($request) ? config('solotasks.username') : null,
            'csrf_token' => csrf_token(),
        ]);
    }

    public function store(Request $request, PersonalAccess $access): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:1000'],
        ]);
        $key = 'personal-login:'.hash('sha256', (string) $request->ip());
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Too many attempts. Try again in a minute.');
        abort_unless($access->configured(), 503, 'Configure your personal credentials in the .env file.');

        $passwordMatches = Hash::check($credentials['password'], config('solotasks.password_hash'));
        if (! hash_equals(config('solotasks.username'), $credentials['username']) || ! $passwordMatches) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['username' => 'The username or password is incorrect.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('personal_access', $access->fingerprint());

        return $this->show($request, $access);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['csrf_token' => csrf_token()]);
    }
}
