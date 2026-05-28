<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_promises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->date('promised_at');
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('status', 16)->default('active');
            $table->text('note')->nullable();
            $table->dateTime('fulfilled_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'course_id', 'status']);
            $table->index(['status', 'promised_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_promises');
    }
};
