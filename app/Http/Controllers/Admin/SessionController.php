<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * Session keep-alive. The "session expires soon" dialog calls it when the user
 * chooses to stay: any authenticated request refreshes the session lifetime.
 */
class SessionController extends Controller
{
    public function ping(): Response
    {
        return response()->noContent();
    }
}
