<?php

namespace App\Http\Resources\Account;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A session list row on the profile page (Profile/Index.vue). Expects a row
 * from AccountSessions::list(); current — the session of this request.
 *
 * @property-read object{id: string, key: string, ip_address: string|null, user_agent: string|null, last_activity: int} $resource
 */
class SessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $session = $this->resource;

        return [
            'key' => $session->key,
            'ip' => $session->ip_address,
            'agent' => $session->user_agent ? Str::limit($session->user_agent, 160) : null,
            'last_active_at' => Carbon::createFromTimestamp($session->last_activity)->toIso8601String(),
            'current' => $session->id === $request->session()->getId(),
        ];
    }
}
