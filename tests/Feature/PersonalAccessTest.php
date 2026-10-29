<?php

use App\Models\Project;
use App\PersonalAccess;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    config(['solotasks.username' => 'michele-test', 'solotasks.password_hash' => Hash::make('test-password-123')]);
});

it('starts a personal session with configured credentials and ends it on logout', function (): void {
    $this->postJson('/api/v1/session', ['username' => 'michele-test', 'password' => 'test-password-123'])
        ->assertOk()->assertJsonPath('authenticated', true)->assertSessionHas('personal_access');
    $this->getJson('/api/v1/board')->assertOk();
    $this->deleteJson('/api/v1/session')->assertOk()->assertSessionMissing('personal_access');
    $this->getJson('/api/v1/board')->assertUnauthorized();
});

it('rejects incorrect credentials without creating an authenticated session', function (string $username, string $password): void {
    $this->postJson('/api/v1/session', compact('username', 'password'))
        ->assertUnprocessable()->assertJsonValidationErrors(['username'])
        ->assertJsonPath('errors.username.0', 'The username or password is incorrect.')
        ->assertSessionMissing('personal_access');
})->with([
    'wrong password' => ['michele-test', 'wrong-password'],
    'wrong username' => ['another-name', 'test-password-123'],
]);

it('rate limits repeated failed logins with 429', function (): void {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/v1/session', ['username' => 'michele-test', 'password' => 'wrong'])->assertUnprocessable();
    }
    $this->postJson('/api/v1/session', ['username' => 'michele-test', 'password' => 'test-password-123'])
        ->assertTooManyRequests()->assertSessionMissing('personal_access');
});

it('fails closed when credentials have not been configured', function (): void {
    config(['solotasks.password_hash' => '']);

    $this->postJson('/api/v1/session', ['username' => 'michele-test', 'password' => 'anything'])
        ->assertServiceUnavailable()->assertSessionMissing('personal_access');
});

it('invalidates existing access when the configured password changes', function (): void {
    $session = app(PersonalAccess::class)->fingerprint();
    config(['solotasks.password_hash' => Hash::make('changed-password')]);

    $this->withSession(['personal_access' => $session])->getJson('/api/v1/board')->assertUnauthorized();
});

it('protects every data endpoint before processing unauthenticated input', function (string $method, string $path): void {
    $this->json($method, '/api/v1/'.$path, ['name' => 'Unwanted record'])
        ->assertUnauthorized()->assertJsonPath('message', 'Sign in to continue.');
    $this->assertDatabaseCount('projects', 0);
    $this->assertDatabaseCount('milestones', 0);
    $this->assertDatabaseCount('tasks', 0);
})->with([
    ['GET', 'board'], ['POST', 'projects'], ['PATCH', 'projects/1'], ['DELETE', 'projects/1'],
    ['POST', 'milestones'], ['PATCH', 'milestones/1'], ['DELETE', 'milestones/1'],
    ['POST', 'tasks'], ['PATCH', 'tasks/1'], ['DELETE', 'tasks/1'],
]);

it('keeps private data out of the public shell and disables response caching', function (): void {
    Project::factory()->create(['name' => 'PRIVATE-PROJECT-NAME']);

    $this->get('/')->assertOk()->assertDontSee('PRIVATE-PROJECT-NAME')->assertHeader('X-Frame-Options', 'DENY');
    $this->getJson('/api/v1/session')->assertJsonPath('authenticated', false)->assertJsonPath('username', null);
});
