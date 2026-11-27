<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Книжный клуб» → магазин (H8412): заказы покупки книги.
 *
 * ОДНА таблица book_club_orders на обе витрины (инженерное решение хендоффа):
 * mode различает доставку участника кампании (delivery_only), покупку книги
 * (purchase) и самовывоз (pickup) — одна очередь фулфилмента и один
 * Filament-ресурс заказов вместо второй таблицы.
 *
 * planeta_email/planeta_order_number становятся nullable: у покупателя
 * витрины /kupit их нет, поля нужны только доставке участника кампании.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('book_club_orders', function (Blueprint $table) {
            $table->foreignId('book_id')
                ->nullable()
                ->after('user_id')
                ->constrained('books')
                ->nullOnDelete();

            // delivery_only — участник кампании planeta (H8195);
            // purchase — покупка книги с доставкой; pickup — самовывоз.
            $table->string('mode', 16)->default('delivery_only')->after('book_id');

            $table->unsignedSmallInteger('books_count')->nullable()->after('mode');

            // Цена доставки на момент заказа (0 при самовывозе). Сумма всего
            // заказа живёт в book_club_orders.amount (как и раньше).
            $table->decimal('delivery_amount', 10, 2)->nullable()->after('amount');

            // pending → paid → packed → shipped; cancelled — отмена.
            $table->string('status', 24)->default('pending')->after('delivery_amount');
            $table->string('tracking_number', 64)->nullable()->after('status');
            $table->timestamp('shipped_at')->nullable()->after('tracking_number');

            $table->index(['status', 'created_at']);
        });

        Schema::table('book_club_orders', function (Blueprint $table) {
            // Поля кампании нужны только delivery_only-заказам.
            $table->string('planeta_email')->nullable()->change();
            $table->string('planeta_order_number')->nullable()->change();

            // Самовывоз (mode=pickup): адреса нет вовсе — «согласуем время».
            $table->string('country_code', 8)->nullable()->change();
            $table->string('city')->nullable()->change();
            $table->string('postal_code', 32)->nullable()->change();
            $table->string('address_line')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('book_club_orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropConstrainedForeignId('book_id');
            $table->dropColumn(['mode', 'books_count', 'delivery_amount', 'status', 'tracking_number', 'shipped_at']);
        });
    }
};
