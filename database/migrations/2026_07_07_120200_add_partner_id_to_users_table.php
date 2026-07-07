<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Партнёр (агент), приведший этого клиента. Отдельно от referred_by
            // (студент-реферер) — это разные программы, могут сосуществовать.
            $table->foreignId('partner_id')->nullable()->after('referred_by')
                ->constrained('partners')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_id');
        });
    }
};
