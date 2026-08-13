<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visualdcs_releases', function (Blueprint $table) {
            $table->id();
            $table->string('release_id', 120)->unique();
            $table->string('contract_version', 32);
            $table->string('manifest_hash', 64);
            $table->string('status', 24); // staged|promoted|rolled_back
            $table->string('storage_path');
            $table->timestamp('promoted_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'promoted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visualdcs_releases');
    }
};
