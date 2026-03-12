<?php

namespace App\Jobs;

use App\Models\Certificate;
use App\Models\Group;
use App\Models\User;
use App\Services\CertificateService;
use Filament\Notifications\Notification;
use Filament\Notifications\Actions\Action;
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

    public function __construct($groupId, $adminUserId)
    {
        $this->groupId = $groupId;
        $this->adminUserId = $adminUserId;
    }

    public function handle(): void
    {
        // 1. Настройки памяти
        ini_set('memory_limit', '-1');

        // 2. Ищем группу
        $group = Group::with('users')->find($this->groupId);
        if (!$group) return;

        $userIds = $group->users->pluck('id');
        $certificates = Certificate::whereIn('user_id', $userIds)
            ->with(['user', 'course'])
            ->get();

        if ($certificates->isEmpty()) return;

        // 3. Создаем ZIP
        $fileName = 'certificates_group_' . $this->groupId . '_' . time() . '.zip';
        // Сохраняем в storage/app/public/archives
        $zipPath = storage_path('app/public/archives/' . $fileName);
        
        // Создаем папку если нет
        if (!file_exists(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            $service = new CertificateService();

            foreach ($certificates as $cert) {
                try {
                    $pdf = $service->generatePdf($cert);
                    $safeName = \Illuminate\Support\Str::slug($cert->user->name . '-' . $cert->course->title, '_');
                    $zip->addFromString($safeName . '.pdf', $pdf->output());
                } catch (\Exception $e) {
                    // Игнорируем ошибки генерации одного файла, чтобы не сломать весь архив
                    continue;
                }
            }
            $zip->close();
        }

        // 4. Отправляем уведомление админу
        // Используем наш надежный маршрут для принудительного скачивания
        $downloadUrl = url('/force-download/' . $fileName);
        
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
