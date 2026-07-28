<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // Мягкий ретрай вместо голого «419 Page Expired» на чекауте/оплате.
        // Студент остаётся авторизован (remember-me), поэтому возвращаем его
        // обратно на чекаут со свежим токеном и понятным сообщением.
        //
        // То же самое — на входе (/login, /shop/login): токен протухает
        // (вкладка простояла дольше жизни сессии, повторный сабмит по
        // уже открытой странице после первой неудачи, hand-off между
        // встроенным браузером мессенджера и обычным), и вместо понятного
        // повтора студент упирался в голую страницу без выхода — это и
        // читалось как «сайт не пускает».
        //
        // Ловим ОБЁРТКУ, а не сам TokenMismatchException. Handler::render()
        // сначала прогоняет исключение через prepareException(), и тот меняет
        // TokenMismatchException на HttpException(419, ..., previous: $e), и
        // только ПОТОМ вызывает renderable-колбэки (Laravel 12,
        // vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/Handler.php,
        // render() строки 616–620). Колбэк с типом TokenMismatchException не
        // срабатывает никогда — ветка чекаута ниже пролежала мёртвой с
        // 25-06-2026 (295ea8b8), теста на неё не было, и «мягкий ретрай на
        // оплате» всё это время оставался обещанием в комментарии.
        $this->renderable(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419 || ! $e->getPrevious() instanceof TokenMismatchException) {
                return null; // прочие HttpException — поведение по умолчанию
            }

            $this->logCsrfMismatch($request);

            if ($request->routeIs('payment.create', 'checkout.*')) {
                return redirect()->back()->with(
                    'error',
                    'Сессия обновилась — нажмите «К безопасной оплате» ещё раз.'
                );
            }

            // /login — обычный POST формы. Redirect::back несёт свежий CSRF-
            // токен на той же странице, так что повторный сабмит проходит.
            if ($request->routeIs('login.post')) {
                return redirect()->back()->with(
                    'error',
                    'Сессия обновилась — введите данные ещё раз.'
                );
            }

            // /shop/login — модалка на Alpine, шлёт fetch() с Accept: json и
            // сама читает {success,message}. Redirect сюда не годится: fetch
            // тихо пройдёт по 302 и получит HTML вместо JSON. С H1774 модалка
            // подтягивает свежий токен из /csrf-token перед КАЖДЫМ сабмитом
            // (partials/csrf-token-refresh.blade.php), так что простое «попробуйте
            // ещё раз» теперь честно — повтор реально несёт новый токен, а не
            // тот же самый. Раньше здесь просили F5, потому что без этого шага
            // повтор гарантированно проваливался тем же 419.
            if ($request->routeIs('shop.login')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Сессия обновилась — попробуйте войти ещё раз.',
                ], 419);
            }

            return null; // прочие 419 — поведение по умолчанию
        });

        // Загрузка тяжелее post_max_size обрывается на уровне PHP: тело запроса
        // отбрасывается, валидация Laravel до файлов уже не доходит, и студент
        // получает голую страницу 413. Возвращаем его в форму с внятным текстом.
        // Набранный ответ при этом всё равно потерян — PHP выбросил тело
        // запроса целиком, поэтому в сообщении просим сохранить черновик.
        $this->renderable(function (PostTooLargeException $e, Request $request) {
            return redirect()->back()->with(
                'error',
                'Файлы слишком тяжёлые — сервер отклонил загрузку целиком. '
                .'Прикрепите меньше файлов за раз или сожмите видео, '
                .'а длинный ответ сначала сохраните черновиком.'
            );
        });
    }

    /**
     * H1773 — до 28-07-2026 CSRF-несовпадение нигде не логировалось: про
     * инцидент 27-07-2026 (голый 419 на /login и /shop/login весь день)
     * узнали только потому, что человек вручную прочитал nginx-логи после
     * жалоб. Теперь каждое несовпадение оставляет одну структурированную
     * запись — материал для `csrf:mismatch-digest`.
     *
     * Никакого IP, сырого User-Agent или user_id (тот же контракт приватности,
     * что у game_events, H1360) — только маршрут, метод, факт авторизации и
     * грубая корзина клиента.
     *
     * Обёрнуто в try/catch намеренно: сломанный канал логов не должен
     * превращать восстанавливаемый 419 в 500 (тот же fail-open, что у
     * scheduler heartbeat, H1713).
     */
    private function logCsrfMismatch(Request $request): void
    {
        try {
            Log::channel('csrf_mismatch')->warning('CSRF token mismatch', [
                'route' => $request->route()?->getName(),
                'method' => $request->method(),
                'path' => $request->path(),
                'authenticated' => Auth::check(),
                'expects_json' => $request->expectsJson(),
                'client_bucket' => $this->clientBucket($request->userAgent()),
            ]);
        } catch (Throwable) {
            // Fail-open: логирование телеметрии не должно ронять ответ.
        }
    }

    /**
     * Грубая, не PII-раскрывающая классификация клиента: встроенный браузер
     * мессенджера (см. комментарий про shop.login выше — там токен в <meta>
     * не обновляется без полной перезагрузки страницы) против обычного.
     */
    private function clientBucket(?string $userAgent): string
    {
        if (! $userAgent) {
            return 'unknown';
        }

        $inAppMarkers = ['Telegram', 'VK/', 'com.vkontakte', 'FBAN', 'FBAV', 'Instagram', 'MicroMessenger', 'WhatsApp'];
        foreach ($inAppMarkers as $marker) {
            if (str_contains($userAgent, $marker)) {
                return 'in_app_messenger';
            }
        }

        return 'browser';
    }
}
