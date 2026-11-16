<?php

namespace App\Services\System;

use App\Models\ActivityLog;
use Illuminate\Container\Attributes\Config;
use Illuminate\Translation\Translator;

/**
 * Activity log labels for the screen, filters and export: author, action,
 * subject type and field names. Strings live in lang/<locale>/activity.php,
 * the "class → translation key" map in config('activity.subjects').
 */
class ActivityLogLabels
{
    /**
     * @param  array<class-string, string>  $subjects  model class → translation key (activity.subjects)
     */
    public function __construct(
        private readonly Translator $translator,
        #[Config('activity.subjects', [])] private readonly array $subjects,
    ) {}

    /**
     * Author: the current user name, then the name snapshot, then "Система".
     * A failed sign-in has no author — it is shown as a guest with the client IP.
     * Preload the user relation to avoid a query per row.
     */
    public function actor(ActivityLog $log): string
    {
        return $log->user?->name ?? $log->actor_label ?? $this->guest($log) ?? 'Система';
    }

    /** Action label; without a translation — the action code as is. */
    public function action(ActivityLog $log): string
    {
        $key = 'activity.actions.'.$log->action;

        return $this->translator->has($key) ? $this->translator->get($key) : $log->action;
    }

    /**
     * Subject type label. New entities are registered in
     * config('activity.subjects'); an unregistered type shows the short class
     * name.
     */
    public function subjectType(ActivityLog $log): string
    {
        $type = (string) ($log->subject_type ?? '');

        if ($type === '') {
            return $this->translator->get('activity.subjects.system');
        }

        $key = $this->subjects[$type] ?? null;

        return $key ? $this->translator->get('activity.subjects.'.$key) : class_basename($type);
    }

    /**
     * Action filter options.
     *
     * @param  list<string>  $except  Actions the filter does not offer
     * @return list<array{value: string, label: string}>
     */
    public function actionOptions(array $except = []): array
    {
        return collect((array) $this->translator->get('activity.actions'))
            ->except($except)
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }

    /**
     * Subject type filter options: those registered in config('activity.subjects').
     *
     * @return list<array{value: string, label: string}>
     */
    public function subjectTypeOptions(): array
    {
        return collect($this->subjects)
            ->map(fn (string $key, string $class) => [
                'value' => $class,
                'label' => $this->translator->get('activity.subjects.'.$key),
            ])
            ->values()
            ->all();
    }

    /**
     * Human field names in change diffs.
     *
     * @return array<string, string>
     */
    public function fieldLabels(): array
    {
        return (array) $this->translator->get('activity.fields');
    }

    /** "Гость (IP)" for a failed sign-in; null for other actions. */
    private function guest(ActivityLog $log): ?string
    {
        if ($log->action !== 'login_failed') {
            return null;
        }

        // getAttribute: the model's $changes property is taken by Eloquent change tracking.
        $ip = $log->getAttribute('changes')['ip'][1] ?? null;

        return is_string($ip) && $ip !== '' ? "Гость ({$ip})" : 'Гость';
    }
}
