<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Content prohibitions — ratified §2.8 list (H5020, MG 16-09-2026)
|--------------------------------------------------------------------------
|
| The four categories a marketing autopilot may NEVER publish, ratified as
| drafted by MG on 16-09-2026 (digital-marketing grill, ruling Q7):
| Uprava docs/ASK_BATCH_CONTENT_FACTORY_TELEGRAM_2026.md §2.8 and
| docs/DECISIONS_DIGITAL_MARKETING_GRILL_16-09-2026.md §2.
|
| Both publishers (content:publish-due → VK via n8n, stories:publish-due →
| @rusamskrtam) run App\Services\Content\ContentProhibitionsChecklist over
| the post text BEFORE the first HTTP call. A `block` hit holds the post
| back for human edit (calendar slot → draft, story_post → draft) with the
| reason journaled; a `warn` hit is published but listed in the Monday
| digest so MG can verify it (standing contract §2: prices, tariff ids and
| live-stream dates are locked — an agent never edits them).
|
| Lists are deliberately conservative and pattern-based — a checklist, not
| a classifier. Widen or narrow them here, never in the publisher code.
*/

return [

    'source' => 'https://github.com/gasyoun/Uprava/blob/main/docs/ASK_BATCH_CONTENT_FACTORY_TELEGRAM_2026.md',
    'ratified_at' => '2026-09-16',

    /*
     | Telegram handles the school itself may name in a post. Any other
     | @handle in a post is treated as a private person (CRM data, §2.8 row 1).
     */
    'allowed_handles' => [
        'rusamskrtam',
        'samskrte',
        'samskrtam',
        'MarcisGasuns',
    ],

    /*
     | Regex rules (u-flag, case-insensitive). `block` = never publish;
     | `warn` = publish, but surface in the digest for the human check.
     */
    'rules' => [
        'crm_personal_data' => [
            'severity' => 'block',
            'label' => '§2.8 · имена учеников / данные CRM / личные диалоги',
            'patterns' => [
                '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',                  // e-mail
                '/(?<!\d)(?:\+7|8)[\s(-]*\d{3}[\s)-]*\d{3}[\s-]*\d{2}[\s-]*\d{2}(?!\d)/u', // RU phone
                '/(*UCP)\b(?:ученик|ученица|студент|студентка)\s+[А-ЯЁ][а-яё]+\s+[А-ЯЁ][а-яё]+/iu', // «ученица Имя Фамилия»
                '/(*UCP)\b(?:из переписки|в личке|написал[аи]? мне|пишет мне)\b/iu',
            ],
        ],
        'politics_religion_guru' => [
            'severity' => 'block',
            'label' => '§2.8 · политика / вероучение / гуру-дискурс',
            'patterns' => [
                '/(*UCP)\b(?:выборы|госдума|президент|партия|санкци|оппозици|митинг)\w*/iu',
                '/(*UCP)\b(?:гуру|духовн\w+ наставник|учитель жизни|просветлени|истинн\w+ вер\w+|вероучени|проповед|секта|сектант)\w*/iu',
            ],
        ],
        'competitors' => [
            'severity' => 'block',
            'label' => '§2.8 · имена конкурентов и сравнения',
            'patterns' => [
                '/(*UCP)\b(?:окаруто|okaruto)\b/iu',
                '/(*UCP)\b(?:лучше|дешевле|в отличие от)\s+(?:чем\s+)?(?:у\s+)?(?:конкурент|друг\w+ школ|остальн\w+ курс)\w*/iu',
            ],
        ],
        'unpublished_research' => [
            'severity' => 'block',
            'label' => '§2.8 · неопубликованные исследования',
            'patterns' => [
                '/(*UCP)\b(?:препринт|preprint|неопубликованн\w+|в печати|under review|submitted to|черновик статьи|ARTICLES\.md)\b/iu',
            ],
        ],
        'price_or_live_date' => [
            'severity' => 'warn',
            'label' => 'контракт §2 · цена / тариф / дата эфира — сверить с расписанием',
            'patterns' => [
                '/\d[\d\s]*(?:₽|руб\.?|рублей|рубля|р\.)(?!\w)/iu',
                '/(*UCP)\b\d{1,2}[.:]\d{2}\s*(?:мск|msk)\b/iu',
                '/(*UCP)\b\d{1,2}\s+(?:января|февраля|марта|апреля|мая|июня|июля|августа|сентября|октября|ноября|декабря)\b/iu',
                '/(*UCP)\bтариф\w*\s*#?\d+/iu',
            ],
        ],
    ],
];
