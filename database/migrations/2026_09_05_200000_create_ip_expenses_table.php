<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Контур «Расходы ИП» (H4188): книга «Расходы по ИП» (Google Sheets)
     * переезжает в LMS-панель. Отдельная от opex (expenses) таблица: книга
     * зеркалит легаси-расходы CRM за Oct'25–May'26 (двойной счёт), слипать
     * нельзя до решения @DECIDE о сверке (AUDIT_BOOKKEEPING_MISERABLE_MAP §2/§3).
     *
     * Валюта сумм — рубли с копейками (как везде); евровые/долларовые строки
     * книги хранят рублёвый эквивалент в amount, а валютную деталь — в fx_note
     * («400 евро PayPal»).
     *
     * Append-only: строки не удаляются (guard в модели + ресурс без Delete);
     * правки категорий/опечаток возможны и пишутся в ip_expense_audits —
     * конвенции payment_audits (H4188, миссия п.1).
     */
    public function up(): void
    {
        Schema::create('ip_expenses', function (Blueprint $table) {
            $table->id();

            // Дата траты. NULL допустим: в книге есть строки без даты
            // (например, «Костина, 607 евро PayPal») — не теряем деньги,
            // оператор проставит дату руками.
            $table->date('spent_at')->nullable()->index();

            // Получатель платежа — колонка «Наименование» книги.
            $table->string('payee');

            // Сумма в рублях с копейками (raw «70 080,00» нормализуется).
            $table->decimal('amount', 12, 2);

            // Валюта суммы amount. Книга ведёт всё в рублях; EUR/USD живут
            // в fx_note. Колонка задел под будущий многоконтурный учёт.
            $table->string('currency', 3)->default('RUB');

            // Валютная деталь строки: «400 евро PayPal», если счёт платили
            // в валюте. NULL для чисто рублёвых строк.
            $table->string('fx_note')->nullable();

            // «С какого счёта была уплачена сумма»: ИП / Гасунс сбер /
            // Поликарпова Альфа / ИП+личная карта Гасунса...
            $table->string('account')->nullable();

            // Статья из App\Enums\IpExpenseCategory.
            $table->string('category')->index();

            // Примечание книги (что оплачено, остатки, контекст).
            $table->string('note')->nullable();

            // Провенанс: вкладка книги-источника («Октябрь 2025»).
            $table->string('source_tab');

            // Идемпотентность импорта (H4188): sha1(tab|date|payee|amount|
            // №вхождения). Вхождение сохраняет легитимные дубликаты строки
            // внутри вкладки — два одинаковых платежа не слипаются.
            $table->string('import_hash')->unique();

            $table->timestamps();

            $table->index(['source_tab', 'spent_at']);
        });

        Schema::create('ip_expense_audits', function (Blueprint $table) {
            // Конвенции payment_audits: append-only лог, переживает удаление
            // строки расхода (без FK), имя админа снимком.
            $table->id();

            $table->unsignedBigInteger('ip_expense_id')->nullable()->index();

            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('admin_name')->nullable();

            // imported | created | updated | deleted
            $table->string('action', 16)->index();

            $table->decimal('amount', 12, 2)->nullable();

            // updated → {field: [old, new]}; created/deleted → снимок полей;
            // imported → снимок + провенанс снапшота книги.
            $table->json('changes')->nullable();

            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_expense_audits');
        Schema::dropIfExists('ip_expenses');
    }
};
