<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentPromiseSuggestionResource\Pages;

use App\Filament\Resources\PaymentPromiseSuggestionResource;
use Filament\Resources\Pages\ListRecords;

class ListPaymentPromiseSuggestions extends ListRecords
{
    protected static string $resource = PaymentPromiseSuggestionResource::class;
}
