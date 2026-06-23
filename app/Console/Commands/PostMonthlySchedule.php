<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CertificateService;
use App\Services\MonthlyScheduleDigest;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PostMonthlySchedule extends Command
{
    protected $signature = 'schedule:post-monthly {--dry : Собрать и показать текст, без отправки}';

    protected $description = 'Ежемесячный пост «сейчас идут курсы» (текст + JPG) в n8n-вебхук → ВК/ТГ.';

    public function handle(MonthlyScheduleDigest $digest): int
    {
        $courses = $digest->courses();
        if ($courses === []) {
            $this->info('В этом месяце нет идущих курсов — пост не формируем.');

            return self::SUCCESS;
        }

        $textTg = $digest->textTelegram();
        $textVk = $digest->textVk();

        if ($this->option('dry')) {
            $this->line($textTg);
            $this->newLine();

            // Рендерим картинку и в dry — чтобы доводить дизайн без публикации.
            $imageUrl = $this->renderImage($courses);
            if ($imageUrl) {
                $this->info('JPG: '.$imageUrl);
            } else {
                $this->warn('JPG не сгенерирован (imagick/ghostscript недоступны).');
            }

            $this->info('DRY-режим: курсов '.count($courses).', отправка пропущена.');

            return self::SUCCESS;
        }

        $imageUrl = $this->renderImage($courses);

        $webhook = config('services.n8n.monthly_schedule_webhook');
        if (empty($webhook)) {
            Log::warning('PostMonthlySchedule: N8N_MONTHLY_SCHEDULE_WEBHOOK не задан — пропуск.');
            $this->warn('Вебхук n8n не настроен (N8N_MONTHLY_SCHEDULE_WEBHOOK) — отправка пропущена.');

            return self::SUCCESS;
        }

        try {
            $response = Http::withHeaders([
                'X-Webhook-Secret' => (string) config('services.n8n.monthly_schedule_secret'),
            ])->timeout(15)->post($webhook, [
                'action' => 'monthly_schedule_post',
                'generated_at' => now()->format('d.m.Y H:i'),
                'text_tg' => $textTg,
                'text_vk' => $textVk,
                'image_url' => $imageUrl,
                'courses' => $courses,
            ]);

            if (! $response->successful()) {
                Log::error('PostMonthlySchedule: n8n вернул '.$response->status(), ['body' => $response->body()]);
                $this->error('n8n вернул статус '.$response->status());

                return self::FAILURE;
            }
        } catch (\Throwable $e) {
            Log::error('PostMonthlySchedule: сбой отправки в n8n: '.$e->getMessage());
            $this->error('Сбой отправки: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Пост отправлен в n8n: курсов '.count($courses).($imageUrl ? ', с картинкой.' : ', без картинки (imagick недоступен).'));

        return self::SUCCESS;
    }

    /**
     * Рендер карточки в JPG через тот же пайплайн, что у сертификатов
     * (Blade → DomPDF → Imagick). Возвращает публичный URL или null, если
     * imagick/ghostscript недоступны — тогда постим только текст.
     *
     * @param  list<array{title:string, teacher:?string, schedule:?string, url:?string}>  $courses
     */
    private function renderImage(array $courses): ?string
    {
        if (! CertificateService::jpegSupported()) {
            return null;
        }

        try {
            $canvasHeight = $this->estimateCanvasHeight($courses);

            $pdf = Pdf::loadView('promo.monthly-schedule-card', [
                'courses' => $courses,
                'month' => mb_convert_case(now()->locale('ru')->translatedFormat('F Y'), MB_CASE_TITLE, 'UTF-8'),
                'site' => preg_replace('~^https?://~', '', rtrim((string) config('app.url'), '/')),
                'canvasHeight' => $canvasHeight,
                'logoBase64' => $this->logoBase64(),
            ])->setPaper([0, 0, 1100, $canvasHeight]);

            $jpeg = app(CertificateService::class)->pdfToJpeg($pdf->output(), dpi: 150);

            $path = 'monthly/schedule-'.now()->format('Y-m').'.jpg';
            Storage::disk('public')->put($path, $jpeg);

            // Cache-busting: Telegram кэширует sendPhoto по URL и при повторной
            // отправке того же URL отдаёт старую копию (имя файла фиксированное
            // на месяц). Хэш содержимого меняет URL при смене картинки → TG
            // перекачивает свежую. VK байты и так качает заново.
            return Storage::disk('public')->url($path).'?v='.substr(md5($jpeg), 0, 10);
        } catch (\Throwable $e) {
            Log::error('PostMonthlySchedule: не удалось сгенерировать JPG: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Высота холста под фактический контент, чтобы постер был ОДНОЙ страницей
     * (иначе DomPDF пагинирует, а JPG берёт только стр. 0) и снизу не зиял
     * провал. Учитываем перенос длинных названий: DejaVu в DomPDF шире
     * экранного шрифта, поэтому считаем строки по длине «название — препод».
     *
     * @param  list<array{title:string, teacher:?string, schedule:?string, url:?string}>  $courses
     */
    private function estimateCanvasHeight(array $courses): int
    {
        $height = 250; // шапка (лого + заголовок) + разделитель + футер + поля

        // Ширина текстовой колонки в px (1100 − поля − маркер).
        $textWidth = 1100 - 2 * 56 - 26;

        foreach ($courses as $c) {
            // Оценка ширины строки: название кеглем 20 (bold), препод — 16.
            // Коэффициент 0.58/0.55 — средняя ширина глифа DejaVu (em).
            $px = mb_strlen($c['title']) * 20 * 0.58;
            if (! empty($c['teacher'])) {
                $px += mb_strlen(' — '.$c['teacher']) * 16 * 0.55;
            }
            $lines = max(1, (int) ceil($px / $textWidth));

            $height += 24 + $lines * 28;          // отступы строки + строки названия
            if (! empty($c['schedule'])) {
                $height += 22;                    // строка расписания
            }
        }

        return $height;
    }

    /**
     * Логотип ОРС в data-URI для встраивания в DomPDF (как фон сертификата).
     * Null, если файла нет — тогда карточка рендерится без лого.
     */
    private function logoBase64(): ?string
    {
        $path = public_path('images/logo.png');
        if (! is_file($path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode((string) file_get_contents($path));
    }
}
