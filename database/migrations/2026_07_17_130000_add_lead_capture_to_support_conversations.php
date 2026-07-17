<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H1199 — Jivo-паритет S4: захват лида (телефон/почта) в веб-виджете.
 *
 * Аддитивно и nullable, как visitor_context (H1196): contact_email/contact_phone
 * дублируют то, что уже пишется в leads.email/leads.contact — дёшево показать
 * куратору в Helpdesk без join'а; lead_id — связь с CRM-строкой (реюз паттерна
 * AttributionService::matchLead / newsletter_subscribe H324), а не второй
 * трекинг источника. lead_captured_at — маркер «попытка захвата была» (тред
 * не переспрашивает контакты повторно), по образцу visitor_geo_resolved_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_conversations', function (Blueprint $table) {
            $table->foreignId('lead_id')->nullable()->after('referrer')
                ->constrained('leads')->nullOnDelete();
            $table->string('contact_email')->nullable()->after('lead_id');
            $table->string('contact_phone', 40)->nullable()->after('contact_email');
            $table->timestamp('lead_captured_at')->nullable()->after('contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('support_conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lead_id');
            $table->dropColumn(['contact_email', 'contact_phone', 'lead_captured_at']);
        });
    }
};
