<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ReactivationTemplate;
use App\Models\MessageTemplate;
use Illuminate\Database\Seeder;

/**
 * Заготовки общей библиотеки шаблонов (H221). Тексты реактивации восстановлены
 * из каталога {@see ReactivationTemplate} («/реактивация-*»); лид/поддержка —
 * минимальные болванки, которые оператор допишет в админке. Идемпотентно:
 * не плодит дубли по title.
 *
 * Поддержка-канреплаи выправлены под домашний регистр (H1876, контракт
 * revenue-copy voice): «вы» со строчной, без восклицаний и «ё» (кроме «всё»),
 * конкретика вместо заверений. Заготовки D/E/F сеются НЕПРИВЯЗАННЫМИ
 * (suggester_category = null): привязка к категории суггестера — решение
 * оператора в админке (S9/H1838), сидер поведение прода не меняет.
 */
class MessageTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $tpl) {
            MessageTemplate::query()->firstOrCreate(
                ['title' => $tpl['title']],
                [
                    'body' => $tpl['body'],
                    'category' => $tpl['category'],
                    'is_active' => true,
                ],
            );
        }
    }

    /** @return list<array{title:string, body:string, category:string}> */
    private function templates(): array
    {
        $reactivation = [];
        foreach (ReactivationTemplate::cases() as $case) {
            $reactivation[] = [
                'title' => $case->command().' · '.$case->label(),
                'body' => "Намасте, {name}!\n\n".$case->whenToUse()
                    ."\n\nЕсли захотите вернуться к курсу «{course}» — я рядом и помогу с любым вопросом.\n\nЛичный кабинет: {pay_link}",
                'category' => MessageTemplate::CATEGORY_REACTIVATION,
            ];
        }

        return array_merge($reactivation, [
            [
                'title' => 'Лид · первый контакт',
                'body' => "Намасте, {name}!\n\nВы оставляли заявку — рад помочь с выбором курса и ответить на вопросы. Когда вам удобно созвониться?",
                'category' => MessageTemplate::CATEGORY_LEAD,
            ],
            [
                'title' => 'Лид · напоминание',
                'body' => "Намасте, {name}!\n\nНапоминаю о себе по вашей заявке на курс «{course}». Остались вопросы — с радостью отвечу.",
                'category' => MessageTemplate::CATEGORY_LEAD,
            ],
            [
                'title' => 'Поддержка · приняли в работу',
                'body' => "Намасте, {name}!\n\nПолучили ваше сообщение и уже разбираемся. Обычно отвечаем в течение рабочего дня.",
                'category' => MessageTemplate::CATEGORY_SUPPORT,
            ],
            // Заготовки под категории FAQ-суггестера D/E/F (H1876). Сеются без
            // привязки (suggester_category = null) — оператор привязывает сам.
            [
                'title' => 'Поддержка · D — оплата и тарифы',
                'body' => "Намасте, {name}!\n\nПришлем точный расчет по вашему тарифу — цена считается персонально, с учетом скидок и рассрочки. Если оплата уже внесена — не платите повторно: мы проверим платеж и откроем доступ.",
                'category' => MessageTemplate::CATEGORY_SUPPORT,
            ],
            [
                'title' => 'Поддержка · E — доступ и кабинет',
                'body' => "Намасте, {name}!\n\nВаш аккаунт привязан к email, на который оформлена оплата, — входите по нему; регистр и пробелы в адресе не важны. Если в кабинет всё равно не пускает — напишите, с какого адреса входите, и мы поправим доступ.\n\nЛичный кабинет: {pay_link}",
                'category' => MessageTemplate::CATEGORY_SUPPORT,
            ],
            [
                'title' => 'Поддержка · F — материалы, ДЗ и сертификаты',
                'body' => "Намасте, {name}!\n\nМатериалы и записи уроков лежат в личном кабинете, раздел «Уроки»; домашние задания — внутри своего урока. Если какого-то материала не хватает — напишите, какого именно, и мы проверим.\n\nЛичный кабинет: {pay_link}",
                'category' => MessageTemplate::CATEGORY_SUPPORT,
            ],
        ]);
    }
}
