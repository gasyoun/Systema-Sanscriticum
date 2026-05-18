<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Шифрует существующие plaintext webhook-секреты в marketing_settings.
     * После добавления 'encrypted' cast'а в модели старые plaintext-значения
     * перестали бы расшифровываться (DecryptException).
     *
     * Шаг 1 — расширить колонки до TEXT: Laravel-encrypted 32-символьной строки
     * даёт ~256 chars, в VARCHAR(64) не влезает (Data too long for column).
     *
     * Шаг 2 — идём в обход cast'а через DB facade — читаем plaintext как есть,
     * шифруем, пишем обратно.
     */
    public function up(): void
    {
        $columns = ['tg_webhook_secret', 'vk_callback_secret', 'max_webhook_secret'];

        Schema::table('marketing_settings', function (Blueprint $table) use ($columns) {
            foreach ($columns as $col) {
                $table->text($col)->nullable()->change();
            }
        });

        DB::table('marketing_settings')->orderBy('id')->each(function ($row) use ($columns) {
            $updates = [];

            foreach ($columns as $col) {
                $value = $row->{$col} ?? null;

                if ($value === null || $value === '') {
                    continue;
                }

                // Если уже похоже на шифр Laravel — пропускаем (повторный run миграции).
                $decoded = base64_decode($value, true);
                if ($decoded !== false && str_starts_with($decoded, '{"iv":')) {
                    continue;
                }

                $updates[$col] = Crypt::encryptString($value);
            }

            if (! empty($updates)) {
                DB::table('marketing_settings')->where('id', $row->id)->update($updates);
            }
        });
    }

    public function down(): void
    {
        $columns = ['tg_webhook_secret', 'vk_callback_secret', 'max_webhook_secret'];

        DB::table('marketing_settings')->orderBy('id')->each(function ($row) use ($columns) {
            $updates = [];

            foreach ($columns as $col) {
                $value = $row->{$col} ?? null;

                if ($value === null || $value === '') {
                    continue;
                }

                try {
                    $updates[$col] = Crypt::decryptString($value);
                } catch (\Throwable) {
                    // Уже plaintext — пропускаем.
                }
            }

            if (! empty($updates)) {
                DB::table('marketing_settings')->where('id', $row->id)->update($updates);
            }
        });

        Schema::table('marketing_settings', function (Blueprint $table) use ($columns) {
            foreach ($columns as $col) {
                $table->string($col, 64)->nullable()->change();
            }
        });
    }
};
