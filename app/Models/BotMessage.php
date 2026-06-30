<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Переопределение текста бота. Код (code) — из реестра messages.json;
 * text — что реально отправит бот (если is_active). Метка для журнала — code.
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

    /** Метка субъекта для ActivityLog::record (labelFor читает ->name). */
    public function getNameAttribute(): string
    {
        return $this->code;
    }
}
