<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `lead_magnet_default_channel` была создана NOT NULL DEFAULT 'telegram'.
     * Filament Select без required в форме редактирования может прислать NULL,
     * что роняет UPDATE. После введения мульти-канальных кнопок default-канал
     * перестал быть критичным: юзер видит все настроенные каналы и сам выбирает.
     * Сюда теперь допустим NULL, fallback на 'telegram' в LeadController::attachMagnet.
     */
    public function up(): void
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            $table->string('lead_magnet_default_channel', 20)->nullable()->default('telegram')->change();
        });
    }

    public function down(): void
    {
        // Чтобы откат не упал — сначала забекфилить NULL'ы дефолтом.
        \DB::table('landing_pages')->whereNull('lead_magnet_default_channel')
            ->update(['lead_magnet_default_channel' => 'telegram']);

        Schema::table('landing_pages', function (Blueprint $table) {
            $table->string('lead_magnet_default_channel', 20)->nullable(false)->default('telegram')->change();
        });
    }
};
