<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImpersonationAuditResource\Pages;

use App\Filament\Resources\ImpersonationAuditResource;
use Filament\Resources\Pages\ListRecords;

class ListImpersonationAudits extends ListRecords
{
    protected static string $resource = ImpersonationAuditResource::class;

    /** Журнал только читается — создавать записи руками нельзя. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
