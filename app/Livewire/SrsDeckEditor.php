<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Student private-deck authoring (Wave 2, H1487). Create/rename private decks,
 * add cards one-by-one or via paste bulk. System/public decks are read-only here.
 * Owner may edit the deck slug (shareable URL segment).
 */
class SrsDeckEditor extends Component
{
    /** Path segments reserved under /dvaram/srs/{slug} (static routes). */
    private const RESERVED_SLUGS = ['stats', 'decks'];

    public ?int $deckId = null;

    public string $newDeckName = '';

    /** Editable slug for the currently selected owned deck. */
    public string $editSlug = '';

    public string $cardDevanagari = '';

    public string $cardIast = '';

    public string $cardCyrillic = '';

    public string $cardTranslation = '';

    public string $pasteBulk = '';

    public string $message = '';

    public string $error = '';

    public function mount(?int $deck = null): void
    {
        abort_unless((bool) config('srs.enabled'), 404);

        if ($deck !== null) {
            $owned = $this->ownedDecksQuery()->whereKey($deck)->exists();
            abort_unless($owned, 404);
            $this->deckId = $deck;
        } else {
            $this->deckId = $this->ownedDecksQuery()->value('id');
        }

        $this->syncEditSlugFromCurrentDeck();
    }

    private function ownedDecksQuery()
    {
        return SrsDeck::query()
            ->where('user_id', Auth::id())
            ->where('visibility', 'private')
            ->orderBy('name');
    }

    public function selectDeck(int $deckId): void
    {
        abort_unless($this->ownedDecksQuery()->whereKey($deckId)->exists(), 403);
        $this->deckId = $deckId;
        $this->syncEditSlugFromCurrentDeck();
        $this->clearFlash();
    }

    public function updatedDeckId(mixed $value): void
    {
        if ($value === null || $value === '') {
            $this->editSlug = '';

            return;
        }

        abort_unless($this->ownedDecksQuery()->whereKey((int) $value)->exists(), 403);
        $this->syncEditSlugFromCurrentDeck();
        $this->clearFlash();
    }

    public function createDeck(): void
    {
        $this->clearFlash();
        $name = trim($this->newDeckName);
        if ($name === '') {
            $this->error = 'Введите название колоды.';

            return;
        }

        $noteType = SrsNoteType::firstOrCreate(
            ['key' => 'sanskrit_basic'],
            [
                'name' => 'Санскрит — базовая',
                'language' => 'sa',
                'fields' => ['devanagari', 'iast', 'cyrillic', 'translation'],
            ],
        );

        $deck = SrsDeck::create([
            'user_id' => Auth::id(),
            'note_type_id' => $noteType->id,
            'name' => $name,
            'slug' => 'user-'.Auth::id().'-'.Str::slug($name).'-'.Str::lower(Str::random(4)),
            'language' => 'sa',
            'visibility' => 'private',
            'description' => null,
        ]);

        $this->newDeckName = '';
        $this->deckId = $deck->id;
        $this->editSlug = (string) $deck->slug;
        $this->message = 'Колода создана.';
    }

    /**
     * Owner edits the shareable slug for their private deck
     * (cabinet URL: /dvaram/srs/{slug}).
     */
    public function updateSlug(): void
    {
        $this->clearFlash();
        $deck = $this->currentOwnedDeck();
        if ($deck === null) {
            $this->error = 'Сначала создайте колоду.';

            return;
        }

        $slug = Str::slug(trim($this->editSlug));
        if ($slug === '') {
            $this->error = 'Slug не может быть пустым (латиница, цифры, дефис).';
            $this->editSlug = (string) ($deck->slug ?? '');

            return;
        }

        if (in_array($slug, self::RESERVED_SLUGS, true) || str_starts_with($slug, 'id-')) {
            $this->error = 'Этот slug зарезервирован. Выберите другой.';
            $this->editSlug = (string) ($deck->slug ?? '');

            return;
        }

        $taken = SrsDeck::query()
            ->where('slug', $slug)
            ->where('id', '!=', $deck->id)
            ->exists();
        if ($taken) {
            $this->error = 'Такой slug уже занят другой колодой.';
            $this->editSlug = (string) ($deck->slug ?? '');

            return;
        }

        $deck->slug = $slug;
        $deck->save();
        $this->editSlug = $slug;
        $this->message = 'Адрес колоды обновлён: /dvaram/srs/'.$slug;
    }

