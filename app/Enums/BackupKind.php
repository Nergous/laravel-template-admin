<?php

namespace App\Enums;

/**
 * A backup kind the application knows. The kind is written as a tag in the
 * file name; a scheduled copy has no tag. app:db-backup accepts other tags too
 * (--tag); they are rotated by the scheduled copy count.
 */
enum BackupKind: string
{
    /** The scheduler's nightly run (no tag). */
    case Scheduled = 'scheduled';

    /** The "Create backup" button in the admin panel. */
    case Manual = 'manual';

    /** A copy of the current state before a restore (app:db-restore). */
    case Prerestore = 'prerestore';

    /** Tag in the file name; a scheduled copy has none. */
    public function tag(): ?string
    {
        return $this === self::Scheduled ? null : $this->value;
    }
}
