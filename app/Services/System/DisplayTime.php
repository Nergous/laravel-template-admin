<?php

namespace App\Services\System;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;

/**
 * Display time zone of the admin panel and the public site. Timestamps are
 * stored in UTC (config app.timezone, the same for web requests, the queue and
 * the scheduler) and shown in the zone from the settings (general.timezone).
 * Do not pass dates without time here: the shift would change the day.
 *
 * Scoped: the zone is read from the settings once per request or job and memoized.
 */
#[Scoped]
class DisplayTime
{
    /** Display zone resolved in this request or job. */
    private ?string $zone = null;

    public function __construct(
        private readonly Settings $settings,
        #[Config('app.timezone')] private readonly string $storageZone,
    ) {}

    /**
     * Display zone from the settings; an invalid value falls back to the
     * storage zone. Before migrations Settings returns the defaults itself.
     * The value is memoized, the settings are not read again.
     */
    public function zone(): string
    {
        return $this->zone ??= $this->resolveZone();
    }

    /** The moment in the display zone (a copy; the model attribute is unchanged). */
    public function local(?CarbonInterface $moment): ?CarbonImmutable
    {
        return $moment === null ? null : CarbonImmutable::instance($moment)->setTimezone($this->zone());
    }

    /** Date or time of the moment in the display zone; an empty string for null. */
    public function format(?CarbonInterface $moment, string $format = 'd.m.Y'): string
    {
        return $this->local($moment)?->format($format) ?? '';
    }

    /** Current moment in the display zone. */
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->zone());
    }

    /** Reads the zone from the settings and checks that PHP knows it. */
    private function resolveZone(): string
    {
        $timezone = (string) $this->settings->value('general', 'timezone');

        return in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : $this->storageZone;
    }
}
