<?php

/*
| Proxies whose X-Forwarded-* headers are trusted (read by Laravel's
| TrustProxies middleware; header set in bootstrap/app.php).
|
| TRUSTED_PROXIES: comma-separated IPs/CIDRs. Unset or empty means the local
| reverse proxy only (nginx on the same host: 127.0.0.1, ::1). Requests from
| anywhere else keep their direct address, so a client cannot forge
| X-Forwarded-For to dodge the login rate limit or fake audit IPs.
|
| "none" trusts no proxy. "*" trusts every caller — acceptable ONLY when the
| application is reachable exclusively through a trusted proxy.
|
| Read through config (not env() in bootstrap/app.php), so it survives
| config:cache.
*/

$proxies = trim((string) env('TRUSTED_PROXIES', ''));

return [
    'proxies' => match (strtolower($proxies)) {
        '' => ['127.0.0.1', '::1'],
        'none' => [],
        '*' => '*',
        default => array_values(array_filter(array_map('trim', explode(',', $proxies)))),
    },
];
