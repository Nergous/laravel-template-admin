<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bot_message_media table — media attached to a bot message.
 *
 * Attachments are keyed by the message `code` (from modules/max-bot/messages.json),
 * NOT by a bot_messages row: a message may keep its default text yet still carry
 * attachments, and resetting the text must not drop them. `position` keeps the order
 * the admin arranged. Deleting a media row cascades the attachment away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_message_media', function (Blueprint $table) {
            $table->id();
            $table->string('code', 128);
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            // One media can be attached to a code only once; speeds up the bot's lookup by code.
            $table->unique(['code', 'media_id']);
            $table->index('code');
        });

        if (! $this->innodbAutoIndexesForeignKeys()) {
            Schema::table('bot_message_media', function (Blueprint $table) {
                $table->index('media_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_message_media');
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
