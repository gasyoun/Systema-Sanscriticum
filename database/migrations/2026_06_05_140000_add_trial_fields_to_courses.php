<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            // Цена пробного занятия; пусто/0 — пробное не продаётся.
            $table->decimal('trial_price', 8, 2)->nullable()->after('deposit_amount');
            // Какой именно урок открывается при покупке пробного.
            $table->foreignId('trial_lesson_id')->nullable()->after('trial_price')
                ->constrained('lessons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trial_lesson_id');
            $table->dropColumn('trial_price');
        });
    }
};
