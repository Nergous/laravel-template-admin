<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица media — файлы медиабиблиотеки (изображения, видео, аудио, документы).
 * Модель App\Models\Media; загрузка идёт асинхронно через UploadMedia.
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
            $table->unsignedBigInteger('size')->nullable(); // байты
            $table->boolean('has_thumb')->default(false);
            // Авторство через трейт TracksAuthor на модели. created_by трейт сам не
            // заполнит — строка создаётся в очереди (UploadMedia), где Auth::id() пуст;
            // поэтому id загрузившего проставляется в job вручную. updated_by пишет
            // трейт при редактировании медиа из админки (например переименование файла).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('original_name');        // сортировка по человекочитаемому имени
            $table->index('created_at');           // дефолтная сортировка галереи (created_at desc)
            $table->index(['type', 'created_at']); // image-пикер: фильтр type + сортировка
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