    public function deleteDeck(): void
    {
        $this->clearFlash();
        $deck = $this->currentOwnedDeck();
        if ($deck === null) {
            return;
        }

        $deck->delete();
        $this->deckId = $this->ownedDecksQuery()->value('id');
        $this->syncEditSlugFromCurrentDeck();
        $this->message = 'Колода удалена.';
    }

    private function syncEditSlugFromCurrentDeck(): void
    {
        $deck = $this->currentOwnedDeck();
        $this->editSlug = (string) ($deck?->slug ?? '');
    }

    public function addCard(): void
    {
        $this->clearFlash();
        $deck = $this->currentOwnedDeck();
        if ($deck === null) {
            $this->error = 'Сначала создайте колоду.';

            return;
        }

        $translation = trim($this->cardTranslation);
        if ($translation === '') {
            $this->error = 'Нужен перевод.';

            return;
        }

        $front = trim($this->cardDevanagari) !== '' || trim($this->cardIast) !== '' || trim($this->cardCyrillic) !== '';
        if (! $front) {
            $this->error = 'Укажите деванагари, IAST или кириллицу.';

            return;
        }

        SrsCard::create([
            'deck_id' => $deck->id,
            'direction' => 'front_back',
            'fields' => [
                'devanagari' => trim($this->cardDevanagari),
                'iast' => trim($this->cardIast),
                'cyrillic' => trim($this->cardCyrillic),
                'translation' => $translation,
            ],
        ]);

        $this->cardDevanagari = '';
        $this->cardIast = '';
        $this->cardCyrillic = '';
        $this->cardTranslation = '';
        $this->message = 'Карточка добавлена.';
    }

    public function importPaste(): void
    {
        $this->clearFlash();
        $deck = $this->currentOwnedDeck();
        if ($deck === null) {
            $this->error = 'Сначала создайте колоду.';

            return;
        }

        $created = 0;
        $lines = preg_split('/\r\n|\r|\n/', $this->pasteBulk) ?: [];

        DB::transaction(function () use ($lines, $deck, &$created): void {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                $parts = preg_split('/\t|\s*\|\s*|\s*;\s*/', $line) ?: [];
                $parts = array_values(array_filter(array_map('trim', $parts), fn (string $p): bool => $p !== ''));
                if (count($parts) < 2) {
                    continue;
                }

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

        $this->pasteBulk = '';
        $this->message = "Вставлено карточек: {$created}.";
    }

    public function deleteCard(int $cardId): void
    {
        $this->clearFlash();
        $deck = $this->currentOwnedDeck();
        if ($deck === null) {
            return;
        }

        SrsCard::query()
            ->where('deck_id', $deck->id)
            ->whereKey($cardId)
            ->delete();

        $this->message = 'Карточка удалена.';
    }

    private function currentOwnedDeck(): ?SrsDeck
    {
        if ($this->deckId === null) {
            return null;
        }

        return $this->ownedDecksQuery()->whereKey($this->deckId)->first();
    }

    private function clearFlash(): void
    {
        $this->message = '';
        $this->error = '';
    }

    public function render(): View
    {
        $decks = $this->ownedDecksQuery()->withCount('cards')->get();
        $deck = $this->currentOwnedDeck();
        $cards = $deck
            ? $deck->cards()->orderByDesc('id')->limit(100)->get()
            : collect();

        return view('livewire.srs-deck-editor', [
            'decks' => $decks,
            'deck' => $deck,
            'cards' => $cards,
        ]);
    }
}
