<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * N2/N3 плана Института: донат-леджер «Меценатов» (ст. 582 ГК, свободная сумма,
 * приём через одношотовую ссылку Точки). Аддитивная, обратимая.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institute_donations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->decimal('amount', 10, 2);
            $table->string('status')->default('pending')->index();
            $table->string('donor_name')->nullable();
            $table->string('email');
            // Реестр благодарностей (N3): имя — по согласию, сумма — по отдельной просьбе.
            $table->boolean('publish_name')->default(false);
            $table->boolean('show_amount')->default(false);
            $table->string('tochka_link_id')->nullable();
            $table->string('last_bank_status')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('utm_term')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institute_donations');
    }
};
