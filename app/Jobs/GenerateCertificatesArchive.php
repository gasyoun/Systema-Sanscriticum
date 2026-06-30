<?php

namespace App\Jobs;

use App\Models\Certificate;
use App\Models\Group;
use App\Models\User;
use App\Services\CertificateService;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class GenerateCertificatesArchive implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // Разрешаем работать 10 минут

    protected $groupId;

    protected $adminUserId;

    protected $teacherId;

    /**
     * @param  int|null  $teacherId  Если задан — в архив попадут только сертификаты
     *                               курсов этого преподавателя (course.teacher_id).
     *                               0 = преподаватель без курсов → пустой архив.
     *                               null = админ → без ограничения.
     */
    public function __construct($groupId, $adminUserId, $teacherId = null)
    {
        $this->groupId = $groupId;
        $this->adminUserId = $adminUserId;
        $this->teacherId = $teacherId;
    }

    public function handle(): void
    {
        // 1. Настройки памяти
        ini_set('memory_limit', '-1');

        // 2. Ищем группу
        $group = Group::with('users')->find($this->groupId);
        if (! $group) {
            return;
        }

        // Архив именно ВЫБРАННОЙ группы: сертификаты её студентов И только по
        // курсам этой группы. Без фильтра по курсам в архив попадали сертификаты
        // других курсов тех же студентов (студент состоит в нескольких группах),
        // из-за чего «выкачивалось всё».
        $userIds = $group->users->pluck('id');
        $courseIds = $group->courses()->pluck('courses.id');

        $certificates = Certificate::whereIn('user_id', $userIds)
            ->whereIn('course_id', $courseIds)
            ->when($this->teacherId !== null, function ($q) {
                // Преподавателю — только сертификаты его курсов (основной ИЛИ со-препод).
                $q->whereHas('course', fn ($c) => $c->forTeacher($this->teacherId));
            })
            ->with(['user', 'course'])
            ->get();

        if ($certificates->isEmpty()) {
            // Молча не выходим: иначе админ ждёт «архив готов», который не придёт.
            if ($recipient = User::find($this->adminUserId)) {
                Notification::make()
                    ->title('Архив пуст')
                    ->warning()
                    ->body("В группе «{$group->name}» нет сертификатов по её курсам. Проверьте, что группа привязана к курсу и сертификаты выданы.")
                    ->sendToDatabase($recipient);
            }

            return;
        }

        // 3. Создаем ZIP
        $fileName = 'certificates_group_'.$this->groupId.'_'.time().'.zip';
        // Сохраняем в storage/app/public/archives
        $zipPath = storage_path('app/public/archives/'.$fileName);

        // Создаем папку если нет
        if (! file_exists(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $service = new CertificateService;

            foreach ($certificates as $cert) {
                try {
                    $pdf = $service->generatePdf($cert);
                    $pdfData = $pdf->output();
                    $safeName = \Illuminate\Support\Str::slug($cert->user->name.'-'.$cert->course->title, '_');
                    $zip->addFromString($safeName.'.pdf', $pdfData);

                    // JPG-дубль рядом с PDF. Если на сервере нет imagick/ghostscript —
                    // тихо кладём только PDF, архив не ломаем.
                    try {
                        $zip->addFromString($safeName.'.jpg', $service->pdfToJpeg($pdfData));
                    } catch (\Throwable $e) {
                    }
                } catch (\Exception $e) {
                    // Игнорируем ошибки генерации одного файла, чтобы не сломать весь архив
                    continue;
                }
            }
            $zip->close();
        }

        // 4. Отправляем уведомление админу
        // Используем наш надежный маршрут для принудительного скачивания
        $downloadUrl = url('/force-download/'.$fileName);

        $recipient = User::find($this->adminUserId);

        if ($recipient) {
            Notification::make()
                ->title('Архив сертификатов готов!')
                ->success()
                ->body("Группа: {$group->name}. Файлов: {$certificates->count()}")
                ->actions([
                    Action::make('download')
                        ->button()
                        ->label('Скачать ZIP')
                        ->url($downloadUrl, shouldOpenInNewTab: true),
                ])
                ->sendToDatabase($recipient);
        }
    }
}
