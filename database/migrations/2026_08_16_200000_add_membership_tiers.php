<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tariffs', function (Blueprint $table): void {
            $table->string('membership_tier', 16)->nullable()->after('membership_months')->index();
        });

        Schema::table('club_memberships', function (Blueprint $table): void {
            $table->string('tier_code', 16)->nullable()->after('payment_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('club_memberships', function (Blueprint $table): void {
            $table->dropIndex(['tier_code']);
            $table->dropColumn('tier_code');
        });

        Schema::table('tariffs', function (Blueprint $table): void {
            $table->dropIndex(['membership_tier']);
            $table->dropColumn('membership_tier');
        });
    }
};
