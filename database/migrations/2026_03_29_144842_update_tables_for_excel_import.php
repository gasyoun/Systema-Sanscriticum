<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Обновляем таблицу users (Студенты)
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                // Проверяем каждую колонку перед добавлением
                if (! Schema::hasColumn('users', 'phone')) {
                    $table->string('phone')->nullable()->after('email');
                }
                if (! Schema::hasColumn('users', 'global_status')) {
                    $column = $table->string('global_status')->default('Обычный студент');
                    Schema::hasColumn('users', 'is_admin')
                        ? $column->after('is_admin')
                        : $column->after('email');
                }
                if (! Schema::hasColumn('users', 'note')) {
                    $table->text('note')->nullable()->after('global_status');
                }
            });
        }

        // 2. Обновляем таблицу courses (Курсы)
        if (Schema::hasTable('courses')) {
            Schema::table('courses', function (Blueprint $table) {
                if (! Schema::hasColumn('courses', 'is_elective')) {
                    $column = $table->boolean('is_elective')->default(false);
                    if (Schema::hasColumn('courses', 'is_visible')) {
                        $column->after('is_visible');
                    }
                }
            });
        }

        // 3. Обновляем таблицу payments (Оплаты)
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                if (! Schema::hasColumn('payments', 'start_block')) {
                    $column = $table->integer('start_block')->nullable();
                    if (Schema::hasColumn('payments', 'amount')) {
                        $column->after('amount');
                    }
                }
                if (! Schema::hasColumn('payments', 'end_block')) {
                    $table->integer('end_block')->nullable()->after('start_block');
                }
            });
        }

        // 4. Создаем связующую таблицу (если её еще нет)
        if (Schema::hasTable('users') && Schema::hasTable('courses') && ! Schema::hasTable('course_user')) {
            Schema::create('course_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('course_id')->constrained()->cascadeOnDelete();

                $table->string('status')->default('Записался');
                $table->text('note')->nullable();

                $table->timestamps();
                $table->unique(['user_id', 'course_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('course_user');

        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                $columns = array_filter(['start_block', 'end_block'], fn (string $column): bool => Schema::hasColumn('payments', $column));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('courses') && Schema::hasColumn('courses', 'is_elective')) {
            Schema::table('courses', function (Blueprint $table) {
                $table->dropColumn('is_elective');
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                // Не удаляем phone при откате, так как он был там до нас
                $columns = array_filter(['global_status', 'note'], fn (string $column): bool => Schema::hasColumn('users', $column));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
