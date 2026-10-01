<?php

namespace Tests\Feature;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Middleware\TrustProxies;
use Tests\TestCase;

class ProxyTepercayaTest extends TestCase
{
    protected function tearDown(): void
    {
        TrustProxies::flushState();

        parent::tearDown();
    }

    public function test_a_client_cannot_forge_its_own_ip_when_no_proxy_is_trusted(): void
    {
        TrustProxies::flushState();

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.77',
        ])->get(route('login'));

        $response->assertSuccessful();

        $this->assertSame(
            '203.0.113.9',
            $response->baseRequest->ip(),
            'Tanpa daftar proxy tepercaya, X-Forwarded-For tidak boleh mengubah IP pemanggil. '
                . 'Kalau bisa, pembatas login per IP dan audit login ikut bisa dipalsukan.'
        );
    }

    public function test_trusting_a_proxy_is_an_explicit_choice_through_configuration(): void
    {
        TrustProxies::at('*');

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.77',
        ])->get(route('login'));

        $this->assertSame(
            '198.51.100.77',
            $response->baseRequest->ip(),
            'Mempercayai proxy hanya boleh terjadi bila TRUSTED_PROXIES memang diisi.'
        );
    }

    public function test_the_login_limiter_stays_registered(): void
    {
        $this->assertNotNull(
            app(RateLimiter::class)->limiter('login'),
            'Limiter login wajib terdaftar, kalau tidak middleware throttle:login gagal saat diminta.'
        );
    }
}
