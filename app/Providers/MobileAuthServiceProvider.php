<?php

namespace App\Providers;

use App\Console\Commands\PruneExpiredTokens;
use App\Models\V2\ParentAccount;
use App\Models\V2\ParentStudentLink;
use App\Services\Auth\MobileTokenPolicy;
use App\Services\Auth\ReviewerDemoAccess;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class MobileAuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        ParentAccount::saving(fn (ParentAccount $parent) => app(ReviewerDemoAccess::class)->protectIdentity($parent));
        ParentStudentLink::saving(fn (ParentStudentLink $link) => app(ReviewerDemoAccess::class)->assertLink($link));

        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $valid): bool {
            $parent = $token->tokenable;
            if ($token->tokenable_type === (new ParentAccount)->getMorphClass()
                && (! $parent instanceof ParentAccount || $parent->trashed() || $parent->status !== 'active')) {
                return false;
            }

            if ($valid || ! request()->is('api/mobile/v2/*') || ! MobileTokenPolicy::persistent($token)) {
                return $valid;
            }

            // Exempt only persistent mobile-parent tokens from Sanctum's global age limit.
            $provider = config('auth.guards.sanctum.provider');
            $model = $provider ? config("auth.providers.$provider.model") : null;

            return $parent instanceof ParentAccount && ! $parent->trashed() && $parent->status === 'active'
                && ($provider === null || (is_string($model) && $parent instanceof $model));
        });

        if ($this->app->runningInConsole()) {
            $this->commands([PruneExpiredTokens::class]);
        }
    }
}
