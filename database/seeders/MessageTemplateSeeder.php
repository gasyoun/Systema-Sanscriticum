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
                'body' => "Намасте, {name}!\n\nПолучили ваше сообщение, разбираемся и скоро вернёмся с ответом. Спасибо за терпение!",
                'category' => MessageTemplate::CATEGORY_SUPPORT,
            ],
        ]);
    }
}
