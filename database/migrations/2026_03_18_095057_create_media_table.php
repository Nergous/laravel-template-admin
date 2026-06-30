<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The media table — media library files (images, video, audio, documents).
 * Model App\Models\Media; uploads run asynchronously via UploadMedia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('type')->nullable(); // image|video|audio|document|other
            $table->unsignedBigInteger('size')->nullable(); // bytes
            $table->boolean('has_thumb')->default(false);
            // Authorship via the TracksAuthor trait on the model. The trait won't fill
            // created_by itself — the row is created in the queue (UploadMedia), where
            // Auth::id() is empty; so the uploader id is set in the job manually. updated_by
            // is written by the trait when media is edited from the admin panel (e.g. renaming a file).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('original_name');        // sort by human-readable name
            $table->index('created_at');           // default gallery sort (created_at desc)
            $table->index(['type', 'created_at']); // image picker: filter by type + sort
        });

        if (! $this->innodbAutoIndexesForeignKeys()) {
            Schema::table('media', function (Blueprint $table) {
                $table->index('created_by');
                $table->index('updated_by');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }

    private function innodbAutoIndexesForeignKeys(): bool
    {
        return in_array(
            Schema::getConnection()->getDriverName(),
            ['mysql', 'mariadb'],
            true
        );
    }
};
