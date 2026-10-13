<?php

declare(strict_types=1);

/*
 * Публичные короткие ссылки кампаний. Контроллер сохраняет метки в сессии и
 * переводит посетителя на чистый адрес; параметры не показываются в URL.
 */
$campaign = 'grammar_gasuns_autumn_2026';
$channels = [
    'ors' => ['source' => 'telegram_samskrte', 'medium' => 'owned_channel'],
    'mg' => ['source' => 'telegram_marcisgasuns', 'medium' => 'owned_channel'],
    'is' => ['source' => 'telegram_indiaswami', 'medium' => 'paid_post'],
    'it' => ['source' => 'telegram_indiatoday', 'medium' => 'partner_post'],
    'vk' => ['source' => 'vk_senler', 'medium' => 'broadcast'],
    'samskrte' => ['source' => 'samskrte', 'medium' => 'crosslink'],
    'samskrtam' => ['source' => 'samskrtam', 'medium' => 'crosslink'],
];
$links = [];

foreach ($channels as $channel => $attribution) {
    foreach (['s', 'v', 'c', 't', 'h'] as $creative) {
        $links["m26-{$channel}-{$creative}"] = [
            'destination' => '/online/kursy/grammatika-gasuns-2026',
            'utm' => [
                'utm_source' => $attribution['source'],
                'utm_medium' => $attribution['medium'],
                'utm_campaign' => $campaign,
                'utm_content' => "g26_{$creative}",
                'utm_term' => 'beginner',
            ],
        ];
    }
}

$links['m26-schedule-c'] = [
    'destination' => '/online/kursy/grammatika-gasuns-2026',
    'utm' => [
        'utm_source' => 'samskrte_schedule',
        'utm_medium' => 'onsite_cta',
        'utm_campaign' => $campaign,
        'utm_content' => 'g26_schedule',
        'utm_term' => 'beginner',
    ],
];

/*
 * up26 — волна вебинара «Традиции толкования упанишад» (вводное бесплатное
 * 05-10-2026 18:00 МСК): -c — запись на эфир, -p — страница курса с тарифами.
 * Пост канала несёт только /ga/up26-* под человеческой анкор-фразой (H6078).
 */
$up26Campaign = 'upanishady_webinar_oct_2026';
$up26Destinations = [
    'c' => '/webinar-upanishady-2026',
    'p' => '/k/tolkovaniia-upanisad-2-potok-2026',
];

foreach ($up26Destinations as $creative => $up26Destination) {
    $links["up26-ors-{$creative}"] = [
        'destination' => $up26Destination,
        'utm' => [
            'utm_source' => $channels['ors']['source'],
            'utm_medium' => $channels['ors']['medium'],
            'utm_campaign' => $up26Campaign,
            'utm_content' => "u26_{$creative}",
            'utm_term' => 'philosophy',
        ],
    ];
}

/*
 * san26 — волна набора «Философия санкхьи» (курс 459, старт по кворуму
 * ждуна 8 голосов, слот пн 18:00): один читательский маршрут — намерение
 * на карточке курса, поэтому слот -p осознанно выброшен (оплаты до
 * кворума нет). Креативы = гипотезы поста: c — программа, t —
 * преподаватель, h — время/слот.
 */
$san26Campaign = 'sankhya_kurs_oct_2026';

foreach (['c', 't', 'h'] as $creative) {
    $links["san26-ors-{$creative}"] = [
        'destination' => '/k/filosofiia-sankhi',
        'utm' => [
            'utm_source' => $channels['ors']['source'],
            'utm_medium' => $channels['ors']['medium'],
            'utm_campaign' => $san26Campaign,
            'utm_content' => "san26_{$creative}",
            'utm_term' => 'philosophy',
        ],
    ];
}

/*
 * bi26 — бесплатное самостоятельное знакомство с санскритом: отдельный
 * текстовый анонс @samskrte 13-10-2026. Проверка за 500 ₽ закрыта;
 * единственный читательский маршрут ведет на страницу с честными условиями.
 */
$links['bi26-ors-c'] = [
    'destination' => '/online/poprobovat',
    'utm' => [
        'utm_source' => $channels['ors']['source'],
        'utm_medium' => $channels['ors']['medium'],
        'utm_campaign' => 'beginner_intro_oct_2026',
        'utm_content' => 'bi26_c',
        'utm_term' => 'beginner',
    ],
];

/*
 * Демо-кампания — постоянная смок-фикстура скилла anons-attribution-mint
 * (H5934): живые /ga/demo-* ключи, чтобы прогонять редирект + метки без
 * реального набора. Синтетические utm_source помечены demo_, поэтому
 * смок-клики не смешиваются с отчётной атрибуцией. Убирается, когда первая
 * реальная кампания этого скилла уедет в прод.
 */
$demoCampaign = 'attribution_smoke_demo';
$demoChannels = [
    'ors' => ['source' => 'demo_ors', 'medium' => 'owned_channel'],
    'mg' => ['source' => 'demo_mg', 'medium' => 'owned_channel'],
    'is' => ['source' => 'demo_is', 'medium' => 'paid_post'],
    'it' => ['source' => 'demo_it', 'medium' => 'partner_post'],
    'vk' => ['source' => 'demo_vk', 'medium' => 'broadcast'],
    'samskrte' => ['source' => 'demo_samskrte', 'medium' => 'crosslink'],
    'samskrtam' => ['source' => 'demo_samskrtam', 'medium' => 'crosslink'],
];

