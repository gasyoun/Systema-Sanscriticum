<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ProductDoc;
use Illuminate\Database\Seeder;

/**
 * Восемь посеянных книг каталога (H3243). firstOrCreate по slug:
 * title/description при повторном прогоне не затираются.
 */
class ProductDocSeeder extends Seeder
{
    /**
     * @var list<array<string, mixed>>
     */
    private const ROWS = [
        [
            'slug' => 'student',
            'title' => 'Как пользоваться кабинетом',
            'description' => 'Гид ученика: вход, урок, ДЗ, словарь, долг.',
            'audience' => 'student',
            'route_name' => 'student.help',
            'url_path' => '/dvaram/help',
            'faq_fragment' => 'часть-iv-частые-вопросы',
            'source_path' => 'docs/STUDENT_CABINET_GUIDE_RU.md',
            'quiz_audience' => 'student',
            'access_gate' => 'catalog',
            'sort_order' => 10,
            'is_active' => true,
            'is_seeded' => true,
        ],
        [
            'slug' => 'teacher',
            'title' => 'Руководство преподавателя',
            'description' => 'Сценарии и справочник разделов панели преподавателя.',
            'audience' => 'teacher',
            'route_name' => 'filament.admin.pages.teacher-guide',
            'url_path' => '/admin/teacher-guide',
            'faq_fragment' => 'частые-вопросы',
            'source_path' => 'docs/TEACHER_CABINET_GUIDE_RU.md',
            'quiz_audience' => 'teacher',
            'access_gate' => 'catalog',
            'sort_order' => 20,
            'is_active' => true,
            'is_seeded' => true,
        ],
        [
            'slug' => 'curator',
            'title' => 'Руководство куратора',
            'description' => 'Обращения, вход без пароля, карточка, ДЗ, группа.',
            'audience' => 'curator',
            'route_name' => 'filament.admin.pages.curator-guide',
            'url_path' => '/admin/curator-guide',
            'faq_fragment' => 'часть-iv-частые-вопросы',
            'source_path' => 'docs/CURATOR_ADMIN_GUIDE_RU.md',
            'quiz_audience' => 'curator',
            'access_gate' => 'catalog',
            'sort_order' => 30,
            'is_active' => true,
            'is_seeded' => true,
        ],
        [
            'slug' => 'accountant',
            'title' => 'Как работать бухгалтеру',
            'description' => 'Проводка, зарплата, расход, штурвал, разметка выплат.',
            'audience' => 'accountant',
            'route_name' => 'filament.admin.pages.accountant-guide',
            'url_path' => '/admin/accountant-guide',
            'faq_fragment' => 'часть-iv-частые-вопросы',
            'source_path' => 'docs/ACCOUNTANT_CABINET_GUIDE_RU.md',
            'quiz_audience' => 'accountant',
            'access_gate' => 'catalog',
            'sort_order' => 40,
            'is_active' => true,
            'is_seeded' => true,
        ],
        [
            'slug' => 'homework',
            'title' => 'Как сдавать домашнее задание',
            'description' => 'Публичная памятка по ДЗ, без входа в кабинет.',
            'audience' => 'student',
            'route_name' => 'faq.dz',
            'url_path' => '/faq/dz',
            'faq_fragment' => '7-частые-вопросы',
            'source_path' => 'docs/STUDENT_HOMEWORK_GUIDE_RU.md',
            'quiz_audience' => null,
            'access_gate' => 'catalog',
            'sort_order' => 50,
            'is_active' => true,
            'is_seeded' => true,
        ],
        [
            'slug' => 'prana',
            'title' => 'Почему баланс праны уменьшился',
            'description' => 'Перк, скидка, перевод, decay — страница в кабинете.',
            'audience' => 'student',
            'route_name' => 'help.prana-balance',
            'url_path' => '/help/prana-balance',
            'faq_fragment' => null,
            'source_path' => null,
            'quiz_audience' => null,
            'access_gate' => 'catalog',
            'sort_order' => 60,
            'is_active' => true,
            'is_seeded' => true,
        ],
        [
            'slug' => 'payout-guide',
            'title' => 'Как размечать выплаты',
            'description' => 'Живая очередь, не книга. Не копирует список платежей.',
            'audience' => 'accountant',
            'route_name' => 'filament.admin.pages.payout-attribution-guide',
            'url_path' => '/admin/payout-attribution-guide',
            'faq_fragment' => null,
            'source_path' => null,
            'quiz_audience' => null,
            'access_gate' => 'catalog',
            'sort_order' => 70,
            'is_active' => true,
            'is_seeded' => true,
        ],
        [
            'slug' => 'important-files',
            'title' => 'Важные файлы',
            'description' => 'Другая полка: Sheets, Drive, регламенты (H2570).',
            'audience' => 'ops',
            'route_name' => 'filament.admin.resources.admin-documents.index',
            'url_path' => '/admin/admin-documents',
            'faq_fragment' => null,
            'source_path' => null,
            'quiz_audience' => null,
            'access_gate' => 'catalog',
            'sort_order' => 80,
            'is_active' => true,
            'is_seeded' => true,
        ],
    ];

    public function run(): void
    {
        foreach (self::ROWS as $row) {
            ProductDoc::firstOrCreate(
                ['slug' => $row['slug']],
                $row,
            );
        }
    }
}
