<?php

namespace App\Services\System\Dashboard;

use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\User;
use App\Services\Media\MediaLibrary;

/**
 * Dashboard stat cards: the main number and a caption line. Each card is
 * built only with the view permission of its section, like the sidebar badges.
 */
class DashboardStats
{
    public function __construct(
        private readonly MediaLibrary $media,
    ) {}

    /** @return array<string, array<string, mixed>> */
    public function for(User $user): array
    {
        return array_filter([
            'media' => $user->can('media.view') ? $this->media() : null,
            'users' => $user->can('users.view') ? $this->users() : null,
            'logins' => $user->can('activity-log.view') ? $this->logins() : null,
        ]);
    }

    /** @return array{value: int, sub: string, bytes: int, no_alt: int} */
    private function media(): array
    {
        $totals = $this->media->totals();
        $noAlt = Media::query()->withoutAlt()->count();

        return [
            'value' => $totals['count'],
            'sub' => $noAlt > 0 ? "без альтернативного текста: {$noAlt}" : 'у всех изображений есть альтернативный текст',
            'bytes' => $totals['bytes'],
            // When such files exist, the card opens their list.
            'no_alt' => $noAlt,
        ];
    }

    /** @return array{value: int, sub: string} */
    private function users(): array
    {
        $blocked = User::where('is_active', false)->count();

        return [
            'value' => User::count(),
            'sub' => $blocked > 0 ? "{$blocked} заблокировано" : 'все активны',
        ];
    }

    /**
     * Successful sign-ins over the last 24 hours, failed attempts in the
     * caption and a 7-day sparkline (sliding 24-hour windows, so the time zone
     * plays no part).
     *
     * @return array{value: int, sub: string, spark: list<int>}
     */
    private function logins(): array
    {
        $now = now();

        // One aggregate query: a SUM(CASE …) column per 24-hour window, counted
        // by the database rather than by PHP over a week of rows.
        $columns = [];
        $bindings = [];
        for ($day = 6; $day >= 0; $day--) {
            // Window "N days ago": (now - (N+1) days, now - N days].
            $columns[] = "SUM(CASE WHEN action = 'login' AND created_at > ? AND created_at <= ? THEN 1 ELSE 0 END) AS d{$day}";
            $bindings[] = $now->copy()->subDays($day + 1);
            $bindings[] = $now->copy()->subDays($day);
        }
        $columns[] = "SUM(CASE WHEN action = 'login_failed' AND created_at >= ? THEN 1 ELSE 0 END) AS failed";
        $bindings[] = $now->copy()->subDay();

        $totals = (array) ActivityLog::query()
            ->toBase()
            ->selectRaw(implode(', ', $columns), $bindings)
            ->whereIn('action', ['login', 'login_failed'])
            ->where('created_at', '>=', $now->copy()->subDays(7))
            ->first();

        $spark = [];
        for ($day = 6; $day >= 0; $day--) {
            $spark[] = (int) ($totals["d{$day}"] ?? 0);
        }
        $failed = (int) ($totals['failed'] ?? 0);

        return [
            'value' => $spark[6],
            'sub' => $failed > 0 ? "{$failed} неудачных за 24 ч" : 'без неудачных попыток',
            'spark' => $spark,
        ];
    }
}
