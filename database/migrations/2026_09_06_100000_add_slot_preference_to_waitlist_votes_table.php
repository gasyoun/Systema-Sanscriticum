<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H4206: пожелание времени слота при голосовании (утро/день/вечер).
 * Nullable — голос без пожелания валиден; куратор видит предпочтения
 * в админке при подборе слота (кейс MihailProfiT67: «утром до 11:00»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waitlist_votes', function (Blueprint $table) {
            $table->string('slot_preference', 16)->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('waitlist_votes', function (Blueprint $table) {
            $table->dropColumn('slot_preference');
        });
    }
};
