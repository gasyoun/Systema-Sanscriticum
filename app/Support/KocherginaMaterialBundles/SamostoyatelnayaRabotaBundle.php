<?php

declare(strict_types=1);

namespace App\Support\KocherginaMaterialBundles;

/**
 * Набор «Самостоятельная работа с текстом» (H8210) — данные, проверяемые тестом контракта.
 * Собирается App\Support\KocherginaMaterialBundles::bundles().
 */
class SamostoyatelnayaRabotaBundle
{
    /** Внешние проверенные веб-версии (gasyoun.github.io, HTTP 200, проверено 15-11-2026). */
    private const ZALIZNIAK_WEB = 'https://gasyoun.github.io/SanskritGrammar/grammars/ZalizniakKonspekt_2004/zalizniak-konspekt-2015-11-X_bd_t/';

    private const KOCHERGINA_WEB = 'https://gasyoun.github.io/SanskritGrammar/grammars/KocherginaUchebnik_1998/Kochergina_unicode/';

    /** @return array<string, mixed> */
    public static function bundle(): array
    {
        return [
            'slug' => 'samostoyatelnaya-rabota',
            'title' => 'Самостоятельная работа с текстом',
            'tagline' => 'Инструменты чтения: лигатуры, словари и таблицы — по мере появления тем.',
            'issuance' => 'Лигатуры — при первом чтении сочетаний. Ввод — перед первой электронной работой. '
                .'Словари — перед самостоятельным поиском слов (примерно уроки 6–8). Таблицы — по темам.',
            'readiness' => 'Вы готовы идти дальше, когда сами находите частые лигатуры в тексте, '
                .'проверяете незнакомое слово в словаре и пользуетесь таблицами к текущей теме.',
            'sequence' => [
                'При первом чтении сочетаний пройдите колоду частых лигатур.',
                'До первой электронной работы настройте ввод деванагари на своём устройстве.',
                'С урока самостоятельного чтения проверяйте слова в электронном словаре.',
            ],
            'sections' => [
                'now' => [
                    'title' => 'Сделать сейчас',
                    'items' => [
                        [
                            'name' => 'Колода частых лигатур',
                            'desc' => 'Открытая колода повторения сочетаний знаков по частоте в корпусе.',
                            'status' => 'available',
                            'url' => '/koloda/sanskrit-ligatures-frequency',
                            'version' => 'колода частотных лигатур (2026)',
                            'format' => 'веб-колода SRS',
                        ],
                        [
                            'name' => 'Электронный словарь платформы',
                            'desc' => 'Публичные словарные страницы: поиск слова без входа.',
                            'status' => 'available',
                            'url' => '/slovar',
                            'version' => 'текущий словарный слой платформы',
                            'format' => 'HTML, веб-страница',
                        ],
                    ],
                ],
                'reference' => [
                    'title' => 'Для справки',
                    'items' => [
                        [
                            'name' => 'Таблицы Конспекта: склонение и спряжение',
                            'desc' => 'Основные грамматические таблицы — прямо в разделах Конспекта.',
                            'status' => 'available',
                            'url' => self::ZALIZNIAK_WEB,
                            'version' => 'редакция 2015-11-X (bd_t)',
                            'format' => 'HTML, разделы веб-версии',
                            'anchors' => [
                                ['label' => 'Склонение', 'href' => '#склонение'],
                                ['label' => 'Спряжение', 'href' => '#спряжение'],
                            ],
                        ],
                        [
                            'name' => 'Отдельные грамматические таблицы (файлы)',
                            'desc' => 'Печатные таблицы к занятиям 34–35 и по темам.',
                            'status' => 'blocked',
                            'url' => null,
                            'version' => 'не установлена до импорта',
                            'format' => 'PDF (ожидается)',
                            'blocked_reason' => 'Файлы таблиц не импортированы на платформу. '
                                .'Разблокировка: импорт (аудит H8209) и публикация.',
                        ],
                        [
                            'name' => 'Инструкции ввода деванагари (Windows / Apple)',
                            'desc' => 'Пошаговые инструкции настройки клавиатуры к первой электронной работе.',
                            'status' => 'blocked',
                            'url' => null,
                            'version' => 'не установлена до импорта',
                            'format' => 'PDF (ожидается)',
                            'blocked_reason' => 'Файлы инструкций не импортированы на платформу. '
                                .'Разблокировка: импорт (аудит H8209) и публикация.',
                        ],
                    ],
                ],
                'optional' => [
                    'title' => 'По желанию',
                    'items' => [
                        [
                            'name' => 'Тренажёр чтения деванагари',
                            'desc' => 'Тот же тренажёр с разбором «Макшики» — годится и для тренировки чтения сочетаний.',
                            'status' => 'available',
                            'url' => '/interactive/devanagari-de7107/',
                            'version' => 'devanagari-de7107',
                            'format' => 'HTML-тренажёр, статическая страница',
                        ],
                    ],
                ],
            ],
            'blocked_note' => null,
        ];
    }
}
