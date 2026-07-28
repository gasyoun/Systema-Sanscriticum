<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cabinet_probe_runs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('ran_at');
            $table->boolean('healthy');
            $table->boolean('critical')->default(true);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->json('failures')->nullable();
            $table->string('summary', 500)->nullable();
            $table->timestamps();

            $table->index(['ran_at']);
            $table->index(['healthy', 'ran_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cabinet_probe_runs');
    }
};
