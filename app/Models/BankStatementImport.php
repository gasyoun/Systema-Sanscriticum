<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * H5480: один импорт файла банковской выписки (Точка). Append-only — триггеры
 * запрещают UPDATE/DELETE. Период покрытия задаёт человек при выгрузке файла:
 * день считается покрытым только целиком (см. BankStatementControl).
 *
 * @property int $id
 * @property string $file_sha256
 * @property string $file_name
 * @property string $provider
 * @property \Illuminate\Support\Carbon $covers_from
 * @property \Illuminate\Support\Carbon $covers_to
 */
class BankStatementImport extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'bank_statement_imports';

    protected $fillable = [
        'file_sha256', 'file_name', 'provider', 'covers_from', 'covers_to',
        'rows_total', 'rows_imported', 'rows_duplicate', 'rows_skipped',
        'credited_kopecks', 'imported_by',
    ];

    protected $casts = [
        'covers_from' => 'datetime',
        'covers_to' => 'datetime',
        'rows_total' => 'integer',
        'rows_imported' => 'integer',
        'rows_duplicate' => 'integer',
        'rows_skipped' => 'integer',
        'credited_kopecks' => 'integer',
    ];

    /** @return HasMany<BankStatementCredit> */
    public function credits(): HasMany
    {
        return $this->hasMany(BankStatementCredit::class, 'import_id');
    }
}
