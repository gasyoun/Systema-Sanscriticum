<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Обновляем таблицу users (Студенты)
        Schema::table('users', function (Blueprint $table) {
            // Проверяем каждую колонку перед добавлением
            if (!Schema::hasColumn('users', 'phone')) {
                $table->string('phone')->nullable()->after('email');
            }
            if (!Schema::hasColumn('users', 'global_status')) {
                $table->string('global_status')->default('Обычный студент')->after('is_admin');
            }
            if (!Schema::hasColumn('users', 'note')) {
                $table->text('note')->nullable()->after('global_status');
            }
        });

        // 2. Обновляем таблицу courses (Курсы)
        Schema::table('courses', function (Blueprint $table) {
            if (!Schema::hasColumn('courses', 'is_elective')) {
                $table->boolean('is_elective')->default(false)->after('is_visible');
            }
        });

        // 3. Обновляем таблицу payments (Оплаты)
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'start_block')) {
                $table->integer('start_block')->nullable()->after('amount');
            }
            if (!Schema::hasColumn('payments', 'end_block')) {
                $table->integer('end_block')->nullable()->after('start_block');
            }
        });

        // 4. Создаем связующую таблицу (если её еще нет)
        if (!Schema::hasTable('course_user')) {
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

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['start_block', 'end_block']);
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('is_elective');
        });

        Schema::table('users', function (Blueprint $table) {
            // Не удаляем phone при откате, так как он был там до нас
            $table->dropColumn(['global_status', 'note']);
        });
    }
};