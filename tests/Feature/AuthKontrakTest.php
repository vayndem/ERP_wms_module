<?php

namespace Tests\Feature;

use App\Models\LogAudit;
use App\Models\User;
use App\Services\UserAccountService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthKontrakTest extends TestCase
{
    use DatabaseTransactions;

    private function masukkanKeSesi(User $user): static
    {
        return $this->withSession([Auth::guard('web')->getName() => $user->id]);
    }

    public function test_authenticated_user_visiting_guest_page_is_redirected_to_dashboard(): void
    {
        $user = User::factory()->create(['type' => User::ROLE_WAREHOUSE]);

        $this->actingAs($user)->get(route('login'))->assertRedirect(route('dashboard'));
    }

    public function test_password_reset_writes_exactly_one_audit_row(): void
    {
        $user = User::factory()->create(['type' => User::ROLE_WAREHOUSE]);
        $token = Password::createToken($user);

        $sebelum = LogAudit::where('event', UserAccountService::RESET_PASSWORD)->where('user_id', $user->id)->count();

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'RahasiaBaru9',
            'password_confirmation' => 'RahasiaBaru9',
        ])->assertRedirect(route('login'));

        $this->assertSame(
            $sebelum + 1,
            LogAudit::where('event', UserAccountService::RESET_PASSWORD)->where('user_id', $user->id)->count()
        );
    }

    public function test_reset_link_request_issues_no_separate_existence_query_and_keeps_neutral_message(): void
    {
        Notification::fake();

        $aktif = User::factory()->create(['type' => User::ROLE_WAREHOUSE]);
        $pesan = 'Jika email tersebut terdaftar dan aktif, tautan reset sudah dikirim.';

        RateLimiter::clear(mb_strtolower($aktif->email) . '|127.0.0.1');
        DB::enableQueryLog();
        $this->post(route('password.email'), ['email' => $aktif->email])->assertSessionHas('status', $pesan);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, array_filter($log, fn ($q) => str_contains($q['query'], 'select exists')));
        Notification::assertSentTo($aktif, ResetPassword::class);

        $this->post(route('password.email'), ['email' => 'tidak-ada@example.test'])->assertSessionHas('status', $pesan);
    }

    public function test_open_session_of_a_deactivated_account_is_rejected_on_next_request(): void
    {
        $user = User::factory()->create(['type' => User::ROLE_WAREHOUSE]);

        $this->masukkanKeSesi($user)->get(route('dashboard'))->assertOk();

        $user->forceFill(['is_active' => false])->save();
        $this->app['auth']->forgetGuards();

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Akun Anda dinonaktifkan. Hubungi administrator sistem.']);
        $this->assertGuest();
        $this->assertSame(
            1,
            LogAudit::where('event', UserAccountService::LOGOUT)->where('user_id', $user->id)->count()
        );
    }

    public function test_deactivated_account_gets_401_on_a_json_request(): void
    {
        $user = User::factory()->create(['type' => User::ROLE_WAREHOUSE]);

        $this->masukkanKeSesi($user)->get(route('dashboard'))->assertOk();

        $user->forceFill(['is_active' => false])->save();
        $this->app['auth']->forgetGuards();

        $this->getJson(route('dashboard'))
            ->assertStatus(401)
            ->assertJson(['message' => 'Akun Anda dinonaktifkan. Hubungi administrator sistem.']);
        $this->assertGuest();
    }

    public function test_active_account_keeps_passing_the_session_check(): void
    {
        $user = User::factory()->create(['type' => User::ROLE_WAREHOUSE]);

        $this->masukkanKeSesi($user)->get(route('dashboard'))->assertOk();
        $this->app['auth']->forgetGuards();
        $this->get(route('dashboard'))->assertOk();
        $this->assertAuthenticatedAs($user);
    }
}
