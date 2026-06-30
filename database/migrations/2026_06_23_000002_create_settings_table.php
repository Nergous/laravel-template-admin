<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The settings table — typed key/value application settings
 * (group, key, string value, type). Model App\Models\Setting;
 * the list of fields and defaults is defined by Setting::SCHEMA.
 *
 * An index on group is deliberately NOT created: Setting::stored() loads
 * the whole table and caches it, grouping happens in PHP, writes go by the unique
 * key; nobody filters by group in SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group');                   // general | seo | security
            $table->string('key')->unique();           // e.g. seo.meta_title_template
            $table->text('value')->nullable();
            $table->string('type')->default('string'); // string | text | bool | int
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
