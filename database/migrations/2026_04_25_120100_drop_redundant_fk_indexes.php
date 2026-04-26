<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Удаляет дубликаты single-column индексов на FK-колонках.
 *
 * Контекст: миграция add_missing_indexes (2026_04_24_200000) ранее
 * создавала $table->index('user_id') и т.п. на колонках, у которых
 * уже был неявный индекс от foreignId()->constrained() (MySQL/InnoDB).
 * MySQL не дедуплицирует и молча создаёт второй B-tree.
 *
 * Эта миграция идемпотентна: если индекс уже отсутствует, drop
 * игнорируется — так покрываются и свежие установки (где исправленная
 * 2026_04_24_200000 дублей уже не создаёт), и установки, где старая
 * версия миграции успела накатиться.
 */
return new class extends Migration
{
    /**
     * @var array<int, array{0: string, 1: string}>
     */
    private array $redundant = [
        ['payments', 'payments_user_id_index'],
        ['payments', 'payments_course_id_index'],
        ['certificates', 'certificates_user_id_index'],
        ['teacher_payouts', 'teacher_payouts_teacher_id_index'],
        ['tariffs', 'tariffs_course_id_index'],
        ['chat_messages', 'chat_messages_user_id_index'],
        ['imports', 'imports_user_id_index'],
    ];

    public function up(): void
    {
        foreach ($this->redundant as [$table, $index]) {
            try {
                Schema::table($table, function (Blueprint $blueprint) use ($index) {
                    $blueprint->dropIndex($index);
                });
            } catch (\Throwable $e) {
                // Индекс отсутствует — это и есть целевое состояние.
            }
        }
    }

    public function down(): void
    {
        // Намеренно не восстанавливаем избыточные индексы.
    }
};
