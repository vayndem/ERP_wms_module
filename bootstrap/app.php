<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
    )
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB
        );

        $middleware->trimStrings(except: [
            'current_password',
            'password',
            'password_confirmation',
        ]);

        $middleware->api(prepend: [
            \Illuminate\Routing\Middleware\ThrottleRequests::class . ':api',
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo('/home');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('wms:hitung-replenishment')
            ->dailyAt('05:00')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('wms:hitung-abc')
            ->weeklyOn(1, '04:00')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('wms:pengingat-harian')
            ->weekdays()
            ->dailyAt('07:00')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('wms:periksa-invarian')
            ->dailyAt('06:30')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('wms:penyusutan-bulanan')
            ->monthlyOn(1, '02:00')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('queue:prune-failed --hours=720')->weeklyOn(7, '03:00');
        $schedule->command('auth:clear-resets')->daily();
    })
    ->create();
