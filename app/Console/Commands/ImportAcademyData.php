<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Teacher;
use App\Models\Course;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB; // <-- Добавили для сверхбыстрых запросов к БД

class ImportAcademyData extends Command
{
    protected $signature = 'import:academy';
    protected $description = 'Пошаговый импорт данных из старой базы Excel';

    public function handle()
    {
        $this->info('Добро пожаловать в мастер импорта Академии!');
        
        $choice = $this->choice(
            'Что будем импортировать сейчас?',
            [
                '1. Преподаватели (готово)', 
                '2. Курсы, Тарифы и Группы (готово)', 
                '3. Блоки (пока пропустим)', 
                '4. Студенты (готово)', 
                '5. Оплаты и Доступы', 
                'Выход'
            ],
            4 // По умолчанию теперь выбран пункт 5
        );

        if (str_starts_with($choice, '1')) {
            $this->importTeachers();
        } elseif (str_starts_with($choice, '2')) {
            $this->importCourses();
        } elseif (str_starts_with($choice, '4')) {
            $this->importStudents();
        } elseif (str_starts_with($choice, '5')) {
            $this->importPayments(); // <-- ДОБАВИЛИ ВЫЗОВ
        } elseif ($choice === 'Выход') {
            $this->info('Импорт отменен.');
        } else {
            $this->warn('Этот раздел мы напишем на следующем шаге!');
        }
    }

