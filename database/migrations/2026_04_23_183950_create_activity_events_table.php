<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
{
    // Партиционирование по RANGE (TO_DAYS(created_at)).
    //
    // ВАЖНО: created_at здесь DATETIME, а не TIMESTAMP. Причина:
    // TIMESTAMP в MySQL хранится в UTC и конвертируется в таймзону сессии при чтении,
    // из-за чего MySQL считает TO_DAYS(timestamp_column) "timezone-dependent"
    // и запрещает его использовать в партиционировании (error 1486).
    // DATETIME хранится as-is — функция детерминирована, партиционирование работает.

    DB::statement("
        CREATE TABLE `activity_events` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `session_id` BIGINT UNSIGNED NULL,
            `event_type` VARCHAR(40) NOT NULL,
            `event_data` JSON NULL,
            `url` VARCHAR(500) NULL,
            `ip_address` VARCHAR(45) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`, `created_at`),

            KEY `idx_user_created` (`user_id`, `created_at`),
            KEY `idx_type_created` (`event_type`, `created_at`),
            KEY `idx_session` (`session_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        PARTITION BY RANGE (TO_DAYS(created_at)) (
            PARTITION p_before_2026_01 VALUES LESS THAN (TO_DAYS('2026-01-01')),
            PARTITION p_2026_01 VALUES LESS THAN (TO_DAYS('2026-02-01')),
            PARTITION p_2026_02 VALUES LESS THAN (TO_DAYS('2026-03-01')),
            PARTITION p_2026_03 VALUES LESS THAN (TO_DAYS('2026-04-01')),
            PARTITION p_2026_04 VALUES LESS THAN (TO_DAYS('2026-05-01')),
            PARTITION p_2026_05 VALUES LESS THAN (TO_DAYS('2026-06-01')),
            PARTITION p_2026_06 VALUES LESS THAN (TO_DAYS('2026-07-01')),
            PARTITION p_2026_07 VALUES LESS THAN (TO_DAYS('2026-08-01')),
            PARTITION p_2026_08 VALUES LESS THAN (TO_DAYS('2026-09-01')),
            PARTITION p_2026_09 VALUES LESS THAN (TO_DAYS('2026-10-01')),
            PARTITION p_2026_10 VALUES LESS THAN (TO_DAYS('2026-11-01')),
            PARTITION p_2026_11 VALUES LESS THAN (TO_DAYS('2026-12-01')),
            PARTITION p_2026_12 VALUES LESS THAN (TO_DAYS('2027-01-01')),
            PARTITION p_future VALUES LESS THAN MAXVALUE
        )
    ");
}

    public function down(): void
    {
        Schema::dropIfExists('activity_events');
    }
};