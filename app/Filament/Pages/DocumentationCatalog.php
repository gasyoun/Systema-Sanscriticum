<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\ProductDocResource;
use App\Models\ProductDoc;
use App\Support\ProductDocGitMeta;
use App\Support\ProductDocSearch;
use App\Support\RoleGate;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Указатель живых книг кабинета (H3243). Не рендерит MarkdownGuide.
 */
class DocumentationCatalog extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationLabel = 'Документация';

    protected static ?string $title = 'Документация';

    protected static ?string $slug = 'documentation';

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.documentation-catalog';

    public string $q = '';

    /**
     * @return array<string, array<string, string>>
     */
    protected function queryString(): array
    {
        return [
            'q' => ['except' => ''],
        ];
    }

    public static function canAccess(): bool
    {
        return RoleGate::adminOnly();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::adminOnly();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Добавить')
                ->url(fn (): string => ProductDocResource::getUrl('create'))
                ->visible(fn (): bool => RoleGate::isSuperAdmin()),
        ];
    }

    /**
     * @return Collection<int, ProductDoc>
     */
    public function books(): Collection
    {
        return ProductDoc::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();
    }

    /**
     * @return Collection<int, array{doc: ProductDoc, field: string, heading: string, href: string}>
     */
    public function hits(): Collection
    {
        if (mb_strlen(trim($this->q)) < 2) {
            return collect();
        }

        return ProductDocSearch::search(auth()->user(), $this->q);
    }

    public function isSuperAdmin(): bool
    {
        return RoleGate::isSuperAdmin();
    }

    public function gitDate(ProductDoc $doc): ?string
    {
        if (! $this->isSuperAdmin() || ! is_string($doc->source_path)) {
            return null;
        }

        return ProductDocGitMeta::lastCommitDate($doc->source_path);
    }
}
