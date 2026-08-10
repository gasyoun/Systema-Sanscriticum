<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Учёт работы дизайнера: баннеры курса в трёх форматах + исходники PSD.
 *
 * ПОЧЕМУ СВОЯ ТАБЛИЦА, А НЕ МЕДИАТЕКА CURATOR. В проекте стоит
 * awcodes/filament-curator (таблица `media` + CuratorPicker), но идти через неё
 * нельзя по трём причинам: PSD — не `type=image` и в Curator не ложится; нужны
 * поля «формат / кто / когда», которых в `media` нет; и в репозитории висит
 * незавершённый переход courses → Curator (MigrateMediaToCurator знает про
 * 'courses' => 'image', а колонок image/image_id на courses не существует).
 * Подключившись к Curator, мы получили бы ТРЕТИЙ источник правды о картинках
 * курса. Если кто-то соберётся переигрывать это решение — сначала доделайте или
 * похороните тот переход.
 *
 * `courses.image_path` эта таблица НЕ заменяет: там обложка витрины (карточка
 * курса в магазине), у неё другой потребитель.
 *
 * `disk` хранится В СТРОКЕ, как в homework_files, — именно это позволит будущий
 * переезд на S3 делать построчно (docs/ROADMAP_MEDIA_STORAGE_2026_2028.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_design_assets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();

            // '16:9' | '9:16' | '4:3' — список значений живёт в config/design_assets.php.
            $table->string('format', 8);

            // Сама картинка. disk — в строке (см. докблок выше).
            $table->string('disk', 32);
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('mime', 64)->nullable();
            // Пиксели нужны для мягкой сверки пропорции: 1600×900 и 1920×1080 —
            // оба «16:9», поэтому сверяем с допуском, а не по точным размерам.
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            // Исходник PSD. Основной способ — ссылка на облако: файлы Photoshop
            // регулярно тяжелее прод-лимита загрузки (PHP-FPM upload_max_filesize
            // = 100M). Загрузка — опциональное дополнение для лёгких исходников.
            $table->string('psd_url')->nullable();
            $table->string('psd_disk', 32)->nullable();
            $table->string('psd_path')->nullable();
            $table->string('psd_original_name')->nullable();
            $table->unsignedBigInteger('psd_size')->nullable();

            $table->foreignId('uploaded_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // «Ровно один файл на формат» держит БД, а не проверка в коде:
            // повторная загрузка того же формата ЗАМЕЩАЕТ слот (updateOrCreate),
            // а не заводит вторую строку.
            $table->unique(['course_id', 'format']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_design_assets');
    }
};
