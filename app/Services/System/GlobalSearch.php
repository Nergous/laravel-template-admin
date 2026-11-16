<?php

namespace App\Services\System;

use App\Enums\MediaType;
use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Global admin panel search (Cmd+K).
 *
 * Searches users, roles and media, each only with the view permission. A link
 * leads to the record or its list by the shared AdminLinks rule. A new entity
 * is one more private method and one line in find().
 */
class GlobalSearch
{
    /** Matches per entity. */
    private const LIMIT = 5;

    /** Minimum query length. */
    private const MIN_LENGTH = 2;

    public function __construct(private readonly AdminLinks $links) {}

    /**
     * Results across the entities the user may view, up to LIMIT for each.
     *
     * @return Collection<int, array{type: string, label: string, meta: string, url: string, icon: string}>
     */
    public function find(User $user, string $q): Collection
    {
        $q = trim($q);

        if (mb_strlen($q) < self::MIN_LENGTH) {
            return collect();
        }

        return collect()
            ->concat($user->can('users.view') ? $this->users($q) : [])
            ->concat($user->can('roles.view') ? $this->roles($q) : [])
            ->concat($user->can('media.view') ? $this->media($q) : [])
            ->values();
    }

    /** @return Collection<int, array<string, string>> */
    private function users(string $q): Collection
    {
        return User::search($q)
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'email'])
            ->map(fn (User $found) => [
                'type' => 'user',
                'label' => $found->name,
                'meta' => $found->email,
                'url' => $this->links->route('admin.users.show', $found),
                'icon' => 'user',
            ]);
    }

    /** @return Collection<int, array<string, string>> */
    private function roles(string $q): Collection
    {
        return Role::search($q)
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'description'])
            ->map(fn (Role $role) => [
                'type' => 'role',
                'label' => $role->name,
                'meta' => $role->description ?: 'Роль',
                'url' => $this->links->route('admin.roles.show', $role),
                'icon' => 'shield',
            ]);
    }

    /** @return Collection<int, array<string, string>> */
    private function media(string $q): Collection
    {
        return Media::search($q)
            ->latest('id')
            ->limit(self::LIMIT)
            ->get(['id', 'filename', 'original_name', 'type'])
            ->map(function (Media $media) {
                $name = $media->original_name ?: basename($media->filename);

                return [
                    'type' => 'media',
                    'label' => $name,
                    'meta' => $media->type?->label() ?? MediaType::Other->label(),
                    'url' => $this->links->route('admin.media.index', ['search' => $name]),
                    'icon' => 'asset',
                ];
            });
    }
}
