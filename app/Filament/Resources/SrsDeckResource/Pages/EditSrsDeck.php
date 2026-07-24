<?php

declare(strict_types=1);

namespace App\Filament\Resources\SrsDeckResource\Pages;

use App\Filament\Resources\SrsDeckResource;
use App\Models\Dictionary;
use App\Models\DictionaryWord;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditSrsDeck extends EditRecord
{
    protected static string $resource = SrsDeckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('seedFromDictionary')
                ->label('Засеять из словаря')
                ->icon('heroicon-o-book-open')
                ->color('success')
                ->form([
                    Forms\Components\Select::make('dictionary_id')
                        ->label('Словарь')
                        ->options(fn () => Dictionary::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->nullable()
                        ->helperText('Пусто = все активные слова (как SrsSanskritDeckSeeder).'),
                    Forms\Components\Toggle::make('require_devanagari_or_iast')
                        ->label('Только со скриптом (deva/IAST)')
                        ->default(true),
                ])
                ->action(function (array $data): void {
                    /** @var SrsDeck $deck */
                    $deck = $this->record;
                    $created = $this->seedDeckFromDictionary(
                        $deck,
                        isset($data['dictionary_id']) ? (int) $data['dictionary_id'] : null,
                        (bool) ($data['require_devanagari_or_iast'] ?? true),
                    );

                    Notification::make()
                        ->title("Добавлено карточек: {$created}")
                        ->success()
                        ->send();
                }),

            Actions\Action::make('bulkPaste')
                ->label('Вставить карточки')
                ->icon('heroicon-o-clipboard-document')
                ->form([
                    Forms\Components\Textarea::make('paste')
                        ->label('Строки карточек')
                        ->required()
                        ->rows(12)
                        ->helperText('Одна карточка на строку. Формат: devanagari | iast | cyrillic | translation (разделители: tab, | или ;). Минимум — front и перевод.'),
                ])
                ->action(function (array $data): void {
                    /** @var SrsDeck $deck */
                    $deck = $this->record;
                    $created = $this->importPasteLines($deck, (string) $data['paste']);

                    Notification::make()
                        ->title("Вставлено карточек: {$created}")
                        ->success()
                        ->send();
                }),

            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Idempotent seed: firstOrCreate by (deck_id, source_word_id). Returns count of newly created cards.
     */
    public function seedDeckFromDictionary(SrsDeck $deck, ?int $dictionaryId, bool $requireScript): int
    {
        $query = DictionaryWord::query()
            ->whereNotNull('translation')
            ->where('translation', '!=', '');

        if ($dictionaryId !== null) {
            $query->where('dictionary_id', $dictionaryId);
        }

        if ($requireScript) {
            $query->where(function ($q): void {
                $q->where(function ($q2): void {
                    $q2->whereNotNull('devanagari')->where('devanagari', '!=', '');
                })->orWhere(function ($q2): void {
                    $q2->whereNotNull('iast')->where('iast', '!=', '');
                });
            });
        }

        $created = 0;

        $query->chunkById(200, function ($words) use ($deck, &$created): void {
            foreach ($words as $word) {
                $card = SrsCard::firstOrCreate(
                    ['deck_id' => $deck->id, 'source_word_id' => $word->id],
                    [
                        'direction' => 'front_back',
                        'fields' => [
                            'devanagari' => (string) $word->devanagari,
                            'iast' => (string) $word->iast,
                            'cyrillic' => (string) $word->cyrillic,
                            'translation' => (string) $word->translation,
                        ],
                    ],
                );

                if ($card->wasRecentlyCreated) {
                    $created++;
                }
            }
        });

        return $created;
    }

    /**
     * Parse paste lines into SrsCard rows. Returns count created.
     */
    public function importPasteLines(SrsDeck $deck, string $paste): int
    {
        $lines = preg_split('/\r\n|\r|\n/', $paste) ?: [];
        $created = 0;

        DB::transaction(function () use ($lines, $deck, &$created): void {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                $parts = preg_split('/\t|\s*\|\s*|\s*;\s*/', $line) ?: [];
                $parts = array_map('trim', $parts);
                $parts = array_values(array_filter($parts, fn (string $p): bool => $p !== ''));

                if (count($parts) < 2) {
                    continue;
                }

                // 2 cols → front + translation; 3 → front + iast + translation;
                // 4+ → devanagari | iast | cyrillic | translation
                $fields = match (true) {
                    count($parts) === 2 => [
                        'devanagari' => $parts[0],
                        'iast' => '',
                        'cyrillic' => '',
                        'translation' => $parts[1],
                    ],
                    count($parts) === 3 => [
                        'devanagari' => $parts[0],
                        'iast' => $parts[1],
                        'cyrillic' => '',
                        'translation' => $parts[2],
                    ],
                    default => [
                        'devanagari' => $parts[0],
                        'iast' => $parts[1] ?? '',
                        'cyrillic' => $parts[2] ?? '',
                        'translation' => $parts[3] ?? ($parts[count($parts) - 1] ?? ''),
                    ],
                };

                if ($fields['translation'] === '') {
                    continue;
                }

                SrsCard::create([
                    'deck_id' => $deck->id,
                    'direction' => 'front_back',
                    'fields' => $fields,
                ]);
                $created++;
            }
        });

        return $created;
    }
}
