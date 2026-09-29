<?php

declare(strict_types=1);

namespace App\Support;

/**
 * H5087 (remediation of H5046 games.telemetry.payload.to.shared.system.srs.deck):
 * единый charset-фенс для клиентских строк игровых тренажёров /lila, из которых
 * собирается ОБЩАЯ системная SRS-колода «Слова из тренажёров» и публичный
 * словарь. Разрешены только буквы/цифры Unicode, диакритика и сочетательные
 * знаки (вирама и пр., \p{M} — лигатуры деванагари), пробел, дефис и
 * апостроф — то, чем бывают леммы IAST и русские переводы; формульные и
 * разметочные символы (= + @ " ' < > ` | { } [ ] \ : / и т.п.) вырезаются
 * ещё на приёме и повторно на границе записи в общие поверхности (на случай
 * payload-рядов, записанных до фенса).
 */
class GameLemmaFence
{
    public static function clean(string $value): string
    {
        $clean = preg_replace('/[^\p{L}\p{M}\p{N}\h\'-]/u', '', $value) ?? '';

        return trim($clean);
    }
}
