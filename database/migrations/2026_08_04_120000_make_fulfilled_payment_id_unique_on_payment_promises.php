<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Older self-service whole-plan payments linked every covered promise
        // to the same payment. Preserve the explicit lead link (or the oldest
        // promise as a fallback), while fulfilled status remains untouched.
        $duplicates = DB::table('payment_promises')
            ->select('fulfilled_payment_id', DB::raw('MIN(id) as oldest_promise_id'))
            ->whereNotNull('fulfilled_payment_id')
            ->groupBy('fulfilled_payment_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $leadPromiseId = DB::table('payments')
                ->where('id', $duplicate->fulfilled_payment_id)
                ->value('linked_promise_id');

            $keepPromiseId = (int) $duplicate->oldest_promise_id;

            if ($leadPromiseId !== null
                && DB::table('payment_promises')
                    ->where('id', $leadPromiseId)
                    ->where('fulfilled_payment_id', $duplicate->fulfilled_payment_id)
                    ->exists()) {
                $keepPromiseId = (int) $leadPromiseId;
            }

            DB::table('payment_promises')
                ->where('fulfilled_payment_id', $duplicate->fulfilled_payment_id)
                ->where('id', '!=', $keepPromiseId)
                ->update(['fulfilled_payment_id' => null]);
        }

        Schema::table('payment_promises', function (Blueprint $table): void {
            // MySQL unique indexes permit multiple NULL values, which is
            // required for fulfilled instalments covered by a bundled payment.
            $table->unique('fulfilled_payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_promises', function (Blueprint $table): void {
            $table->dropUnique(['fulfilled_payment_id']);
        });
    }
};
