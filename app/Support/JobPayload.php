<?php

namespace App\Support;

/** Reads a queued job payload from a jobs or failed_jobs table row. */
final class JobPayload
{
    /** Human-readable job name (displayName of the payload). */
    public static function displayName(object $job): string
    {
        $payload = json_decode((string) ($job->payload ?? ''), true);

        return is_array($payload) && is_string($payload['displayName'] ?? null)
            ? $payload['displayName']
            : 'Неизвестная задача';
    }
}
