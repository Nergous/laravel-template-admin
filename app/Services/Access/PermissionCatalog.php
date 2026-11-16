<?php

namespace App\Services\Access;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/**
 * Reading the permission list: permissions by resource (the name part before
 * the first dot; without a dot — "other") with labels from lang/<locale>/permissions.php.
 */
class PermissionCatalog
{
    /**
     * Permission models by resource: users.view, users.edit → ['users' => [...]].
     *
     * @return array<string, Collection<int, Permission>>
     */
    public function grouped(): array
    {
        return $this->byResource()->all();
    }

    /** Total number of permissions (the "of N" counter in the role list). */
    public function count(): int
    {
        return Permission::count();
    }

    /**
     * Rows of the "role × permission" matrix: a group per resource with its
     * permissions and labels.
     *
     * @return Collection<int, array{resource: string, label: string, permissions: Collection<int, array{id: int, name: string, action: string, label: string}>}>
     */
    public function matrixGroups(): Collection
    {
        return $this->byResource()
            ->map(fn (Collection $items, string $resource) => [
                'resource' => $resource,
                'label' => $this->label("permissions.resources.$resource", $resource),
                'permissions' => $items->map(function (Permission $permission) {
                    $action = Str::contains($permission->name, '.') ? Str::after($permission->name, '.') : $permission->name;

                    return [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'action' => $action,
                        'label' => $this->label("permissions.actions.$action", $action),
                    ];
                })->values(),
            ])
            ->values();
    }

    /** @return Collection<string, Collection<int, Permission>> */
    private function byResource(): Collection
    {
        return Permission::orderBy('name')
            ->get()
            ->groupBy(fn (Permission $permission) => Str::contains($permission->name, '.') ? Str::before($permission->name, '.') : 'other')
            ->toBase();
    }

    /** The translated label, or the key part itself capitalized. */
    private function label(string $key, string $fallback): string
    {
        return Lang::has($key) ? __($key) : Str::ucfirst($fallback);
    }
}
