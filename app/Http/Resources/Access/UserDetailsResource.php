<?php

namespace App\Http\Resources\Access;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * A user on their page and in the edit form: the list fields plus the block
 * reason and authorship. Expects roles, creator and editor loaded.
 *
 * @mixin User
 */
class UserDetailsResource extends UserResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'blocked_reason' => $this->blocked_reason,
            'creator_name' => $this->creator?->name,
            'editor_name' => $this->editor?->name,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
