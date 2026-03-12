<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // Добавляем колонку email, если её еще нет
            if (!Schema::hasColumn('leads', 'email')) {
                $table->string('email')->nullable()->after('contact');
            }
            
            // Заодно добавим поля аналитики, если их нет (чтобы два раза не вставать)
            if (!Schema::hasColumn('leads', 'utm_source')) {
                $table->string('utm_source')->nullable();
                $table->string('utm_medium')->nullable();
                $table->string('utm_campaign')->nullable();
                $table->string('utm_content')->nullable();
                $table->string('utm_term')->nullable();
                $table->string('click_id')->nullable();
                $table->string('ip_address')->nullable();
                $table->text('user_agent')->nullable();
                $table->string('referrer')->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn([
                'email', 
                'utm_source', 'utm_medium', 'utm_campaign', 
                'utm_content', 'utm_term', 'click_id', 
                'ip_address', 'user_agent', 'referrer'
            ]);
        });
    }
};