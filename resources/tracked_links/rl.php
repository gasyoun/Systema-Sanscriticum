<?php

declare(strict_types=1);

/*
 * rl — автопостинг рилсов (n8n «Рилсы — автопостинг (YT, Rutube, VK)», 21-11-2026):
 * через день в 19:00 МСК один ролик уходит на YouTube Shorts, Rutube и VK Видео,
 * в описании — свой ключ на каждый ролик × площадку: rl-<канал>-<id ролика>.
 * Ведёт на карточку курса, из которого фрагмент; utm_content = rl_<id>,
 * utm_term = тема курса. Карта ниже: страница курса => [тема, id роликов].
 * Источник — catalog.json публикатора рилсов (дубли фрагментов исключены).
 *
 * Пары площадок: yt — та же, что vanons-yt-v1; rt и vkv — новые сёрфейсы
 * (описание ролика на Rutube / в VK Видео).
 */

$rlCampaign = 'reels_autopost_2026';
$rlChannels = [
    'yt' => ['source' => 'youtube_shorts', 'medium' => 'shorts_description'],
    'rt' => ['source' => 'rutube', 'medium' => 'video_description'],
    'vkv' => ['source' => 'vk_video', 'medium' => 'video_description'],
];
$rlCourses = [
    '/k/astronomiia-dlia-astrologov-2-potok-osen-2025' => ['astronomy', [
        'ac001', 'ac002', 'ac003', 'ac004', 'ac005', 'ac006', 'ac087', 'pqr03',
    ]],
    '/k/filosofiya-sankhi' => ['sankhya', [
        'sls01', 'sls02', 'sls03', 'sls04', 'sls05', 'sls06', 'sls07', 'sls08', 'sls09', 'sls10',
    ]],
    '/k/grammatika-gasuns-2026' => ['grammar', [
        'ac011', 'ac012', 'ac095',
    ]],
    '/k/grammatika-po-biulleru-gr27' => ['grammar', [
        'ac007',
    ]],
    '/k/grammatika-po-kocerginoi-gr63' => ['grammar', [
        'ac008', 'ac009', 'ac010', 'ac013', 'ac014', 'ac015',
    ]],
    '/k/hindi-5-vt1500-2026' => ['hindi', [
        'ac016', 'ac017', 'ac086',
    ]],
    '/k/ioga-sutry-patandzali-2-potok-2025-2026' => ['yogasutra', [
        'ac018', 'ac019', 'ac020', 'ac090', 'ac094',
    ]],
    '/k/karaki-po-panini-2025' => ['karaka', [
        'ac021', 'ac022',
    ]],
    '/k/kasmirskii-sivaizm-3-potok-2026' => ['shaivism', [
        'ac023', 'ac084', 'ac089', 'pqr01', 'pqr02', 'pqr04', 'sfr09', 'sfr10', 'sfr11', 'sfr12',
    ]],
    '/k/likbez-po-vedante-2025' => ['vedanta', [
        'ac030', 'ac031', 'ac032', 'ac033',
    ]],
    '/k/lingvistika-v-zadacax-s-elenoi-trefilovoi-2026' => ['linguistics', [
        'ac028', 'ac029',
    ]],
    '/k/logika-2024' => ['logic', [
        'ac034', 'ac035', 'ac036', 'ac037',
    ]],
    '/k/morfologiia-zalizniaka-2025-2026' => ['morphology', [
        'ac040', 'ac041', 'ac042', 'ac043',
    ]],
    '/k/napevnyi-sanskrit-gimn-108-imen-sivy-2025' => ['chanting', [
        'ac044',
    ]],
    '/k/osnovy-aiurvedy-ocno' => ['ayurveda', [
        'ac045', 'ac046', 'ac047', 'ac048', 'ac049', 'ac050', 'ac051', 'ac052', 'ac053', 'ac054',
        'ac055',
    ]],
    '/k/osnovy-indiiskoi-filosofii-2026' => ['philosophy', [
        'ac088',
    ]],
    '/k/osoznannye-snovideniia-nidra' => ['dreams', [
        'ac056',
    ]],
    '/k/prodlenka-sanskrita-pt-9-2026' => ['prodlenka', [
        'ac057', 'ac058', 'ac059',
    ]],
    '/k/rannie-sanskritskie-rukopisi-2026' => ['manuscripts', [
        'ac093',
    ]],
    '/k/sanskritskaia-literatura-2025' => ['literature', [
        'ac060', 'ac061', 'ac062', 'ac063', 'ac064',
    ]],
    '/k/sintaksis-sanskrita' => ['syntax', [
        'ac065', 'ac066', 'ac067', 'ac068', 'ac069', 'ac070', 'ac071', 'ac072', 'ac073', 'ac074',
        'ac075', 'sxr01',
    ]],
    '/k/tolkovaniia-upanisad-2-potok-2026' => ['upanishads', [
        'ac076', 'ac077', 'ac079', 'uwr05', 'uwr06', 'uwr07', 'uwr08',
    ]],
    '/k/yoga-vasistha' => ['yogavasishtha', [
        'ac038', 'ac039', 'yoa01', 'yoe01', 'yoe02', 'yoo01', 'yoy01', 'yvr01',
    ]],
];

foreach ($rlCourses as $rlDestination => [$rlTerm, $rlIds]) {
    foreach ($rlIds as $rlId) {
        foreach ($rlChannels as $rlChannel => $rlPair) {
            $links["rl-{$rlChannel}-{$rlId}"] = [
                'destination' => $rlDestination,
                'utm' => [
                    'utm_source' => $rlPair['source'],
                    'utm_medium' => $rlPair['medium'],
                    'utm_campaign' => $rlCampaign,
                    'utm_content' => "rl_{$rlId}",
                    'utm_term' => $rlTerm,
                ],
            ];
        }
    }
}
