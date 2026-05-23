<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_promises', function (Blueprint $table) {
            $table->date('actual_paid_at')->nullable()->after('promised_at');
            $table->json('revocation_channels_sent')->nullable()->after('cancelled_at');
            $table->timestamp('revocation_notified_at')->nullable()->after('revocation_channels_sent');
        });
    }

    public function down(): void
    {
        Schema::table('payment_promises', function (Blueprint $table) {
            $table->dropColumn([
                'actual_paid_at',
                'revocation_channels_sent',
                'revocation_notified_at',
            ]);
        });
    }
};
