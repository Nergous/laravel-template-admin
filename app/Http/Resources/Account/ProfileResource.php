<?php

namespace App\Http\Resources\Account;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user's own account on the profile page (Profile/Index.vue).
 *
 * @mixin User
 */
class ProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
