<?php

namespace Tests\Feature;

use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\UserAccountService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthCelahTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'RahasiaKuat9';

    private function user(int $role, array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'type' => $role,
            'password' => self::PASSWORD,
            'is_active' => true,
        ]);
    }

    private function login(User $user, bool $remember = false)
    {
        RateLimiter::clear(mb_strtolower($user->email).'|127.0.0.1');
        RateLimiter::clear('127.0.0.1');

        return $this->post(route('login.attempt'), [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'remember' => $remember,
        ]);
    }

    public function test_api_user_accepts_a_valid_bearer_token(): void
    {
        $user = $this->user(User::ROLE_FINANCE);
        $token = $user->createToken('uji-api')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('email', $user->email);
    }

    public function test_api_user_rejects_an_invalid_bearer_token(): void
    {
        $this->withToken('token-tidak-valid')
            ->getJson('/api/user')
            ->assertUnauthorized();
    }

    public function test_deactivating_an_account_revokes_its_bearer_token(): void
    {
        $user = $this->user(User::ROLE_WAREHOUSE);
        $admin = $this->user(User::ROLE_SUPER_ADMIN);
        $token = $user->createToken('uji-pencabutan')->plainTextToken;

        app(UserAccountService::class)->nonaktifkan($user, $admin);

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->withToken($token)
            ->getJson('/api/user')
            ->assertUnauthorized();
    }

    public function test_an_active_session_is_rejected_after_logout(): void
    {
        $user = $this->user(User::ROLE_FINANCE);

        $this->login($user)->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk();
        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_active_session_is_rejected_after_the_account_is_deactivated(): void
    {
        $user = $this->user(User::ROLE_WAREHOUSE);
        $admin = $this->user(User::ROLE_SUPER_ADMIN);

        $this->login($user)->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk();

        app(UserAccountService::class)->nonaktifkan($user, $admin);
        Auth::forgetGuards();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_user_policy_denies_every_management_ability_to_each_non_admin_role(): void
    {
        $policy = app(UserPolicy::class);
        $target = $this->user(User::ROLE_WAREHOUSE);

        foreach ($this->nonAdminRoles() as $role) {
            $actor = $this->user($role);

            $this->assertFalse($policy->viewAny($actor));
            $this->assertTrue($policy->view($actor, $actor));
            $this->assertFalse($policy->view($actor, $target));
            $this->assertFalse($policy->create($actor));
            $this->assertFalse($policy->update($actor, $target));
            $this->assertFalse($policy->deactivate($actor, $target));
            $this->assertFalse($policy->resetPassword($actor, $target));
        }
    }

    public function test_each_non_admin_role_is_forbidden_from_every_user_management_endpoint(): void
    {
        $target = $this->user(User::ROLE_WAREHOUSE);

        foreach ($this->nonAdminRoles() as $role) {
            $actor = $this->user($role);
            $payload = [
                'name' => 'Pengguna Uji',
                'email' => 'pengguna-'.$role.'@example.test',
                'type' => User::ROLE_WAREHOUSE,
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
            ];

            $this->actingAs($actor)->get(route('user.edit', $target))->assertForbidden();
            $this->actingAs($actor)->post(route('user.store'), $payload)->assertForbidden();
            $this->actingAs($actor)->put(route('user.update', $target), $payload)->assertForbidden();
            $this->actingAs($actor)->post(route('user.deactivate', $target))->assertForbidden();
            $this->actingAs($actor)->post(route('user.reactivate', $target))->assertForbidden();
            $this->actingAs($actor)->get(route('user.login-audit'))->assertForbidden();
        }
    }

    public function test_accounting_manager_can_log_in_through_the_route_and_open_the_dashboard(): void
    {
        $manager = $this->user(User::ROLE_ACCOUNTING_MANAGER);

        $this->login($manager)->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($manager);
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_login_is_limited_to_twenty_attempts_per_ip_across_different_emails(): void
    {
        $ip = '198.51.100.37';
        RateLimiter::clear($ip);

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $email = "tidak-ada-{$attempt}@example.test";
            RateLimiter::clear(mb_strtolower($email).'|'.$ip);

            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->post(route('login.attempt'), [
                    'email' => $email,
                    'password' => 'password-salah',
                ])
                ->assertRedirect();
        }

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post(route('login.attempt'), [
                'email' => 'tidak-ada-21@example.test',
                'password' => 'password-salah',
            ])
            ->assertTooManyRequests();
    }

    public function test_remember_me_issues_a_recaller_cookie_and_persists_a_token(): void
    {
        $user = $this->user(User::ROLE_FINANCE, ['remember_token' => null]);
        $cookieName = Auth::guard()->getRecallerName();

        $this->login($user, true)
            ->assertRedirect(route('dashboard'))
            ->assertCookie($cookieName);

        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_guest_user_mutations_are_rejected_without_a_server_error(): void
    {
        $target = $this->user(User::ROLE_WAREHOUSE);
        $payload = [
            'name' => 'Pengguna Guest',
            'email' => 'guest-mutation@example.test',
            'type' => User::ROLE_WAREHOUSE,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ];

        $responses = [
            $this->post(route('user.store'), $payload),
            $this->put(route('user.update', $target), $payload),
            $this->post(route('user.deactivate', $target)),
            $this->post(route('user.reactivate', $target)),
        ];

        foreach ($responses as $response) {
            $this->assertContains($response->getStatusCode(), [302, 401, 403]);
            $this->assertLessThan(500, $response->getStatusCode());
        }
    }

    private function nonAdminRoles(): array
    {
        return [
            User::ROLE_PURCHASING,
            User::ROLE_FINANCE,
            User::ROLE_WAREHOUSE,
            User::ROLE_ACCOUNTING,
            User::ROLE_PRODUCTION,
            User::ROLE_ACCOUNTING_MANAGER,
        ];
    }
}
