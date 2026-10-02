<?php

namespace App\Providers;

use App\Enums\Role;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Only personal access tokens are used; the OAuth endpoints (/oauth/*) stay off.
        Passport::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurePassport();
        $this->configureApiDocs();
    }

    /** Token scopes match the role names; see App\Enums\Role. */
    private function configurePassport(): void
    {
        Passport::tokensCan(collect(Role::cases())->mapWithKeys(
            fn (Role $role) => [$role->scope() => ucfirst($role->value).' API'],
        )->all());

        // Every personal access token gets the longest role lifetime; EnsureTokenIsFresh shortens admin tokens.
        Passport::personalAccessTokensExpireIn(now()->addMinutes(
            max(array_map(fn (Role $role) => $role->tokenLifetimeMinutes(), Role::cases())),
        ));
    }

    /** Endpoints need a Bearer token unless marked @unauthenticated. */
    private function configureApiDocs(): void
    {
        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi) {
            $openApi->secure(SecurityScheme::http('bearer'));
        });
    }
}
