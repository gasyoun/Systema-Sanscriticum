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
     * H1771 — маршруты со своим текстом про протухший CSRF-токен.
     *
     * Это ТОЛЬКО переопределение текста. Форма ответа (redirect или JSON)
     * выбирается ниже одинаково для всех маршрутов, по expectsJson().
     *
     * До H1771 здесь стоял allowlist из трёх ветвей, и маршрут, которого в
     * нём нет, получал голую страницу «419 Page Expired». Хрупкость была
     * именно в перечислении по имени: в routes/web.php сейчас 40 POST-
     * маршрутов, а покрыто было шесть — регистрация, восстановление пароля,
     * сдача домашки, смена профиля и остальные оставляли студента на
     * странице без выхода. Симптом возвращался бы при каждом новом POST-
     * маршруте, добавленном без мысли о CSRF: ровно так ветка чекаута и
     * пролежала незамеченной пять недель.
     *
     * Порядок значим — берётся первое совпадение.
     *
     * @var array<int, array{routes: array<int, string>, message: string}>
     */
    private const CSRF_MISMATCH_OVERRIDES = [
        [
            'routes' => ['payment.create', 'checkout.*'],
            'message' => 'Сессия обновилась — нажмите «К безопасной оплате» ещё раз.',
        ],
        [
            'routes' => ['login.post'],
            'message' => 'Сессия обновилась — введите данные ещё раз.',
        ],
        // shop.login — единственный fetch-вызывающий, которому «попробуйте ещё
        // раз» можно обещать честно: с H1774 модалка подтягивает свежий токен
        // из /csrf-token перед КАЖДЫМ сабмитом
        // (partials/csrf-token-refresh.blade.php), поэтому повтор реально несёт
        // новый токен. Просить F5, как раньше, стало бы неправдой. Именно
        // поэтому строка живёт здесь, в переопределениях, а не в общем
        // JSON-тексте ниже — тот обслуживает вызывающих БЕЗ такого обновления.
        [
            'routes' => ['shop.login'],
            'message' => 'Сессия обновилась — попробуйте войти ещё раз.',
        ],
    ];

    /**
     * Redirect::back возвращает пользователя на ту же страницу уже со свежим
     * токеном, поэтому достаточно «отправьте ещё раз».
     */
    private const CSRF_MISMATCH_DEFAULT_HTML = 'Сессия обновилась — отправьте форму ещё раз.';

    /**
     * А произвольный fetch/XHR страницу не перезагружает и, в отличие от
     * модалки shop.login (H1774), токен перед сабмитом не обновляет: тот, что
     * лежит в <meta>, остаётся старым, и повтор без reload упрётся в тот же
     * 419. Поэтому общий JSON-текст просит именно обновить страницу.
     */
    private const CSRF_MISMATCH_DEFAULT_JSON = 'Сессия обновилась — обновите страницу (F5) и попробуйте ещё раз.';

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

        // Мягкий ретрай вместо голого «419 Page Expired» — ПО УМОЛЧАНИЮ, на
        // любом web-POST, а не на перечисленных поимённо (H1771).
        //
        // Токен протухает буднично: вкладка простояла дольше жизни сессии,
        // повторный сабмит по уже открытой странице после первой неудачи,
        // hand-off между встроенным браузером мессенджера и обычным. Вместо
        // понятного повтора студент упирался в страницу без выхода — это и
        // читалось как «сайт не пускает».
        //
        // Ловим ОБЁРТКУ, а не сам TokenMismatchException. Handler::render()
        // сначала прогоняет исключение через prepareException(), и тот меняет
        // TokenMismatchException на HttpException(419, ..., previous: $e), и
        // только ПОТОМ вызывает renderable-колбэки (Laravel 12,
        // vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/Handler.php,
        // render() строки 616–620). Колбэк с типом TokenMismatchException не
        // срабатывает никогда — ветка чекаута пролежала мёртвой с 25-06-2026
        // (295ea8b8), теста на неё не было, и «мягкий ретрай на оплате» всё
        // это время оставался обещанием в комментарии.
        $this->renderable(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419 || ! $e->getPrevious() instanceof TokenMismatchException) {
                return null; // прочие HttpException — поведение по умолчанию
            }

            $this->logCsrfMismatch($request);

            // Livewire — единственное исключение из «graceful по умолчанию», и
            // именно поэтому его пришлось выяснять, а не предполагать (H1771).
            //
            // Обе панели Filament ходят через дефолтный livewire.update, а он
            // зарегистрирован как POST /livewire/update ->middleware('web')
            // (vendor/livewire/livewire/src/Mechanisms/HandleRequests/HandleRequests.php),
            // то есть проходит наш VerifyCsrfToken и попадает сюда же. При этом
            // клиент Livewire шлёт только Content-type + X-Livewire, без Accept
            // и без X-Requested-With (dist/livewire.js), поэтому expectsJson()
            // на нём ЛОЖЕН — по умолчанию он получил бы 302. А у Livewire есть
            // собственный обработчик протухшей страницы, завязанный ровно на
            // голый статус: `if (response.status === 419) handlePageExpiry()`.
            // Redirect его обходит (fetch молча идёт по 302 и получает 200 HTML),
            // и админ теряет несохранённую форму без единого предупреждения.
            // Отдаём такие запросы Laravel'у нетронутыми.
            if ($request->hasHeader('X-Livewire')) {
                return null;
            }

            $message = $this->csrfMismatchMessage($request);

            // Форма ответа — по вызывающему, не по имени маршрута. Тот же
            // контракт {success,message}, что уже отдаёт AuthController::shopLogin()
            // и разбирает Alpine-модалка (`!response.ok || !data.success`
            // → data.message), чтобы не заводить второй.
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 419);
            }

            return redirect()->back()->with('error', $message);
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
     * H1771 — текст для конкретного маршрута, иначе общий по форме ответа.
     */
    private function csrfMismatchMessage(Request $request): string
    {
        foreach (self::CSRF_MISMATCH_OVERRIDES as $override) {
            if ($request->routeIs(...$override['routes'])) {
                return $override['message'];
            }
        }

        return $request->expectsJson()
            ? self::CSRF_MISMATCH_DEFAULT_JSON
            : self::CSRF_MISMATCH_DEFAULT_HTML;
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
     * мессенджера против обычного. Разделение осмысленно, потому что hand-off
     * между встроенным браузером и обычным — один из будничных способов
     * получить протухший токен.
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
