<?php

namespace App\Providers;

use App\Notifications\AccountEmailChannel;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        RateLimiter::for('booking-cancellation-request', function (Request $request) {
            $email = $request->input('email');
            $email = is_string($email) ? mb_strtolower(trim($email)) : '';
            $response = fn (Request $request, array $headers) => redirect()->route('home')
                ->withErrors(['email' => 'Aguarde um minuto antes de solicitar outro e-mail.'])
                ->withHeaders($headers);

            return [
                Limit::perMinute(5)->by('cancellation-ip:'.$request->ip())->response($response),
                Limit::perMinute(1)->by('cancellation-email:'.hash('sha256', $email))->response($response),
            ];
        });
        RateLimiter::for('client-portal-request', function (Request $request) {
            $email = $request->input('email');
            $email = is_string($email) ? mb_strtolower(trim($email)) : '';
            $response = fn (Request $request, array $headers) => redirect()->route('home')
                ->withErrors(['email' => 'Aguarde um minuto antes de solicitar outro e-mail.'])
                ->withHeaders($headers);

            return [
                Limit::perMinute(5)->by('portal-ip:'.$request->ip())->response($response),
                Limit::perMinute(1)->by('portal-email:'.hash('sha256', $email))->response($response),
            ];
        });
        RateLimiter::for('client-portal-link', fn (Request $request) => Limit::perMinute(60)
            ->by('portal-link-ip:'.$request->ip())
            ->response(fn (Request $request, array $headers) => response('Muitas solicitações. Tente novamente mais tarde.', 429, [
                ...$headers, 'Cache-Control' => 'no-store, private',
                'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow',
            ])));
        app(ChannelManager::class)->extend('mail',
            fn ($app) => $app->make(AccountEmailChannel::class));

        App::singleton('currentBarbershopId', function () {
            return auth()->user()?->barbershop_id;
        });

        Inertia::share([
            'auth' => function () {
                return auth()->user()
                    ? [
                        'user' => [
                            'id' => auth()->user()->id,
                            'name' => auth()->user()->name,
                            'email' => auth()->user()->email,
                            'role' => auth()->user()->role,
                        ],
                    ]
                    : null;
            },
        ]);

    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
