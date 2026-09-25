<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "Sign in as": a users.impersonate holder switches the session to another
 * account to see the panel with that user's permissions, then returns.
 *
 * Both switches are written to the activity log; entries in between carry the
 * real user as impersonator (see ActivityLog::record()). The rules live in
 * App\Support\Impersonation.
 */
class ImpersonationController extends Controller
{
    public function start(Request $request, User $user): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        abort_unless(Impersonation::canImpersonate($actor, $user), 403);

        ActivityLog::record($user, 'impersonation_started');

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(Impersonation::SESSION_KEY, $actor->getKey());

        return redirect()
            ->route('admin.dashboard')
            ->with('info', "Вы работаете от имени пользователя {$user->name}");
    }

    public function stop(Request $request): RedirectResponse
    {
        $impersonatorId = Impersonation::impersonatorId();
        abort_if($impersonatorId === null, 404);

        /** @var User $impersonated */
        $impersonated = $request->user();
        $impersonator = User::find($impersonatorId);
        $request->session()->forget(Impersonation::SESSION_KEY);

        // The real user was blocked, deleted or lost the permission meanwhile: end the session.
        if ($impersonator === null || ! $impersonator->is_active || ! $impersonator->can(Impersonation::PERMISSION)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        Auth::guard('web')->login($impersonator);
        $request->session()->regenerate();

        ActivityLog::record($impersonated, 'impersonation_stopped');

        return redirect()
            ->route('admin.users.show', $impersonated)
            ->with('success', 'Вы вернулись к своему аккаунту');
    }
}
