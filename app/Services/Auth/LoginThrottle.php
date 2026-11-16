<?php

namespace App\Services\Auth;

use App\Services\System\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Limits of the "login" rate limiter. Attempts per minute come from the
 * security.login_throttle setting, read during the request.
 */
class LoginThrottle
{
    /** How many times the per-IP limit across all emails exceeds the per-account limit. */
    public const IP_MULTIPLIER = 4;

    public function __construct(private readonly Settings $settings) {}

    /**
     * The key is the email+ip pair: to get a "fresh" limit an attacker must
     * change both parts at once. The IP cannot be spoofed (trustProxies is
     * narrowed in bootstrap/app.php), so changing X-Forwarded-For does not bypass
     * the limit. An email+ip key (as in Breeze/Fortify) causes no mass lockout:
     * an attacker's attempts from one IP do not block the victim on another.
     *
     * A second, wider limit restricts one IP trying many emails (credential
     * stuffing): the email+ip key alone would not stop it.
     *
     * @return list<Limit>
     */
    public function limits(Request $request): array
    {
        $max = max(1, (int) $this->settings->value('security', 'login_throttle'));
        $email = Str::lower((string) $request->input('email'));

        return [
            Limit::perMinute($max)->by($email.'|'.$request->ip()),
            Limit::perMinute($max * self::IP_MULTIPLIER)->by('ip|'.$request->ip()),
        ];
    }
}
