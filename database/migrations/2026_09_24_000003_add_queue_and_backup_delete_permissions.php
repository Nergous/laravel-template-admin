<?php

use App\Support\Migrations\PermissionMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds the system permissions introduced after the first release (queue
 * management, backup deletion) to installations that already have the RBAC
 * catalog, and grants them to the superadmin role.
 *
 * A fresh database is left untouched: RolePermissionSeeder creates the whole
 * catalog, and app:seed-fresh treats any existing row as application data.
 */
return new class extends Migration
{
    /** New permission => null: granted to the superadmin only. */
    private const PERMISSIONS = [
        'backups.delete' => null,
        'queue.view' => null,
        'queue.manage' => null,
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
