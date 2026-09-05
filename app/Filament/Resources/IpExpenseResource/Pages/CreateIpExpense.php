<?php

declare(strict_types=1);

namespace App\Filament\Resources\IpExpenseResource\Pages;

use App\Filament\Resources\IpExpenseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateIpExpense extends CreateRecord
{
    protected static string $resource = IpExpenseResource::class;
}
