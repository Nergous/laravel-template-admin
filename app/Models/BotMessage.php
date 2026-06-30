<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bot text override. The code is from the messages.json registry;
 * text is what the bot will actually send (if is_active). The activity log label is code.
 *
 * @property int $id
 * @property string $code
 * @property string $text
 * @property bool $is_active
 * @property int|null $updated_by
 */
class BotMessage extends Model
{
    protected $fillable = ['code', 'text', 'is_active', 'updated_by'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** Subject label for ActivityLog::record (labelFor reads ->name). */
    public function getNameAttribute(): string
    {
        return $this->code;
    }
}
