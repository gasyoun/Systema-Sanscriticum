<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H3462: приёмные квитанции входящего email-канала (zabota@samskrte.ru).
 * Само сообщение поддержки — в chat_messages (source='email'); здесь живёт
 * ключ дедупа Message-ID и след судьбы письма (queued → ingested), включая
 * видимую очередь нераспознанных отправителей.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_emails', function (Blueprint $table) {
            $table->id();
            // RFC 5322 Message-ID без <...>; ключ дедупа повторных доставок.
            $table->string('message_id')->unique();
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->string('subject')->nullable();
            $table->longText('text');
            // queued = отправитель не найден по users.email (ручная привязка);
            // ingested = записано в chat_messages, привязка заполнена.
            $table->string('status')->default('queued')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('chat_message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_emails');
    }
};
