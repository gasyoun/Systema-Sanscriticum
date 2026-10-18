<?php

/*
 |--------------------------------------------------------------------------
 | Learn Your Way (LYW) — flagged lesson-pack serving (H3521)
 |--------------------------------------------------------------------------
 |
 | Персонализированные уроки-паки, сгенерированные build-time генератором
 | SanskritGrammar/scripts/build_lessonpack.py и доставленные на сервер как
 | обычный файловый контент деплоя (никаких runtime API и путей загрузки).
 |
 | ВЫКЛ по умолчанию — рубильник человека: пока LYW_ENABLED=false, маршрут
 | /c/{slug}/u/{id}/learn отдаёт 404, вкладка на странице урока не рендерится,
 | поведение байт-в-байт прежнее. Включение — только после MG sign-off
 | прочитанных паков (GTD @DO row); агент флаг не переворачивает никогда.
 |
 | H6888: sign-off перестал быть одним рубильником. Голосование 14-10-2026
 | одобрило 5 групп из 9 и отклонило 4, поэтому `enabled` ниже — МАСТЕР-
 | выключатель (как и раньше, env, default OFF), а обслуживается ли конкретная
 | группа, решает её собственный флаг в `enabled_groups`.
 |
 | Профиль волны 1: level × interest из параметров запроса с дефолтом
 | «база». Уровень из конфига курса/группы и поле предпочтений студента —
 | wave 2 (отмечено в ARCHITECTURE_LEARN_YOUR_WAY_LESSON_PACKS).
 */

return [

    'enabled' => (bool) env('LYW_ENABLED', false),

    /*
     | Групповые флаги (H6888, GTD row 0UP).
     |
     | Ключ группы — ровно ключ карточки листа голосования
     | `systema-sanscriticum-lyw-zan1-flag-on_9groups` (решения 14-10-2026,
     | review/systema-sanscriticum-lyw-zan1-flag-on_9groups_decisions.json):
     | пара «уровень × интерес», склеенная как `<level>-<interest>`, и `base`,
     | когда любая сторона пары — `base`. Это не новый словарь: то же правило
     | задаёт каталог пака в LessonPackRepository::packDir() (`zan1/base`,
     | `zan1/nol/yoga`, …). Единственный преобразователь пары в ключ —
     | App\Support\LywProfiles::groupKey().
     |
     | Дефолт — OFF для всех: отсутствующий ключ значит OFF, поэтому новый пак,
     | положенный в дерево, не начинает отдаваться без голосования. Группа
     | обслуживается только когда И `enabled` выше, И её флаг здесь = true;
     | все остальные профили сохраняют сегодняшний 404 (без отката в «базу»).
     */
    'enabled_groups' => [

        // --- Одобрено 14-10-2026 (5 из 9): MG прочитал паки, вердикт approve. ---
        'base' => true,
        'nol-ayurveda' => true,
        'nol-kino' => true,
        'nol-palomnichestvo' => true,
        'nol-yoga' => true,

        // --- Отклонено 14-10-2026 (4 из 9): OFF осознанно, это не забывчивость. ---
        // Вердикт: «Перегруз терминологией для занятия 1; вернуть после
        // переработки». Пак из дерева не удалён: ключи оставлены явными, чтобы
        // повторная подача шла через голосование, а не через удачу, и чтобы
        // флип был виден в диффе.
        'prodolzhayushchiy-ayurveda' => false,
        // MG переспросил и сменил вердикт с approve на reject (repeat).
        'prodolzhayushchiy-kino' => false,
        'prodolzhayushchiy-palomnichestvo' => false,
        'prodolzhayushchiy-yoga' => false,
    ],

    /*
     | Корень закоммиченных паков (…/LessonPacks). В проде путь заполняется
     | выкладкой контента; локально и в тестах переопределяется.
     */
    'packs_path' => env('LYW_PACKS_PATH', storage_path('app/lesson-packs')),

    // Схема манифеста, которую умеет читать этот контроллер.
    'schema' => 'lyw-pack-v1',

    'default_zan' => 1,
    'default_level' => 'base',
    'default_interest' => 'base',

    // Словари профилей — зеркалят CONTENT-таблицы генератора.
    'levels' => ['base', 'nol', 'prodolzhayushchiy'],
    'interests' => ['base', 'yoga', 'ayurveda', 'kino', 'palomnichestvo'],

];
