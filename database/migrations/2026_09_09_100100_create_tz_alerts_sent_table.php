<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// H4434 — dedup ledger for DST-shift reminders: one row per (user, transition
// date, stage). Without it the daily job would re-send on every run.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tz_alerts_sent', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->date('transition_date'); // local date of the user's clock shift
            $table->string('stage', 16);     // d7 / d1_evening / d1_hour
            $table->timestamp('sent_at')->useCurrent();

            $table->unique(['user_id', 'transition_date', 'stage']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tz_alerts_sent');
    }
};