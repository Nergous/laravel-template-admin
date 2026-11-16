<?php

namespace App\Http\Controllers\Admin\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\LogoutOtherSessionsRequest;
use App\Http\Requests\Account\UpdatePasswordRequest;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Http\Resources\Account\ProfileResource;
use App\Http\Resources\Account\SessionResource;
use App\Services\Auth\AccountSessions;
use App\Services\Auth\RememberedDevice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in user's own account: name and email, password and active
 * sessions (AccountSessions). Available to every authenticated user without
 * extra permissions.
 *
 * Ending sessions and changing the password reset "remember me" on other
 * devices; the current device gets a new cookie (RememberedDevice) and stays
 * signed in.
 */
class ProfileController extends Controller
{
    public function __construct(
        private readonly AccountSessions $sessions,
        private readonly RememberedDevice $device,
    ) {}

    public function show(Request $request): Response
    {
        return Inertia::render('Profile/Index', [
            'profile' => (new ProfileResource($request->user()))->resolve($request),
            'sessions' => SessionResource::collection($this->sessions->list($request->user()))->resolve($request),
            'sessionsSupported' => $this->sessions->supported(),
        ]);
    }

    /** Updates the name and email (rules in UpdateProfileRequest). */
    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $request->user()->update($request->safe()->only(['name', 'email']));

        return back()->with('success', 'Профиль обновлён');
    }

    /**
     * Changes the own password (hashed by the model's "hashed" cast).
     * AuthenticateSession ends the other sessions on their next request; the
     * current one keeps working.
     */
    public function password(UpdatePasswordRequest $request): RedirectResponse
    {
        $request->user()->update(['password' => $request->validated('password')]);
        $this->device->refresh($request->user());

        return redirect()
            ->route('admin.profile.show')
            ->with('success', 'Пароль изменён');
    }

    /** Ends all the user's other sessions (requires password confirmation). */
    public function logoutOthers(LogoutOtherSessionsRequest $request): RedirectResponse
    {
        $this->sessions->endOthers($request->user(), $request->session()->getId(), $request->validated('password'));
        $this->device->refresh($request->user());

        return back()->with('success', 'Сеансы на других устройствах завершены');
    }

    /** Ends one session by its public key (a hash of the session id). */
    public function destroySession(Request $request, string $key): RedirectResponse
    {
        abort_unless($this->sessions->endByKey($request->user(), $key, $request->session()->getId()), 404);
        $this->device->refresh($request->user());

        return back()->with('success', 'Сеанс завершён');
    }
}
