<?php

namespace Tests\Feature\Identity;

use App\Models\AuthSession;
use App\Models\Branch;
use App\Models\RefreshToken;
use App\Models\Tenant;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use App\Modules\Identity\Data\LoginData;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Services\AuthenticationService;
use App\Modules\Identity\Services\JwtAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;
use Tests\TestCase;

class AuthenticationServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_issues_access_and_refresh_tokens_for_a_granted_branch(): void
    {
        [$tenant, $branch, $user] = $this->identity();

        $result = app(AuthenticationService::class)->login(new LoginData(
            $tenant->slug, $user->email, 'Password123', $branch->id,
        ));

        $this->assertFalse($result->mfaRequired);
        $this->assertNotNull($result->tokens);
        $this->assertDatabaseHas('auth_sessions', ['id' => $result->tokens->authSessionId]);
        $this->assertDatabaseHas('refresh_tokens', [
            'token_hash' => hash('sha256', $result->tokens->refreshToken),
        ]);
        $this->assertTrue(app(JwtAccessTokenService::class)->validate($result->tokens->accessToken)->is($user));

        $payload = app(JWTAuth::class)->setToken($result->tokens->accessToken)->getPayload();
        $this->assertSame($tenant->id, $payload->get('tenant_id'));
        $this->assertSame($branch->id, $payload->get('branch_id'));
        $this->assertSame($result->tokens->authSessionId, $payload->get('session_id'));
    }

    public function test_failed_credentials_lock_the_account_with_db_backed_counters(): void
    {
        [$tenant, $branch, $user] = $this->identity(['max_failed_login_attempts' => 2]);
        $service = app(AuthenticationService::class);

        $this->assertIdentityCode(fn () => $service->login(
            new LoginData($tenant->slug, $user->email, 'wrong', $branch->id),
        ), 'INVALID_CREDENTIALS');
        $this->assertIdentityCode(fn () => $service->login(
            new LoginData($tenant->slug, $user->email, 'wrong', $branch->id),
        ), 'INVALID_CREDENTIALS');

        $this->assertNotNull($user->fresh()->locked_at);
        $this->assertIdentityCode(fn () => $service->login(
            new LoginData($tenant->slug, $user->email, 'Password123', $branch->id),
        ), 'ACCOUNT_LOCKED');
    }

    public function test_refresh_rotation_is_one_time_and_reuse_revokes_the_family_and_session(): void
    {
        [$tenant, $branch, $user] = $this->identity();
        $service = app(AuthenticationService::class);
        $tokens = $service->login(new LoginData(
            $tenant->slug, $user->email, 'Password123', $branch->id,
        ))->tokens;

        $rotated = $service->refresh($tokens->refreshToken);
        $this->assertNotSame($tokens->refreshToken, $rotated->refreshToken);
        $this->assertTrue(app(JwtAccessTokenService::class)->validate($rotated->accessToken)->is($user));

        $this->assertIdentityCode(fn () => $service->refresh($tokens->refreshToken), 'REFRESH_TOKEN_REUSED');
        $session = AuthSession::withoutGlobalScopes()->findOrFail($tokens->authSessionId);
        $this->assertNotNull($session->revoked_at);
        $this->assertSame(0, RefreshToken::query()->where('family_id', RefreshToken::query()->first()->family_id)
            ->whereNull('revoked_at')->count());
    }

    public function test_access_token_validation_rejects_changed_session_versions(): void
    {
        [$tenant, $branch, $user] = $this->identity();
        $tokens = app(AuthenticationService::class)->login(new LoginData(
            $tenant->slug, $user->email, 'Password123', $branch->id,
        ))->tokens;

        User::withoutGlobalScopes()->whereKey($user->id)->increment('authorization_version');

        $this->assertIdentityCode(
            fn () => app(JwtAccessTokenService::class)->validate($tokens->accessToken),
            'SESSION_INVALID',
        );
    }

    public function test_jwt_provider_uses_public_id_without_changing_web_numeric_sessions(): void
    {
        [, , $user] = $this->identity();

        $this->assertTrue(Auth::createUserProvider('users')->retrieveById($user->id)->is($user));
        $this->assertTrue(Auth::createUserProvider('jwt_users')->retrieveById($user->public_id)->is($user));
    }

    /** @param array<string, mixed> $policy */
    private function identity(array $policy = []): array
    {
        $tenant = Tenant::create(['slug' => 'organic', 'name' => 'Organic']);
        $branch = Branch::create(['tenant_id' => $tenant->id, 'code' => 'BGD', 'name' => 'Baghdad']);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id, 'email' => 'user@example.com', 'password' => 'Password123',
        ]);
        $branch->users()->attach($user);
        TenantSecurityPolicy::create(array_merge(['tenant_id' => $tenant->id], $policy));

        return [$tenant, $branch, $user];
    }

    private function assertIdentityCode(callable $callback, string $code): void
    {
        try {
            $callback();
            $this->fail("Expected identity error {$code}.");
        } catch (IdentityException $exception) {
            $this->assertSame($code, $exception->errorCode);
        }
    }
}
