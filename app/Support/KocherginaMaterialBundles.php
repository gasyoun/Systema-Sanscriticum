<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\KocherginaMaterialBundles\NachaloBundle;
use App\Support\KocherginaMaterialBundles\PervayaShlokaBundle;
use App\Support\KocherginaMaterialBundles\SamostoyatelnayaRabotaBundle;

/**
 * Кочергина: открытые наборы дополнительных материалов по этапам обучения (H8210).
 *
 * Единственный источник правды для страниц /materialy/kochergina/* и реестра URL
 * (docs/MATERIALS_KOCHERGINA_BUNDLES_REGISTRY.md). Контракт набора (согласовано
 * MG 13-11-2026): условие выдачи по теме/готовности, разделы «сделать сейчас» /
 * «для справки» / «по желанию», порядок работы, наблюдаемый признак готовности.
 *
 * Правило блокировок: материал без подтверждённого исходника никогда не получает
 * ссылку — статус blocked + причина + условие разблокировки. Мёртвых ссылок нет.
 * Версия и формат обязательны у каждого файла.
 *
 * Данные наборов разложены по классам-строителям в App\Support\KocherginaMaterialBundles\
 * (лимит токен-гейта на размер файла); URL платформенных маршрутов хранятся строками —
 * при регистрации маршрутов (whereIn slugs()) URL-генератор ещё неприменим.
 *
 * Выдача исторически привязана к темам (Зализняк — проверка урока 2; «Макшика» —
 * читка урока 6; словари — уроки 6–8; таблицы — к 34–35), но на страницах момент
 * определяется готовностью ученика, а не номером занятия.
 */
class KocherginaMaterialBundles
{
    /**
     * Все наборы, ключ — slug маршрута. Порядок = порядок этапов курса.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function bundles(): array
    {
        return [
            'nachalo' => NachaloBundle::bundle(),
            'pervaya-shloka' => PervayaShlokaBundle::bundle(),
            'samostoyatelnaya-rabota' => SamostoyatelnayaRabotaBundle::bundle(),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function bundle(string $slug): ?array
    {
        return self::bundles()[$slug] ?? null;
    }

    /**
     * Slug'и наборов — плоский список без построения bundles(): вызывается
     * при регистрации маршрутов (whereIn), где URL-генератор ещё неприменим
     * (консольные команды не грузят веб-маршруты — route() бросил бы исключение).
     *
     * @return list<string>
     */
    public static function slugs(): array
    {
        return ['nachalo', 'pervaya-shloka', 'samostoyatelnaya-rabota'];
    }

    /** «Макшика» — точный текст первой шлоки (выверен по SRS-колоде, Бётлингк IS 4651). */
    public static function makshika(): array
    {
        return [
            'devanagari' => "मक्षिका व्रणमिच्छन्ति धनमिच्छन्ति पार्थिवाः ।\n"
                .'नीचाः कलहमिच्छन्ति शान्तिमिच्छन्ति साधवः ॥',
            'iast' => "makṣikā vraṇamicchanti dhanamicchanti pārthivāḥ |\n"
                .'nīcāḥ kalahamicchanti śāntimicchanti sādhavaḥ ||',
            'ru' => 'Мухи желают раны, цари — богатства; низкие желают ссоры, праведные — покоя.',
            'source_note' => 'Бётлингк, Indische Sprüche, № 4651; выверенный текст колод SRS (2026).',
        ];
    }
}
