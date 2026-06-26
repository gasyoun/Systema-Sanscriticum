<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prana_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Перк может быть удалён — храним снимок названия/цены в самой записи.
            $table->foreignId('prana_perk_id')->nullable()->constrained()->nullOnDelete();
            $table->string('perk_name');
            $table->unsignedInteger('cost'); // снимок списанной праны
            $table->string('status')->default('pending'); // pending | fulfilled | cancelled
            $table->text('admin_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prana_redemptions');
    }
};
