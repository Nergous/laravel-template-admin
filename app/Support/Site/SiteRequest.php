<?php

namespace App\Support\Site;

use Illuminate\Http\Request;

/**
 * The single "request for a public page" rule: not a JSON request and not an
 * admin address. Error handling uses it to tell public pages from the admin
 * panel and API calls.
 */
final class SiteRequest
{
    /** Whether the request targets a public page. */
    public static function isPage(Request $request): bool
    {
        return ! $request->expectsJson() && ! $request->is('admin', 'admin/*');
    }
}
