<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\MediaTextObserver;
use App\Services\Access\RbacGuard;
use App\Services\Auth\LoginThrottle;
use App\Services\Media\MediaReferenceRegistry;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

/**
 * Main application provider: bootstrapping that needs no provider of its own —
 * password policy, HTTPS in production, the superadmin bypass, the login rate
 * limiter and media index resets. Service lifetimes are declared by attributes
 * on the classes themselves (#[Scoped]); the public side lives in SiteServiceProvider.
 */
class AppServiceProvider extends ServiceProvider
{
    public function boot(Gate $gate): void
    {
        // One password policy for every place a password is set (user form,
        // app:create-admin, …): they all refer to Password::defaults(), so the
        // requirements are described only here.
        Password::defaults(fn () => Password::min(15)->mixedCase()->numbers()->symbols());

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // The superadmin passes every ability and policy check before it runs.
        // The "permission:" middleware calls $user->canAny(), so it honours this
        // too: deleting a permission row cannot lock them out of the panel.
        // RbacGuard is per request (#[Scoped]), so it is resolved on every check:
        // the closure lives as long as the process, and in a queue worker an
        // instance captured here would outlive its request.
        $gate->before(fn ($user) => $user instanceof User && $this->app->make(RbacGuard::class)->isSuperadmin($user) ? true : null);

        RateLimiter::for('login', fn (Request $request) => $this->app->make(LoginThrottle::class)->limits($request));

        // The index of media references in texts is reset when any model from
        // the registry changes: a new field with files is added only to MediaReferenceRegistry.
        /** @var list<class-string<Model>> $models */
        $models = array_values(array_unique(array_column(MediaReferenceRegistry::TEXT_FIELDS, 'model')));

        foreach ($models as $model) {
            $model::observe(MediaTextObserver::class);
        }
    }
}