foreach ($demoChannels as $channel => $demoAttribution) {
    foreach (['s', 'v', 'c', 't', 'h'] as $creative) {
        $links["demo-{$channel}-{$creative}"] = [
            'destination' => '/online',
            'utm' => [
                'utm_source' => $demoAttribution['source'],
                'utm_medium' => $demoAttribution['medium'],
                'utm_campaign' => $demoCampaign,
                'utm_content' => "demo_{$creative}",
                'utm_term' => 'smoke',
            ],
        ];
    }
}

/*
 * zhdun — трекинговые прослойки для deep-links бота на один курс ждуна
 * (H6466/H6467, MG 11-10-2026: «отслеживать кто вообще шёл, пусть и не
 * дошёл»): /ga/zhdun-<slug> → t.me/samskrte_bot?start=wl_<slug>. Каждый
 * клик пишется в anons_link_clicks с utm_content = <slug>, даже если
 * человек бота не открыл. Список курсов синхронизируется с is_listed
 * course_waitlist_items; utm_source помечен zhdun_, чтобы клики прослойки
 * не смешивались с сайт-атрибуцией.
 */
$zhdunCourses = [
    'likbez-po-lingvistike',
    'lingvisticheskie-zadachi',
    'kalligrafiya-devanagari',
    'nachalnyi-sanskrit-litvinenko',
    'gitarthasangraha-abhinavagputy',
    'filosofiya-sankhyi',
    'indiiskii-epos',
    'kashmirskii-shivaizm',
    'putevoditel-po-buddizmu',
    'bxagavadgita-po-rukopisyam',
    'arkhitektura-i-iskusstvo-buddizma',
    'yoga-i-tantra',
    'grammatika-sanskrita-jan2027',
    'kosmografiya',
];

foreach ($zhdunCourses as $zhdunSlug) {
    // H6489 (MG 11-10-2026): ключ = голый <slug> без префикса zhdun- —
    // «чересчур длинные» ссылки; коллизий с m26-/up26-/demo-/story-ключами
    // нет (слаги курсов на эти префиксы не начинаются и story-regex не
    // матчатся).
    $links[$zhdunSlug] = [
        'destination' => 'https://t.me/samskrte_bot?start=wl_'.$zhdunSlug,
        'utm' => [
            'utm_source' => 'zhdun_share',
            'utm_medium' => 'bot_deeplink',
            'utm_campaign' => 'zhdun_waitlist_evergreen',
            'utm_content' => $zhdunSlug,
            'utm_term' => 'waitlist',
        ],
    ];
}

/*
 * vanons — вечнозелёный ключ видео-анонса (H6547): сторис персоны с видео из
 * учебных чатов (лейн H6546) ведут сюда. Один стоящий ключ, не кампания —
 * пер-видео креативы только если MG попросит разбивку атрибуции.
 */
$links['vanons-tg-v1'] = [
    'destination' => '/online',
    'utm' => [
        'utm_source' => 'telegram_story_rusamskrtam',
        'utm_medium' => 'persona_story',
        'utm_campaign' => 'video_anons_evergreen',
        'utm_content' => 'vanons_v1',
        'utm_term' => 'video',
    ],
];

/*
 * H6566 (MG 12-10-2026): «Стать добровольцем» — разметка санскритских
 * словарей Кёльна, вход через закрытую TG-группу. Кнопка ждун-бота ведёт
 * на прослойку /ga/volunteer, чтобы клик считался даже без входа в группу
 * (паттерн H6466/H6489); utm_source помечен zhdun_ — не сайт-атрибуция.
 */
$links['volunteer'] = [
    'destination' => 'https://t.me/+zl3hUJ632t03OGY6',
    'utm' => [
        'utm_source' => 'zhdun_share',
        'utm_medium' => 'bot_deeplink',
        'utm_campaign' => 'zhdun_volunteer_evergreen',
        'utm_content' => 'volunteer',
        'utm_term' => 'volunteer',
    ],
];

/*
 * vol — набор научных добровольцев (H6567, MG 13-10-2026): анонс в
 * «Оповещениях санскритян» ведёт в закрытую TG-группу «Корпус: подготовка
 * текстов» через пер-площадочный ключ — канон атрибуции anons, каждая
 * площадка свой источник (standing volunteer выше остаётся за ждун-ботом).
 * -a = креатив-пост T1-стиля (обновка 👻-поста 367 от 29-01-2025).
 */
$links['vol-ors-a'] = [
    'destination' => 'https://t.me/+zl3hUJ632t03OGY6',
    'utm' => [
        'utm_source' => 'telegram_samskrte',
        'utm_medium' => 'owned_channel',
        'utm_campaign' => 'sanskrit_volunteer_evergreen',
        'utm_content' => 'volunteers_t1',
        'utm_term' => 'volunteer',
    ],
];

return [
    'session_key' => 'tracked_link_attribution',
    'links' => $links,
    'story_campaigns' => [
        'vanons' => [
            'destination' => '/online',
            'utm_campaign' => 'video_anons_evergreen',
            'utm_term' => 'video',
        ],
        'm26' => [
            'destination' => '/online/kursy/grammatika-gasuns-2026',
            'utm_campaign' => $campaign,
            'utm_term' => 'beginner',
        ],
        'demo' => [
            'destination' => '/online',
            'utm_campaign' => $demoCampaign,
            'utm_term' => 'smoke',
        ],
        'lingq' => [
            'destination' => 'https://t.me/samskrtamru/4166',
            'utm_campaign' => 'linguistics_tasks_evergreen',
            'utm_term' => 'puzzle',
        ],
        'linga' => [
            'destination' => 'https://t.me/samskrtamru/4169',
            'utm_campaign' => 'linguistics_tasks_evergreen',
            'utm_term' => 'answer',
        ],
    ],
    'story_accounts' => [
        'mg' => 'telegram_marcisgasuns',
        'rs' => 'telegram_rusamskrtam',
    ],
];
