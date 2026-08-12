<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AdminDocument;
use Illuminate\Database\Seeder;

/**
 * Стартовые строки библиотеки важных файлов (H2570) — идемпотентно
 * (firstOrCreate по source_url).
 *
 * Обновляются только производные поля (host/kind ставит модель); название и
 * описание при повторном прогоне НЕ перезаписываются: MG правит их через
 * AdminDocumentResource, и сидер не вправе затирать человеческую правку.
 *
 * Название таблицы MG дано описательным: заголовок самого Google-документа
 * недоступен без авторизации (файл закрыт), а выдумывать точное имя за
 * владельца хуже, чем дать честное родовое — переименование делается одним
 * полем в форме.
 */
class AdminDocumentSeeder extends Seeder
{
    /**
     * @var list<array<string, mixed>>
     */
    private const ROWS = [
        [
            'source_url' => 'https://docs.google.com/spreadsheets/d/1sHTKEEHa0uIzbna_BaqSNsz2vZiHGBut_pAZMluc0zA/edit?gid=435092428#gid=435092428',
            'title' => 'Рабочая таблица управления (Google Sheets)',
            'description' => 'Основная административная таблица. Ссылка ведёт на конкретную вкладку (gid=435092428).',
            'category' => 'ops',
            'sort_order' => 10,
        ],
    ];

    public function run(): void
    {
        foreach (self::ROWS as $row) {
            AdminDocument::firstOrCreate(
                ['source_url' => $row['source_url']],
                $row,
            );
        }
    }
}
