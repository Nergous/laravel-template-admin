<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица settings — типизированные key/value-настройки приложения
 * (группа, ключ, значение-строка, тип). Модель App\Models\Setting;
 * перечень полей и дефолты задаёт Setting::SCHEMA.
 *
 * Индекс на group сознательно НЕ создаётся: Setting::stored() грузит
 * таблицу целиком и кэширует, группировка идёт в PHP, запись — по уникальному
 * key; по group в SQL никто не фильтрует.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group');                   // general | seo | security
            $table->string('key')->unique();           // напр. seo.meta_title_template
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
