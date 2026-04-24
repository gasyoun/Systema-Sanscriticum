<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use Carbon\Carbon;

final class TemplateRenderer
{
    /**
     * @param  int  $blockSize  Сколько занятий в одном блоке
     */
    public function __construct(
        private readonly int $blockSize = 4,
    ) {}

    /**
     * Рендерит шаблон, возвращая разнесённые по полям title/description/tag.
     *
     * Плейсхолдеры:
     *   {N}      — номер занятия (для отображения)
     *   {DATE}   — дата dd.MM.yy
     *   {TITLE}  — название потока
     *   {BLOCK}  — номер блока (по lessonIndex / blockSize)
     *   {BN}     — номер занятия внутри блока (1..blockSize)
     *
     * Разделитель `|` режет результат на: Название | Описание | Тег.
     *
     * @return array{title: string, description: string, tag: string}
     */
    public function render(
        string $template,
        int $lessonNumber,
        int $lessonIndex,
        Carbon $date,
        string $groupTitle,
    ): array {
        $blockNum = (int) ceil($lessonIndex / $this->blockSize);
        $lessonInBlock = (($lessonIndex - 1) % $this->blockSize) + 1;
        $displayDate = $date->format('d.m.y');

        $rendered = strtr($template, [
            '{N}'     => (string) $lessonNumber,
            '{DATE}'  => $displayDate,
            '{TITLE}' => $groupTitle,
            '{BLOCK}' => (string) $blockNum,
            '{BN}'    => (string) $lessonInBlock,
        ]);

        $parts = array_map('trim', explode('|', $rendered));

        return [
            'title'       => $parts[0] ?? '',
            'description' => $parts[1] ?? '',
            'tag'         => $parts[2] ?? "(#{$lessonNumber}, {$displayDate})",
        ];
    }
}