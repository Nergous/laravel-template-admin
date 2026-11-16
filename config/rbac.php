<?php

return [
    /*
    | The "superadmin" role name.
    |
    | This is the role with full access: its permissions are locked in the matrix
    | (cannot be changed), it cannot be removed from yourself, and new permissions
    | are granted to it automatically. To rename the superadmin or assign a different
    | role, change the single value HERE (and the seeder recreates the role under
    | the new name).
    |
    | Not to be confused with a "protected" role (the roles.is_system column: cannot
    | be deleted or renamed) — there can be several such roles (admin and operator
    | by default). The superadmin is always protected; the reverse is not required.
    */
    'superadmin_role' => env('RBAC_SUPERADMIN_ROLE', 'admin'),

    /*
    | The first administrator UserSeeder creates in production when seeding an empty
    | database (app:seed-fresh --users or RUN_SEEDS=true). Without a password no account
    | is created: set ADMIN_PASSWORD or create one with php artisan app:create-admin.
    */

    'initial_admin' => [
        'email' => env('ADMIN_EMAIL', 'admin@example.com'),
        'name' => env('ADMIN_NAME', 'Администратор'),
        'password' => env('ADMIN_PASSWORD'),
    ],
];
