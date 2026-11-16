<?php

namespace App\Support\Access;

/**
 * The difference of two role permission sets in the activity log format
 * (field → [before, after]): granted permissions — permissions_granted, revoked —
 * permissions_revoked, each list sorted and comma-separated. The shared format of
 * the role form (RoleService) and the access matrix (PermissionService).
 */
final class PermissionDiff
{
    /**
     * @param  array<int, string>  $before
     * @param  array<int, string>  $after
     * @return array<string, array{0: string|null, 1: string|null}> Empty when the sets match
     */
    public static function changes(array $before, array $after): array
    {
        $granted = self::sorted(array_diff($after, $before));
        $revoked = self::sorted(array_diff($before, $after));
        $changes = [];

        if ($granted !== []) {
            $changes['permissions_granted'] = [null, implode(', ', $granted)];
        }
        if ($revoked !== []) {
            $changes['permissions_revoked'] = [implode(', ', $revoked), null];
        }

        return $changes;
    }

    /**
     * Granted permissions in the log format.
     *
     * @param  array<int, string>  $names
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function granted(array $names): array
    {
        return self::changes([], $names);
    }

    /**
     * Revoked permissions in the log format.
     *
     * @param  array<int, string>  $names
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function revoked(array $names): array
    {
        return self::changes($names, []);
    }

    /**
     * Whether the sets differ, ignoring order.
     *
     * @param  array<int, string>  $before
     * @param  array<int, string>  $after
     */
    public static function differs(array $before, array $after): bool
    {
        return self::changes($before, $after) !== [];
    }

    /**
     * @param  array<int, string>  $names
     * @return list<string>
     */
    private static function sorted(array $names): array
    {
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }
}
