<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Реестр благодарностей меценатам (план института N3): публичные имена
 * доноров по явному согласию. Суммы здесь НЕТ и не должно появляться —
 * рулинг MG 23-08: сумму публикуем только по отдельной просьбе конкретного
 * человека; отсутствие колонки сильнее любого UI-правила.
 *
 * payment_id уникален и null-абелен: онлайн-донаты связываются с платежом
 * (идемпотентно, firstOrCreate), офлайн-переводы по реквизитам заводятся
 * вручную через админку с payment_id = null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donation_gratitudes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->nullable()->unique()->constrained('payments')->nullOnDelete();
            $table->string('name_display');
            $table->boolean('is_public')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_gratitudes');
    }
};
