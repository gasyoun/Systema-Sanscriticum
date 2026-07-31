<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H1067 marathon landing copy A/B split — records which copy arm (a/b) a
 * Lead saw at capture time, so registration/conversion can be compared per
 * arm. Set once at Lead::create() from the session-sticky assignment in
 * MarathonLandingCopy::resolveArmForRequest(); never updated afterward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('landing_copy_arm', 1)->nullable()->after('landing_page_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('landing_copy_arm');
        });
    }
};
