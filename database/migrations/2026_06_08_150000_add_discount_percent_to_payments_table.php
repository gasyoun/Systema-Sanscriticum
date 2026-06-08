<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Процент скидки, применённой при покупке (персональная или лояльность).
            // NULL/0 = без скидки. Фиксируется в момент создания платежа —
            // чтобы пометка не «поплыла» при последующем изменении скидок.
            if (! Schema::hasColumn('payments', 'discount_percent')) {
                $table->decimal('discount_percent', 5, 2)->nullable()->after('amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'discount_percent')) {
                $table->dropColumn('discount_percent');
            }
        });
    }
};
