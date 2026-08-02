<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Черновик «студент просит отсрочку» из переписки (веб-чат / TG-support).
    // Куратор approve/dismiss; только после approve рождается PaymentPromise.
    // Детектор сам ничего не создаёт в payment_promises — см. H2156.
    public function up(): void
    {
        Schema::create('payment_promise_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type'); // chat_message | telegram_support_message
            $table->unsignedBigInteger('source_id');
            $table->text('detected_text')->nullable();
            $table->date('suggested_date')->nullable();
            $table->decimal('suggested_amount', 10, 2)->nullable();
            $table->json('candidate_course_ids')->nullable();
            $table->float('confidence')->default(0);
            $table->string('status')->default('pending'); // pending|approved|dismissed|expired
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('payment_promise_id')->nullable()->constrained('payment_promises')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source_type', 'source_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_promise_suggestions');
    }
};
