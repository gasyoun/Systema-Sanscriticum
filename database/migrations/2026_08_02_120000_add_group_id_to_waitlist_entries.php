<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H1755 residual: every not-yet-launched group has a stable ID (groups.id + slug
 * while status=forming). Waitlist rows may attach to a specific Group so curators
 * can list people before the group goes active.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->foreignId('group_id')
                ->nullable()
                ->after('intake_id')
                ->constrained('groups')
                ->nullOnDelete();
            $table->index(['group_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_id');
        });
    }
};
