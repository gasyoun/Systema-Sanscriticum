<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentAuditResource\Pages;

use App\Filament\Resources\PaymentAuditResource;
use Filament\Resources\Pages\ListRecords;

class ListPaymentAudits extends ListRecords
{
    protected static string $resource = PaymentAuditResource::class;

    // Read-only журнал: никаких действий в шапке (создавать записи нельзя).
    protected function getHeaderActions(): array
    {
        return [];
    }
}
