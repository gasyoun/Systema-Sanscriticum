<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_unreliable')->default(false)->after('global_status');
            $table->text('unreliable_reason')->nullable()->after('is_unreliable');
            $table->timestamp('unreliable_marked_at')->nullable()->after('unreliable_reason');
            $table->foreignId('unreliable_marked_by')
                ->nullable()
                ->after('unreliable_marked_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->boolean('unreliable_auto')->default(false)->after('unreliable_marked_by');
            $table->date('discipline_improved_since')->nullable()->after('unreliable_auto');

            $table->index('is_unreliable');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['unreliable_marked_by']);
            $table->dropIndex(['is_unreliable']);
            $table->dropColumn([
                'is_unreliable',
                'unreliable_reason',
                'unreliable_marked_at',
                'unreliable_marked_by',
                'unreliable_auto',
                'discipline_improved_since',
            ]);
        });
    }
};
