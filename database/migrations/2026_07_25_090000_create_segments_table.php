<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GC-A1 segment engine (H1637). Additive, behind the `marketing_segments`
 * flag. A segment is a SAVED FILTER DEFINITION over ranks 1-5 (money/access/
 * lead/stage tables) — this table itself never becomes a write target for
 * money/access logic; see docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md §2.2
 * rank 6 (reporting + marketing is read-only over ranks 1-5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('segments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            // Typed criteria, AND-combined; see App\Models\Segment docblock for
            // the supported {"type": ...} shapes. Empty/null = matches nobody.
            $table->json('criteria')->nullable();
            $table->boolean('is_builtin')->default(false);
            // Stable lookup key for the three wrapped reports (reactivation |
            // debtors | stuck_students) — null for user-authored segments.
            $table->string('builtin_key')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('is_builtin');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('segments');
    }
};
