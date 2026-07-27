<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
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
        $this->renderable(function (TokenMismatchException $e, Request $request) {
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
            // тихо пройдёт по 302 и получит HTML вместо JSON, а CSRF-токен в
            // <meta> у модалки всё равно останется старым без перезагрузки
            // страницы — поэтому явно просим обновить страницу, а не «попробуйте
            // ещё раз» (повтор без reload гарантированно провалится тем же 419).
            if ($request->routeIs('shop.login')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Сессия обновилась — обновите страницу (F5) и попробуйте войти ещё раз.',
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
}
