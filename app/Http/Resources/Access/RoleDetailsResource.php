<?php

namespace App\Http\Resources\Access;

use App\Models\Role;
use Illuminate\Http\Request;

/**
 * A role on its page and in the edit form: the list fields plus permission
 * names and authorship. Expects permissions, creator and editor loaded.
 *
 * @mixin Role
 */
class RoleDetailsResource extends RoleResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'permission_names' => $this->permissions->pluck('name')->values(),
            'creator_name' => $this->creator?->name,
            'editor_name' => $this->editor?->name,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
