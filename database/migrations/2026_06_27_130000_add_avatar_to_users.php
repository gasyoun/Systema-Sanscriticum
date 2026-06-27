<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Относительный путь к скачанной аватарке в public-диске
            // (storage/app/public/avatars/{id}.jpg). NULL = фото нет/недоступно,
            // показываем инициалы. URL внешних провайдеров (TG/VK) намеренно НЕ
            // храним: TG-ссылка живёт ~1 час, VK-CDN тоже протухает.
            if (! Schema::hasColumn('users', 'avatar_path')) {
                $table->string('avatar_path')->nullable()->after('vk_connected_at');
            }

            // Когда последний раз пытались синхронизировать аватарку — для
            // троттлинга периодического обновления (avatars:sync). NULL = ни разу.
            if (! Schema::hasColumn('users', 'avatar_synced_at')) {
                $table->timestamp('avatar_synced_at')->nullable()->after('avatar_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['avatar_path', 'avatar_synced_at'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