    // ==========================================
    // ЛОГИКА ИМПОРТА ОПЛАТ И ДОСТУПОВ
    // ==========================================
    private function importPayments()
    {
        $absolutePath = storage_path('app/imports/payments.csv');

        if (!file_exists($absolutePath)) {
            $this->error("Файл не найден! Положи payments.csv сюда: " . $absolutePath);
            return;
        }

        $this->info("Оптимизируем кэш (читаем студентов и курсы в память)...");
        // Берем все ID в память, чтобы не делать 30 000 запросов к БД!
        $users = \App\Models\User::pluck('id', 'name')->toArray();
        $courses = \App\Models\Course::pluck('id', 'title')->toArray();
        $groups = \App\Models\Group::pluck('id', 'name')->toArray();

        $this->info("Читаем файл оплат (11 000+ строк) и выдаем доступы...");
        $file = fopen($absolutePath, 'r');
        
        $countPayments = 0;
        $countSkips = 0;
        $countExpenses = 0;
        $firstRow = true;

        $cleanNumber = function ($value) {
            $value = str_replace(',', '.', trim($value)); 
            $value = preg_replace('/[^\d.-]/', '', $value); // Оставляем МИНУС для распознавания расходов
            return $value === '' ? 0 : (float)$value;
        };

        $paymentsBatch = [];
        $groupUserBatch = [];
        $courseUserBatch = [];

        while (($row = fgetcsv($file, 10000, ",")) !== FALSE) {
            if ($firstRow) { $firstRow = false; continue; }

            $studentName = trim($row[1] ?? ''); // Колонка B: Студент
            $courseTitle = trim($row[2] ?? ''); // Колонка C: Курс
            
            if (empty($studentName) || empty($courseTitle)) continue;

            $amount = $cleanNumber($row[5] ?? 0); // Колонка F: Оплата
            $startBlock = (int)$cleanNumber($row[3] ?? 0); 
            $endBlock = (int)$cleanNumber($row[4] ?? 0); 
            $dateRaw = trim($row[6] ?? ''); 
            $statusRaw = trim($row[8] ?? ''); 
            $note = trim($row[9] ?? ''); 

            // Парсим дату
            $parsedDate = now();
            if (!empty($dateRaw)) {
                try {
                    $parsedDate = \Carbon\Carbon::parse($dateRaw);
                } catch (\Exception $e) { }
            }

            // ==========================================
            // ЛОГИКА ДЛЯ РАСХОДОВ И ВОЗВРАТОВ (МИНУСОВЫЕ СУММЫ)
            // ==========================================
            if ($amount < 0) {
                // Пытаемся найти студента (вдруг это реальный возврат)
                $userId = $users[$studentName] ?? null;
                $courseId = $courses[$courseTitle] ?? null;

                // Если это абстрактный расход (Реклама, Банк и т.д.), создаем технический профиль
                if (!$userId) {
                    $sysUser = \App\Models\User::firstOrCreate(
                        ['email' => 'expenses@samskrte.ru'],
                        ['name' => 'Системные расходы', 'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(10))]
                    );
                    $userId = $sysUser->id;
                    $users['Системные расходы'] = $userId; // Кешируем, чтобы не создавать дважды
                }

                if (!$courseId) {
                    $sysCourse = \App\Models\Course::firstOrCreate(
                        ['title' => 'Прочие затраты (Технический)'],
                        ['slug' => 'system-expenses', 'is_visible' => false]
                    );
                    $courseId = $sysCourse->id;
                    $courses['Прочие затраты (Технический)'] = $courseId; 
                }

                // Информацию о том, на что ушел расход, сохраним в поле Транзакции, чтобы видеть в админке
                $expenseDetails = mb_substr($studentName . ' - ' . $courseTitle, 0, 250);

                $paymentsBatch[] = [
                    'user_id' => $userId,
                    'course_id' => $courseId,
                    'amount' => $amount,
                    'tariff' => 'Расход', // В таблице будет красиво написано "Расход"
                    'status' => 'paid', // Транзакция проведена (с минусом)
                    'start_block' => null,
                    'end_block' => null,
                    'transaction_id' => $expenseDetails, 
                    'created_at' => $parsedDate,
                    'updated_at' => $parsedDate,
                ];
                $countExpenses++;
                
                // ВАЖНО: Пропускаем выдачу доступов и идем к следующей строке!
                continue; 
            }

            // ==========================================
            // ЛОГИКА ДЛЯ ОБЫЧНЫХ ПОЛОЖИТЕЛЬНЫХ ОПЛАТ
            // ==========================================
            $userId = $users[$studentName] ?? null;
            $courseId = $courses[$courseTitle] ?? null;

            if (!$userId || !$courseId) {
                $countSkips++;
                continue;
            }

            // --- НОВАЯ ЛОГИКА РАЗДЕЛЕНИЯ ОПЛАТ ПО БЛОКАМ ---
            if ($startBlock > 0) {
                // Если конечный блок не указан или меньше начального, приравниваем их
                $endBlock = ($endBlock >= $startBlock) ? $endBlock : $startBlock;
                
                // Считаем, сколько блоков купили разом
                $blocksCount = $endBlock - $startBlock + 1;
                
                // Делим сумму на количество блоков (например, 15000 / 3 = 5000)
                $amountPerBlock = $blocksCount > 0 ? round($amount / $blocksCount, 2) : $amount;

                for ($i = $startBlock; $i <= $endBlock; $i++) {
                    $paymentsBatch[] = [
                        'user_id' => $userId,
                        'course_id' => $courseId,
                        'amount' => $amountPerBlock,
                        'tariff' => 'block_' . $i,
                        'status' => 'paid',
                        'start_block' => $i,
                        'end_block' => $i,
                        // Если это мульти-оплата, оставляем пометку в транзакции
                        'transaction_id' => ($blocksCount > 1) ? "Мульти-оплата (Блоки {$startBlock}-{$endBlock})" : null,
                        'created_at' => $parsedDate,
                        'updated_at' => $parsedDate,
                    ];
                    $countPayments++;
                }
            } else {
                // Весь курс целиком (Если start_block = 0 или пуст)
                $paymentsBatch[] = [
                    'user_id' => $userId,
                    'course_id' => $courseId,
                    'amount' => $amount,
                    'tariff' => 'full',
                    'status' => 'paid',
                    'start_block' => null,
                    'end_block' => null,
                    'transaction_id' => null,
                    'created_at' => $parsedDate,
                    'updated_at' => $parsedDate,
                ];
                $countPayments++;
            }
            
            // ==========================================
            // РАСКИДЫВАЕМ ДОСТУПЫ СТУДЕНТАМ
            // ==========================================

            // ==========================================
            // РАСКИДЫВАЕМ ДОСТУПЫ СТУДЕНТАМ
            // ==========================================
            
            // 1. Учебная группа
            $groupId = $groups[$courseTitle] ?? null;
            if ($groupId) {
                $groupUserBatch[$userId . '_' . $groupId] = [
                    'user_id' => $userId,
                    'group_id' => $groupId,
                ];
            }

            // 2. Статус студента на курсе (Записался / Льготник и тд)
            $mappedStatus = 'Записался';
            $lowerStatus = mb_strtolower($statusRaw);
            
            if (str_contains($lowerStatus, 'льготник')) $mappedStatus = 'Льготник';
            elseif (str_contains($lowerStatus, 'покинул')) $mappedStatus = 'Покинул';
            elseif (str_contains($lowerStatus, 'исключен')) $mappedStatus = 'Исключен';
            elseif (str_contains($lowerStatus, 'рассрочка')) $mappedStatus = 'Рассрочка';
            elseif (str_contains($lowerStatus, 'приостановка')) $mappedStatus = 'Приостановка';
            elseif (str_contains($lowerStatus, 'вернулся')) $mappedStatus = 'Вернулся';
            elseif (str_contains($lowerStatus, 'выпускник')) $mappedStatus = 'Выпускник';

            // Оставляем только самую последнюю запись статуса для курса
            $courseUserBatch[$userId . '_' . $courseId] = [
                'user_id' => $userId,
                'course_id' => $courseId,
                'status' => $mappedStatus,
                'note' => mb_substr($note, 0, 65000), // Защита от слишком длинных текстов
            ];

            // Загружаем в БД пачками по 1000 строк (сверхскорость)
            if (count($paymentsBatch) >= 1000) {
                \Illuminate\Support\Facades\DB::table('payments')->insert($paymentsBatch);
                $paymentsBatch = [];
            }
        }

        // Загружаем остатки оплат
        if (!empty($paymentsBatch)) {
            \Illuminate\Support\Facades\DB::table('payments')->insert($paymentsBatch);
        }

        fclose($file);

        $this->info("Оплаты загружены! Синхронизируем доступы в кабинеты...");

        // Раздаем доступы к группам
        if (!empty($groupUserBatch)) {
            foreach (array_chunk($groupUserBatch, 500) as $chunk) {
                \Illuminate\Support\Facades\DB::table('group_user')->insertOrIgnore($chunk);
            }
        }

        // Заполняем статусы "Обучается на курсах"
        foreach ($courseUserBatch as $link) {
            $user = \App\Models\User::find($link['user_id']);
            if ($user) {
                $user->courses()->syncWithoutDetaching([
                    $link['course_id'] => [
                        'status' => $link['status'],
                        'note' => $link['note']
                    ]
                ]);
            }
        }

        $this->info("✅ Успешно перенесено положительных оплат: {$countPayments}");
        $this->info("📉 Записано расходов / возвратов: {$countExpenses}");
        if ($countSkips > 0) {
            $this->warn("⚠️ Пропущено строк из-за несовпадений ФИО/Курса: {$countSkips}");
            $this->warn("  (Обычно это оплаты за курсы или консультации, которых нет в таблице 'Курсы')");
        }
    }

    // ==========================================
    // ЛОГИКА ИМПОРТА СТУДЕНТОВ
    // ==========================================
    private function importStudents()
    {
        $absolutePath = storage_path('app/imports/students.csv');

        if (!file_exists($absolutePath)) {
            $this->error("Файл не найден! Положи students.csv сюда: " . $absolutePath);
            return;
        }

        $this->info("Читаем файл студентов и создаем профили...");
        $file = fopen($absolutePath, 'r');
        
        $count = 0;
        $firstRow = true;

        while (($row = fgetcsv($file, 10000, ",")) !== FALSE) {
            if ($firstRow) { $firstRow = false; continue; }

            $name = trim($row[2] ?? ''); // Колонка C: Уникальное имя (ФИО)
            
            // Защита от пустых строк в конце файла
            if (empty($name)) continue;

            $telegram = trim($row[3] ?? '');  // Колонка D: Ник телеграм
            $phone = trim($row[4] ?? '');     // Колонка E: Телефон
            $email = trim($row[5] ?? '');     // Колонка F: Email
            $vk = trim($row[6] ?? '');        // Колонка G: VK
            $statusRaw = trim($row[9] ?? ''); // Колонка J: Статус
            $note = trim($row[11] ?? '');     // Колонка L: Примечание

            // Валидация статуса (если написано что-то странное, ставим Обычный)
            $validStatuses = ['Обычный студент', 'Техподдержка', 'VIP', 'Занимается бесплатно', 'Бартер'];
            $status = in_array($statusRaw, $validStatuses) ? $statusRaw : 'Обычный студент';

            // Ищем, есть ли уже такой студент в базе
            $existingUser = User::where('name', $name)->first();

            // Если email пустой и студента еще нет - генерируем заглушку
            if (empty($email)) {
                if ($existingUser) {
                    $email = $existingUser->email; // Оставляем старый, чтобы не затирать
                } else {
                    $email = Str::slug($name) . '_' . rand(1000, 9999) . '@no-email.com';
                }
            }

            // Генерируем случайный пароль для новых пользователей
            $password = $existingUser ? $existingUser->password : Hash::make(Str::random(10));

            // ==========================================
            // НОВОЕ: Умное склеивание примечания
            // ==========================================
            $combinedNoteParts = [];
            if (!empty($telegram)) {
                $combinedNoteParts[] = "Telegram: " . $telegram;
            }
            if (!empty($vk)) {
                $combinedNoteParts[] = "VK: " . $vk;
            }
            if (!empty($note)) {
                $combinedNoteParts[] = "Комментарий: " . $note;
            }
            // Склеиваем с переносом строки, если пусто - будет пустая строка
            $finalNote = implode("\n", $combinedNoteParts);


            // Сохраняем в базу (без автоматической отправки писем, так как мы обходим контроллеры!)
            User::updateOrCreate(
                ['name' => $name], // Ищем строго по ФИО
                [
                    'email' => $email,
                    'phone' => $phone,
                    // Убрали telegram_id и vk_id, теперь всё лежит в note
                    'global_status' => $status,
                    'note' => $finalNote,
                    'password' => $password,
                ]
            );
            
            $count++;
        }

        fclose($file);

        $this->info("✅ Успешно импортировано студентов: {$count}");
    }

    // ==========================================
    // ЛОГИКА ИМПОРТА ПРЕПОДАВАТЕЛЕЙ
    // ==========================================
    private function importTeachers()
    {
        $absolutePath = storage_path('app/imports/teachers.csv');

        if (!file_exists($absolutePath)) {
            $this->error("Файл не найден! Скрипт искал его прямо здесь: " . $absolutePath);
            return;
        }

        $this->info("Читаем файл преподавателей...");
        $file = fopen($absolutePath, 'r');
        $count = 0;
        $firstRow = true;

        while (($row = fgetcsv($file, 1000, ",")) !== FALSE) {
            if ($firstRow) { $firstRow = false; continue; }

            $name = trim($row[1] ?? ''); 
            $bio = trim($row[2] ?? '');  

            if (!empty($name)) {
                Teacher::updateOrCreate(['name' => $name], ['bio' => $bio]);
                $count++;
            }
        }
        fclose($file);
        $this->info("✅ Успешно импортировано преподавателей: {$count}");
    }

    // ==========================================
    // ЛОГИКА ИМПОРТА КУРСОВ И ТАРИФОВ
    // ==========================================
    private function importCourses()
    {
        $absolutePath = storage_path('app/imports/courses.csv');

        if (!file_exists($absolutePath)) {
            $this->error("Файл не найден! Положи courses.csv сюда: " . $absolutePath);
            return;
        }

        $this->info("Читаем файл курсов и генерируем тарифы...");
        $file = fopen($absolutePath, 'r');
        
        $coursesCount = 0;
        $tariffsCount = 0;
        $firstRow = true;

        // СУПЕР-УМНАЯ ЧИСТКА ЦИФР
        $cleanNumber = function ($value) {
            $value = str_replace(',', '.', trim($value)); 
            
            if (str_contains($value, '/')) {
                $value = explode('/', $value)[0];
            }
            
            $value = preg_replace('/[^\d.]/', '', $value); 
            return $value === '' ? 0 : (float)$value;
        };

        while (($row = fgetcsv($file, 1000, ",")) !== FALSE) {
            if ($firstRow) { $firstRow = false; continue; }

            $title = trim($row[1] ?? ''); 
            if (empty($title)) continue;

            $teacherName = trim($row[2] ?? ''); 
            
            // Вот здесь применяем нашу функцию очистки
            $blocksCount = (int)$cleanNumber(explode('/', $row[5] ?? '0')[0]); 
            $blockPrice = $cleanNumber(explode('/', $row[7] ?? '0')[0]); 
            $fullPrice = $cleanNumber(explode('/', $row[8] ?? '0')[0]); 
            
            $salaryRaw = mb_strtolower(trim($row[10] ?? '')); 
            $salaryType = 'percent'; 
            $salaryValue = 0;

            if ($salaryRaw !== '') {
                $salaryValue = $cleanNumber($salaryRaw); 
                if (str_contains($salaryRaw, 'руб')) {
                    $salaryType = 'fix_total';
                }
            }

            // УМНЫЙ ПОИСК ПРЕПОДАВАТЕЛЯ (Мягкое совпадение)
            $teacherId = null;
            if (!empty($teacherName)) {
                $primaryTeacherName = trim(explode(',', $teacherName)[0]); 
                $teacher = \App\Models\Teacher::where('name', 'LIKE', '%' . $primaryTeacherName . '%')->first();
                
                if ($teacher) {
                    $teacherId = $teacher->id;
                } else {
                    $this->warn("Внимание: Преподаватель '{$primaryTeacherName}' не найден для курса '{$title}'");
                }
            }

            // Создаем курс
            $course = \App\Models\Course::updateOrCreate(
                ['title' => $title],
                [
                    'slug' => \Illuminate\Support\Str::slug($title) ?: \Illuminate\Support\Str::random(10),
                    'teacher_id' => $teacherId,
                    'lessons_count' => $blocksCount > 0 ? $blocksCount * 4 : 12, 
                    'salary_type' => $salaryType,
                    'salary_value' => $salaryValue,
                    'is_visible' => true,
                    'is_elective' => false,
                ]
            );
            $coursesCount++;
            
            // ==========================================
            // НОВОЕ: АВТОМАТИЧЕСКОЕ СОЗДАНИЕ ГРУППЫ
            // ==========================================
            // Создаем группу с точно таким же названием, как у курса (если ее еще нет)
            $group = \App\Models\Group::firstOrCreate(
                ['name' => $title]
            );
            
            // Намертво привязываем этот курс к созданной учебной группе
            $course->groups()->syncWithoutDetaching([$group->id]);
            // ==========================================

            // Тариф: Полный курс
            if ($fullPrice > 0) {
                \App\Models\Tariff::updateOrCreate(
                    [
                        'course_id' => $course->id,
                        'type' => 'full',
                    ],
                    [
                        'title' => 'Весь курс целиком',
                        'price' => $fullPrice,
                        'is_active' => true,
                    ]
                );
                $tariffsCount++;
            }

            // Тарифы: Отдельные блоки
            if ($blockPrice > 0) {
                if ($blocksCount > 0) {
                    for ($i = 1; $i <= $blocksCount; $i++) {
                        \App\Models\Tariff::updateOrCreate(
                            [
                                'course_id' => $course->id,
                                'type' => 'block',
                                'block_number' => $i,
                            ],
                            [
                                'title' => "Блок {$i}",
                                'price' => $blockPrice,
                                'is_active' => true,
                            ]
                        );
                        $tariffsCount++;
                    }
                } else {
                    \App\Models\Tariff::updateOrCreate(
                        [
                            'course_id' => $course->id,
                            'type' => 'block',
                            'block_number' => 1,
                        ],
                        [
                            'title' => 'Один блок',
                            'price' => $blockPrice,
                            'is_active' => true,
                        ]
                    );
                    $tariffsCount++;
                }
            }
        }

        fclose($file);

        $this->info("✅ Успешно импортировано курсов: {$coursesCount}");
        $this->info("✅ Автоматически создано тарифов: {$tariffsCount}");
    }
}