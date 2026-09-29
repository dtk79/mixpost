<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Inovector\Mixpost\Mixpost;
use Sentry\Laravel\Integration;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register application services.
     */
    public function register(): void
    {
        Mixpost::report(function (Throwable $exception): void {
            Log::error('mixpost.reported_exception', [
                'exception_class' => $exception::class,
                'message' => $this->redactSecrets($exception->getMessage()),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'request_method' => app()->bound('request') ? request()->method() : null,
                // Intentionally omit the query string because OAuth callbacks contain one-time codes.
                'request_path' => app()->bound('request') ? request()->path() : null,
                'trace' => $this->redactSecrets($exception->getTraceAsString()),
            ]);

            if (config('sentry.dsn')) {
                Integration::captureUnhandledException($exception);
            }
        });
    }

    /**
     * Bootstrap application services.
     */
    public function boot(): void
    {
        // Liveness only: avoid sessions, database queries, and external providers.
        if (! $this->app->routesAreCached()) {
            Route::get('/api/health', static fn () => new JsonResponse([
                'ok' => true,
                'service' => 'mixpost',
            ], 200, ['Cache-Control' => 'no-store']))->name('peachy.health');
        }

        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            return route('mixpost.password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);
        });
    }

    private function redactSecrets(string $value): string
    {
        return preg_replace(
            [
                '/((?:access_token|refresh_token|client_secret|app_secret)=)[^&\\s]+/i',
                '/(Authorization:\\s*Bearer\\s+)[^\\s]+/i',
                '/("(?:access_token|refresh_token|client_secret|app_secret)"\\s*:\\s*")[^"]+/i',
            ],
            '$1[REDACTED]',
            $value,
        ) ?? '[unable to render exception detail]';
    }
}
