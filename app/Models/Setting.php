<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A settings table row: the "group.field" key, the type and the value as a string.
 *
 * Read and write settings via App\Services\System\Settings: it casts types,
 * fills in defaults from SettingsSchema and flushes the cache.
 *
 * @property int $id
 * @property string $group
 * @property string $key
 * @property string $type
 * @property string|null $value
 */
class Setting extends Model
{
    protected $fillable = ['group', 'key', 'value', 'type'];
}
