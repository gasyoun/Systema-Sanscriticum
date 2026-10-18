<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Журнал согласий субъектов ПДн (152-ФЗ, ст. 9 ч. 3: доказать получение
     * согласия обязан оператор). Одна строка — одно действие: дал / отозвал
     * согласие определённого типа по определённой версии документа, из
     * определённой формы. Строки только добавляются, никогда не правятся —
     * текущее состояние = последняя строка по (субъект, тип).
     */
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('lead_id')->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('type', 16);    // pd | promo | media | cookies
            $table->string('action', 16);  // given | withdrawn
            $table->string('doc_version', 32)->nullable();
            $table->string('source', 64);  // форма / маршрут
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};
