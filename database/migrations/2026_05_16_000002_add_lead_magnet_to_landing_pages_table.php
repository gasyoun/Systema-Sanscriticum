<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            $table->boolean('lead_magnet_enabled')->default(false)->after('redirect_after_submit_url');
            $table->string('lead_magnet_file_path')->nullable()->after('lead_magnet_enabled');
            $table->string('lead_magnet_title', 255)->nullable()->after('lead_magnet_file_path');
            $table->text('lead_magnet_caption')->nullable()->after('lead_magnet_title');
            $table->string('lead_magnet_default_channel', 20)->default('telegram')->after('lead_magnet_caption');
        });
    }

    public function down(): void
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            $table->dropColumn([
                'lead_magnet_enabled',
                'lead_magnet_file_path',
                'lead_magnet_title',
                'lead_magnet_caption',
                'lead_magnet_default_channel',
            ]);
        });
    }
};
