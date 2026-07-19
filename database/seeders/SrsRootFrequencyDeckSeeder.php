<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Системная колода СРС «Корни санскрита по частотности» (H1280, D4 из
 * PLAN_SYSTEMA_CORPUS_FREQUENCY_LEARNER_SURFACES_2026H2.md). Читает
 * committed-фикстуру database/seeders/data/roots_frequency_ru.tsv — join
 * kosha roots_frequency.json (H950, частотность DCS) x WhitneyRoots
 * crosswalk/ru_root_glosses.tsv (H347, RU-глоссы), уже собранный
 * database/seeders/data/build_roots_frequency_ru.py. Идемпотентно: карточка
 * ключуется по `fields->dcs_lemma` (updateOrCreate), повторный запуск
 * обновляет данные на месте, не дублирует. Карточки создаются В ПОРЯДКЕ
 * ЧАСТОТНОСТИ (rank ASC из фикстуры), так что автоинкрементный id совпадает
 * с рангом — очередь новых карточек в ReviewService::queueFor() сортирует
 * `orderBy('id')`, поэтому FSRS естественно подаёт сперва самые частотные
 * корни без специального поля сортировки.
 */
class SrsRootFrequencyDeckSeeder extends Seeder
{
    private const DEFAULT_FIXTURE = __DIR__.'/data/roots_frequency_ru.tsv';

    private const DEFAULT_UNMATCHED = __DIR__.'/data/roots_frequency_ru_unmatched.tsv';

    public function __construct(
        private readonly string $fixturePath = self::DEFAULT_FIXTURE,
        private readonly ?string $unmatchedPath = self::DEFAULT_UNMATCHED,
    ) {}

    public function run(): void
    {
        if (! file_exists($this->fixturePath)) {
            throw new RuntimeException(
                'Missing fixture: '.$this->fixturePath.'. Regenerate with '
                .'`python database/seeders/data/build_roots_frequency_ru.py` from a checkout '
                .'with kosha and WhitneyRoots as sibling directories.'
            );
        }

        $noteType = SrsNoteType::firstOrCreate(
            ['key' => 'sanskrit_root'],
            [
                'name' => 'Санскрит — корень (по частотности)',
                'language' => 'sa',
                'fields' => ['devanagari', 'iast', 'gloss_ru', 'grammar_class', 'top_form'],
            ],
        );

        $deck = SrsDeck::firstOrCreate(
            ['slug' => 'sanskrit-roots-frequency', 'user_id' => null],
            [
                'note_type_id' => $noteType->id,
                'name' => 'Корни санскрита по частотности',
                'language' => 'sa',
                'visibility' => 'system',
                'description' => 'Системная колода: корни санскрита в порядке корпусной частотности '
                    .'(kosha roots_frequency, H950) с русскими глоссами (WhitneyRoots RU root-gloss '
                    .'layer, H347). Частотность — по данным Digital Corpus of Sanskrit '
                    .'(DCS, Oliver Hellwig), CC BY 4.0.',
            ],
        );

        $rows = $this->readTsv($this->fixturePath);

        // rank ASC гарантирует порядок вставки = порядок частотности.
        usort($rows, fn (array $a, array $b) => ((int) $a['rank']) <=> ((int) $b['rank']));

        foreach ($rows as $row) {
            SrsCard::updateOrCreate(
                [
                    'deck_id' => $deck->id,
                    'fields->dcs_lemma' => $row['dcs_lemma'],
                ],
                [
                    'direction' => 'front_back',
                    'fields' => [
                        'devanagari' => $row['root_devanagari'],
                        'iast' => $row['root_iast'],
                        'gloss_ru' => $row['gloss_ru'],
                        'grammar_class' => $row['grammar_class'],
                        'top_form' => $row['top_form'],
                        'dcs_lemma' => $row['dcs_lemma'],
                        'rank' => (int) $row['rank'],
                    ],
                ],
            );
        }

        $unmatchedCount = ($this->unmatchedPath !== null && file_exists($this->unmatchedPath))
            ? max(0, count(file($this->unmatchedPath, FILE_SKIP_EMPTY_LINES)) - 1)
            : 0;

        $this->command?->info(sprintf(
            'SrsRootFrequencyDeckSeeder: seeded %d root cards; %d roots have no RU gloss match '
            .'(logged, not silently dropped) — see database/seeders/data/roots_frequency_ru_unmatched.tsv',
            count($rows),
            $unmatchedCount,
        ));
    }

    /** @return array<int, array<string, string>> */
    private function readTsv(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $header = str_getcsv(array_shift($lines), "\t");

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
