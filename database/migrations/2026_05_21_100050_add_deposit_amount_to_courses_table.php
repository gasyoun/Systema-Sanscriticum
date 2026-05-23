<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            // Сумма предоплаты («забронировать курс») — индивидуально для курса.
            // null/0 = бронь для этого курса не предлагается, даже если глобальный
            // marketing_settings.deposit_enabled включён.
            $table->decimal('deposit_amount', 10, 2)->nullable()->after('salary_value');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('deposit_amount');
        });
    }
};
