<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single media file attached to a bot message.
 *
 * Keyed by the message `code` from the messages.json registry (see the
 * bot_message_media migration), independent of the bot_messages text override.
 * The Python bot joins this to `media` to resolve the file to upload to MAX.
 *
 * @property int $id
 * @property string $code
 * @property int $media_id
 * @property int $position
 */
class BotMessageMedia extends Model
{
    protected $table = 'bot_message_media';

    protected $fillable = ['code', 'media_id', 'position'];

    protected $casts = [
        'position' => 'integer',
    ];

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
