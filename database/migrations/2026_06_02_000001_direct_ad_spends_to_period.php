<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Расход Директа теперь хранится за произвольный период «с — по», а не за месяц.
 * Старые помесячные записи конвертируются в период [начало месяца; конец месяца].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('direct_ad_spends', function (Blueprint $table) {
            $table->date('starts_at')->nullable()->after('id');
            $table->date('ends_at')->nullable()->after('starts_at');
        });

        foreach (DB::table('direct_ad_spends')->whereNotNull('month')->get() as $row) {
            $month = Carbon::parse($row->month);
            DB::table('direct_ad_spends')->where('id', $row->id)->update([
                'starts_at' => $month->copy()->startOfMonth()->toDateString(),
                'ends_at' => $month->copy()->endOfMonth()->toDateString(),
            ]);
        }

        Schema::table('direct_ad_spends', function (Blueprint $table) {
            $table->index('starts_at');
            $table->dropColumn('month');
        });
    }

    public function down(): void
    {
        Schema::table('direct_ad_spends', function (Blueprint $table) {
            $table->date('month')->nullable()->after('id');
        });

        foreach (DB::table('direct_ad_spends')->whereNotNull('starts_at')->get() as $row) {
            DB::table('direct_ad_spends')->where('id', $row->id)->update([
                'month' => Carbon::parse($row->starts_at)->startOfMonth()->toDateString(),
            ]);
        }

        Schema::table('direct_ad_spends', function (Blueprint $table) {
            $table->dropColumn(['starts_at', 'ends_at']);
        });
    }
};
