<?php

namespace App\Http\Controllers\Admin\Account;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * Session keep-alive. The "session expires soon" dialog calls it when the user
 * chooses to stay: any authenticated request extends the session lifetime.
 */
class SessionPingController extends Controller
{
    public function __invoke(): Response
    {
        return response()->noContent();
    }
}
