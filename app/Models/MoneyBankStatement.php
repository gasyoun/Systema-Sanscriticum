<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * H5480 (P3b): импортированный файл выписки зачислений и период, который он
 * покрывает. Уникален по sha256 файла — повторный импорт того же файла не
 * пишет ничего.
 */
class MoneyBankStatement extends Model
{
    public const PERIOD_EXPLICIT = 'explicit';

    public const PERIOD_DERIVED = 'derived';

    public const UPDATED_AT = null;

    protected $table = 'money_bank_statements';

    protected $fillable = [
        'file_hash', 'file_name', 'covers_from', 'covers_to', 'period_source',
        'rows_imported', 'rows_skipped', 'credit_kopecks',
    ];

    protected $casts = [
        'covers_from' => 'date',
        'covers_to' => 'date',
        'rows_imported' => 'integer',
        'rows_skipped' => 'integer',
        'credit_kopecks' => 'integer',
    ];

    public function credits(): HasMany
    {
        return $this->hasMany(MoneyBankStatementCredit::class, 'statement_id');
    }
}
