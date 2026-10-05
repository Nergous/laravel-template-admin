<?php

use App\Support\Migrations\PermissionMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Splits actions that used to ride on a broader permission into their own
 * system permissions: downloading dumps (was backups.view), exporting users
 * (was users.view) and "sign in as" (was superadmin-only).
 *
 * Existing roles keep their current abilities: every role holding the old
 * permission receives the new one; impersonation goes to the superadmin only.
 * A fresh database is left untouched — RolePermissionSeeder creates the catalog.
 */
return new class extends Migration
{
    /** New permission => the permission whose holders inherit it (null — superadmin only). */
    private const PERMISSIONS = [
        'backups.download' => 'backups.view',
        'users.export' => 'users.view',
        'users.impersonate' => null,
    ];

    public function up(): void
    {
        PermissionMigration::add(self::PERMISSIONS);
    }

    public function down(): void
    {
        // The permissions may already be assigned to custom roles; keep them.
    }
};
