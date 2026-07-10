<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seed values mirror the current Lead::STATUSES/FINAL_STATUSES constants
     * (app/Models/Lead.php) so existing lead.status rows keep their meaning.
     */
    private const SEED = [
        ['key' => 'new', 'label' => 'Новый', 'sort_order' => 1, 'is_final' => false, 'color' => null],
        ['key' => 'in_work', 'label' => 'В работе', 'sort_order' => 2, 'is_final' => false, 'color' => null],
        ['key' => 'qualified', 'label' => 'Квалифицирован', 'sort_order' => 3, 'is_final' => false, 'color' => null],
        ['key' => 'converted', 'label' => 'Конверсия', 'sort_order' => 4, 'is_final' => true, 'color' => 'success'],
        ['key' => 'rejected', 'label' => 'Отказ', 'sort_order' => 5, 'is_final' => true, 'color' => 'danger'],
    ];

    public function up(): void
    {
        Schema::create('lead_stages', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->unsignedInteger('sort_order')->default(0);
            // Финальные стадии нельзя молча откатить в «Новый» — замена Lead::FINAL_STATUSES.
            $table->boolean('is_final')->default(false);
            $table->string('color')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('lead_stages')->insert(array_map(
            fn (array $row) => $row + ['created_at' => $now, 'updated_at' => $now],
            self::SEED,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_stages');
    }
};
