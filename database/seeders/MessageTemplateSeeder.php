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
                    'dozhim_step' => $tpl['dozhim_step'] ?? null,
                    'is_active' => true,
                ],
            );
        }
    }

    /** @return list<array{title:string, body:string, category:string, dozhim_step?:string}> */
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
            // Дожим-дрип (H2059, IMPLEMENTATION H-B п.3/5) — 4 заготовки из
            // таблицы NF-кейса. Три первые несут dozhim_step и участвуют в
            // авто-дрипе (day0/day3/day7 по порядку); апселл — без шага,
            // оператор отправляет вручную из библиотеки шаблонов.
            [
                'title' => 'Дожим · День 0 — способы оплаты',
                'body' => "Намасте, {name}!\n\nВидим, что оформление курса «{course}» ещё не завершено оплатой. Если что-то не получилось — доступны разные способы оплаты, включая рассрочку. Продолжить оформление: {pay_link}",
                'category' => MessageTemplate::CATEGORY_DOZHIM,
                'dozhim_step' => MessageTemplate::DOZHIM_STEP_DAY0,
            ],
            [
                'title' => 'Дожим · День 3 — рассрочка и куратор',
                'body' => "Намасте, {name}!\n\nЕсли вопрос в сумме — можно оформить курс «{course}» в рассрочку. А если остались сомнения или вопросы по программе — куратор с радостью ответит лично, просто напишите в этот же чат.\n\nОформить: {pay_link}",
                'category' => MessageTemplate::CATEGORY_DOZHIM,
                'dozhim_step' => MessageTemplate::DOZHIM_STEP_DAY3,
            ],
            [
                'title' => 'Дожим · День 7 — обратная связь',
                'body' => "Намасте, {name}!\n\nЗаметили, что оформление курса «{course}» пока не завершилось оплатой — расскажите, пожалуйста, что смутило или помешало? Это поможет нам сделать курс понятнее, а вам — быстрее принять решение.",
                'category' => MessageTemplate::CATEGORY_DOZHIM,
                'dozhim_step' => MessageTemplate::DOZHIM_STEP_DAY7,
            ],
            [
                'title' => 'Дожим · апсейл смежного продукта',
                'body' => "Намасте, {name}!\n\nПока раздумываете над курсом «{course}» — обратите внимание и на другие наши программы, которые часто берут вместе с ним. Расскажем подробнее, если интересно.\n\nЛичный кабинет: {pay_link}",
                'category' => MessageTemplate::CATEGORY_DOZHIM,
            ],
        ]);
    }
}
