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
            $table->uuid('installment_group_id')
                ->nullable()
                ->after('fulfilled_payment_id');

            $table->index('installment_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_promises', function (Blueprint $table) {
            $table->dropIndex(['installment_group_id']);
            $table->dropColumn('installment_group_id');
        });
    }
};
