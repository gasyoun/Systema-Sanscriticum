<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Системная колода СРС «Лигатуры санскрита по частотности» (H6393, по
 * паттерну H1280 SrsRootFrequencyDeckSeeder). Content-only: читает
 * committed-фикстуру database/seeders/data/ligatures_frequency.tsv —
 * топ-200 лигатур DCS (public/lila/ligatures/data.js, H1281; kosha
 * "dcs-grapheme-frequency", CC BY 4.0), собранную детерминированным
 * скриптом build_ligatures_frequency_tsv.py. Движок СРС не меняется.
 *
 * Поле `meaning` несёт чтение (IAST) — это ответ карточки (ANSWER_KEYS
 * в CardFace), поэтому IAST ОТДЕЛЬНО в поля НЕ кладём: рендер лица
 * показывает devanagari+iast подряд, и чтение на лице сделало бы MC/typing
 * тривиальными. `confusion` — интерим-близнецы (детерминированное правило
 * скрипта: придыхание / n-ṇ / s-ṣ в одном слоте); когда gold-сет Киселёвой
 * (H6392) ляжет, фикстура перегенерируется, сеятель идемпотентно обновит
 * карточки на месте.
 *
 * Идемпотентно: карточка ключуется по `fields->devanagari`
 * (updateOrCreate). Карточки создаются В ПОРЯДКЕ ЧАСТОТНОСТИ (rank ASC),
 * так что автоинкрементный id совпадает с рангом — очередь новых карточек
 * в ReviewService::queueFor() сортирует `orderBy('id')`, и FSRS подаёт
 * сперва самые частотные лигатуры.
 */
class SrsLigatureFrequencyDeckSeeder extends Seeder
{
    private const DEFAULT_FIXTURE = __DIR__.'/data/ligatures_frequency.tsv';

    public function __construct(
        private readonly string $fixturePath = self::DEFAULT_FIXTURE,
    ) {}

    public function run(): void
    {
        if (! file_exists($this->fixturePath)) {
            throw new RuntimeException(
                'Missing fixture: '.$this->fixturePath.'. Regenerate with '
                .'`python3 database/seeders/data/build_ligatures_frequency_tsv.py`.'
            );
        }

        $noteType = SrsNoteType::firstOrCreate(
            ['key' => 'sanskrit_ligature'],
            [
                'name' => 'Санскрит — лигатура (по частотности)',
                'language' => 'sa',
                'fields' => ['devanagari', 'meaning', 'components', 'confusion', 'rank', 'count', 'pct'],
            ],
        );

        $deck = SrsDeck::firstOrCreate(
            ['slug' => 'sanskrit-ligatures-frequency', 'user_id' => null],
            [
                'note_type_id' => $noteType->id,
                'name' => 'Лигатуры санскрита по частотности',
                'language' => 'sa',
                'visibility' => 'system',
                'description' => 'Системная колода: топ-200 санскритских лигатур в порядке '
                    .'корпусной частотности (DCS, Oliver Hellwig, CC BY 4.0, через lila-фикстуру '
                    .'H1281). Чтение — ответ; близнецы-дестракторы — интерим-правило до gold-сета '
                    .'Киселёвой (H6392).',
            ],
        );

        $rows = $this->readTsv($this->fixturePath);

        // rank ASC гарантирует порядок вставки = порядок частотности.
        usort($rows, fn (array $a, array $b) => ((int) $a['rank']) <=> ((int) $b['rank']));

        $twinned = 0;
        foreach ($rows as $row) {
            if ($row['confusion'] !== '') {
                $twinned++;
            }
            SrsCard::updateOrCreate(
                [
                    'deck_id' => $deck->id,
                    'fields->devanagari' => $row['devanagari'],
                ],
                [
                    'direction' => 'front_back',
                    'fields' => [
                        'devanagari' => $row['devanagari'],
                        'meaning' => $row['iast'],
                        'components' => $row['components'],
                        'confusion' => $row['confusion'],
                        'rank' => (int) $row['rank'],
                        'count' => (int) $row['count'],
                        'pct' => (float) $row['pct'],
                    ],
                ],
            );
        }

        $this->command?->info(sprintf(
            'SrsLigatureFrequencyDeckSeeder: seeded %d ligature cards (%d with confusion twins); '
            .'re-run updates in place, no duplicates.',
            count($rows),
            $twinned,
        ));
    }

    /** @return array<int, array<string, string>> */
    private function readTsv(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $header = str_getcsv((string) array_shift($lines), "\t");

        $rows = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $values = str_getcsv($line, "\t");
            $rows[] = array_combine($header, $values);
        }

        return $rows;
    }
}
