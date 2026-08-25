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
 | Профиль волны 1: level × interest из параметров запроса с дефолтом
 | «база». Уровень из конфига курса/группы и поле предпочтений студента —
 | wave 2 (отмечено в ARCHITECTURE_LEARN_YOUR_WAY_LESSON_PACKS).
 */

return [

    'enabled' => (bool) env('LYW_ENABLED', false),

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
