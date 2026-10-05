<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Media library folders. A folder used to exist only as a label on its files
 * (media.folder), so it vanished with its last file; the table keeps empty
 * folders too. Folders nest: the name is the full path ("Баннеры/2026"), and
 * files point at their folder by it, so media.folder grows to fit a path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->string('folder', 255)->nullable()->change();
        });

        Schema::create('media_folders', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        DB::table('media')
            ->whereNotNull('folder')
            ->where('folder', '!=', '')
            ->distinct()
            ->orderBy('folder')
            ->pluck('folder')
            ->each(fn (string $name) => DB::table('media_folders')->insertOrIgnore([
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
    }

    public function down(): void
    {
        // media.folder goes back to 100 characters: refuse instead of letting the
        // database truncate (or reject) longer nested folder paths half-way.
        $tooLong = DB::table('media')
            ->whereNotNull('folder')
            ->distinct()
            ->pluck('folder')
            ->first(fn (string $folder) => mb_strlen($folder) > 100);

        if ($tooLong !== null) {
            throw new RuntimeException(sprintf(
                'Cannot roll back: media.folder holds a path longer than 100 characters ("%s"). '
                .'Shorten or move such folders first, then run the rollback again.',
                $tooLong,
            ));
        }

        Schema::dropIfExists('media_folders');

        Schema::table('media', function (Blueprint $table) {
            $table->string('folder', 100)->nullable()->change();
        });
    }
};
