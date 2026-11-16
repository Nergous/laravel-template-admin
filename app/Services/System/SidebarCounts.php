<?php

namespace App\Services\System;

use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\ConnectionInterface;
use Spatie\Permission\Models\Permission;

/**
 * Record counts for the admin sidebar badges.
 *
 * The totals do not depend on the user, so they are counted in one query and
 * cached briefly. Each user gets only the sections they may view: a badge must
 * not reveal a number where there is no access.
 */
class SidebarCounts
{
    private const CACHE_KEY = 'admin.sidebar-counts.v3';

    private const CACHE_SECONDS = 30;

    /** Badge => [counted model, permission required to show the badge]. */
    private const SECTIONS = [
        'users' => [User::class, 'users.view'],
        'roles' => [Role::class, 'roles.view'],
        'permissions' => [Permission::class, 'permissions.view'],
        'media' => [Media::class, 'media.view'],
    ];

    public function __construct(
        private readonly Repository $cache,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * Badge counts for the user; null for a section they may not view.
     *
     * @return array<string, int|null>
     */
    public function for(User $user): array
    {
        $totals = $this->totals();
        $counts = [];

        foreach (self::SECTIONS as $section => [, $permission]) {
            $counts[$section] = $user->can($permission) ? $totals[$section] : null;
        }

        return $counts;
    }

    /**
     * All totals in one query of subqueries. Model global scopes apply,
     * so soft-deleted records are not counted.
     *
     * @return array<string, int>
     */
    private function totals(): array
    {
        return $this->cache->remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): array {
            $query = $this->db->query();

            foreach (self::SECTIONS as $section => [$model]) {
                $query->selectSub($model::query()->selectRaw('count(*)'), $section);
            }

            return array_map(intval(...), (array) $query->first());
        });
    }
}
