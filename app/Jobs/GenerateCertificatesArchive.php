<?php

namespace App\Jobs;

use App\Models\Certificate;
use App\Models\Group;
use App\Models\User;
use App\Services\CertificateService;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class GenerateCertificatesArchive implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // Разрешаем работать 10 минут

    /** Prevent double-click dispatches while the first archive is running. */
    public int $uniqueFor = 720;

    protected $groupId;

    protected $adminUserId;

    protected $teacherId;

    public readonly string $operationId;

    /**
     * @param  int|null  $teacherId  Если задан — в архив попадут только сертификаты
     *                               курсов этого преподавателя (course.teacher_id).
     *                               0 = преподаватель без курсов → пустой архив.
     *                               null = админ → без ограничения.
     */
    public function __construct($groupId, $adminUserId, $teacherId = null, ?string $operationId = null)
    {
        $this->groupId = $groupId;
        $this->adminUserId = $adminUserId;
        $this->teacherId = $teacherId;
        $this->operationId = $operationId ?? (string) Str::uuid();

        // Keep sync/database development modes working. Production Redis jobs
        // use the long reservation and the certificates-only worker.
        if (config('queue.default') === 'redis') {
            $this->onConnection('redis-long');
        }
        $this->onQueue('certificates');
    }

    public function uniqueId(): string
    {
        return implode(':', [
            $this->groupId,
            $this->adminUserId,
            $this->teacherId ?? 'all',
        ]);
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
                $this->sendNotificationOnce(
                    $recipient,
                    Notification::make($this->operationId)
                        ->title('Архив пуст')
                        ->warning()
                        ->body("В группе «{$group->name}» нет сертификатов по её курсам. Проверьте, что группа привязана к курсу и сертификаты выданы.")
                );
            }

            return;
        }

        // 3. Создаем ZIP
        // Имя содержит случайный токен, а не time(): иначе имя предсказуемо
        // (group_id перебираемый + узкое окно секунд) и любой залогиненный мог бы
        // скачать чужой архив сертификатов через /force-download (IDOR).
        // operationId is serialized with the job, so a Redis redelivery reuses
        // the same output instead of creating a second archive and notification.
        $fileName = 'certificates_group_'.$this->groupId.'_'.$this->operationId.'.zip';
        // H3310: приватный диск вместо storage/app/public/archives. UUID в имени
        // — не секрет: ссылку /storage/archives/... угадать нельзя, но подсмотреть
        // (логи, шаринги, история браузера) можно. Архив с персональными
        // сертификатами отдаёт только staff-маршрут force-download.
        $relativePath = 'archives/'.$fileName;
        $zipPath = Storage::disk('local')->path($relativePath);
        $temporaryZipPath = $zipPath.'.tmp';

        if (Storage::disk('local')->exists($relativePath)) {
            $this->notifyArchiveReady($group, $certificates->count(), $fileName);

            return;
        }

        // Создаем папку если нет
        Storage::disk('local')->makeDirectory('archives');

        $zip = new ZipArchive;
        // Раньше сборка шла в `if (open === true) { ... }`, а уведомление «Архив
        // готов!» отправлялось ВСЕГДА — при сбое open() админ получал ссылку на
        // несуществующий файл. Теперь при неудаче бросаем исключение (сработает
        // failed(), админ узнает о провале), а уведомление об успехе — только
        // после реальной сборки.
        if ($zip->open($temporaryZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Не удалось создать ZIP-архив: {$temporaryZipPath}");
        }

        // Через контейнер, а не `new`: тесты подменяют генератор PDF свапом,
        // не гоняя DomPDF и внешний QR-сервис в каждом прогоне.
        $service = app(CertificateService::class);

        foreach ($certificates as $cert) {
            try {
                $pdf = $service->generatePdf($cert);
                $pdfData = $pdf->output();
                $safeName = Str::slug($cert->user->name.'-'.$cert->course->title, '_');
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
        if (! $zip->close()) {
            throw new \RuntimeException("Не удалось завершить ZIP-архив: {$temporaryZipPath}");
        }

        // Publish only a fully closed ZIP. A killed worker may leave a .tmp,
        // but a redelivery will overwrite it and never mistake it for success.
        if (! rename($temporaryZipPath, $zipPath)) {
            throw new \RuntimeException("Не удалось опубликовать ZIP-архив: {$zipPath}");
        }

        // 4. Отправляем уведомление админу
        $this->notifyArchiveReady($group, $certificates->count(), $fileName);
    }

    private function notifyArchiveReady(Group $group, int $certificateCount, string $fileName): void
    {
        if ($recipient = User::find($this->adminUserId)) {
            // Используем наш надежный маршрут для принудительного скачивания.
            $downloadUrl = url('/force-download/'.$fileName);

            $this->sendNotificationOnce(
                $recipient,
                Notification::make($this->operationId)
                    ->title('Архив сертификатов готов!')
                    ->success()
                    ->body("Группа: {$group->name}. Файлов: {$certificateCount}")
                    ->actions([
                        Action::make('download')
                            ->button()
                            ->label('Скачать ZIP')
                            ->url($downloadUrl, shouldOpenInNewTab: true),
                    ])
            );
        }
    }

    private function sendNotificationOnce(User $recipient, Notification $notification): void
    {
        if ($recipient->notifications()->whereKey($this->operationId)->exists()) {
            return;
        }

        // Filament::sendToDatabase() wraps into a Laravel DatabaseNotification
        // that does NOT inherit Notification::make($id) as the row PK — Laravel
        // assigns a fresh UUID. Then whereKey(operationId) never hits and every
        // redelivery creates another bell. Pin the Laravel notification id to
        // operationId so the exists() guard and the PK match.
        $databaseNotification = $notification->toDatabase();
        $databaseNotification->id = $this->operationId;
        $recipient->notify($databaseNotification);
    }

    /**
     * Провал джобы (в т.ч. сбой ZIP) — сообщаем инициатору, иначе он ждёт
     * «архив готов», который уже не придёт.
     */
    public function failed(\Throwable $e): void
    {
        if ($recipient = User::find($this->adminUserId)) {
            $this->sendNotificationOnce(
                $recipient,
                Notification::make($this->operationId)
                    ->title('Не удалось собрать архив сертификатов')
                    ->danger()
                    ->body('Произошла ошибка при формировании ZIP. Попробуйте ещё раз позже или обратитесь к администратору.')
            );
        }
    }
}
