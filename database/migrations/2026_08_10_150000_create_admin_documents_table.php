<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Библиотека важных файлов админки (H2570) — реестр ссылок на рабочие
 * документы, которые нужны суперадмину и админу: таблицы, регламенты, реестры.
 *
 * Отдельная таблица, а не course_materials: та привязана к course_id (NOT NULL)
 * и видна студенту через /c/{slug}/library за флагом course_library. Здесь
 * ровно наоборот — документы вне курсов и НИКОГДА не видны никому, кроме
 * админоподобных ролей. Втискивать это в course_materials значило бы завести
 * nullable course_id и «служебный» курс-пустышку, а вместе с ними — риск
 * протечки внутреннего документа в студенческую витрину.
 *
 * Файлы не забираем: документ живёт в Google Sheets / Drive / на диске у
 * владельца, у нас — только строка реестра. storage/app не растёт (он целиком
 * попадает в недельный бэкап), права на документ остаются на стороне Google.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_documents', function (Blueprint $table) {
            $table->id();

            $table->string('title');
            $table->text('description')->nullable();

            // Ссылка как её дал человек — дословно, чтобы производные поля
            // можно было пересчитать, если правила нормализации изменятся.
            $table->text('source_url');

            $table->string('host', 190)->nullable();

            // Тип документа: sheet/doc/drive/pdf/... — выводится из URL,
            // человек может переопределить.
            $table->string('kind', 20)->default('link');

            // Раздел библиотеки (финансы, юридическое, обучение...).
            $table->string('category', 32)->default('other');

            $table->integer('sort_order')->default(0);

            // Видна ли строка админу или только суперадмину. По умолчанию
            // false — обычный админ видит документ сразу; сужение до
            // суперадмина делается осознанно, галочкой.
            $table->boolean('super_admin_only')->default(false);

            // В отличие от course_materials, где is_visible=false по умолчанию
            // (куратор публикует ссылку студенту вручную), здесь зритель —
            // сам админ: строку заводят, чтобы ею пользоваться, а не чтобы
            // потом отдельно публиковать.
            $table->boolean('is_active')->default(true);

            $table->foreignId('added_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['is_active', 'category', 'sort_order'], 'admin_documents_shelf_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_documents');
    }
};
