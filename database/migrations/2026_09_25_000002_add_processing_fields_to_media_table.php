<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * upload_batch ties rows to the upload request that produced them (upload
 * progress polling), content_hash detects duplicate uploads, focal_x/focal_y
 * keep the important point of an image (0..1), variants lists the widths of the
 * generated responsive copies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->string('upload_batch', 36)->nullable()->index()->after('folder');
            $table->string('content_hash', 64)->nullable()->index()->after('upload_batch');
            $table->decimal('focal_x', 5, 4)->nullable()->after('height');
            $table->decimal('focal_y', 5, 4)->nullable()->after('focal_x');
            $table->json('variants')->nullable()->after('has_thumb');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropIndex(['upload_batch']);
            $table->dropIndex(['content_hash']);
            $table->dropColumn(['upload_batch', 'content_hash', 'focal_x', 'focal_y', 'variants']);
        });
    }
};
