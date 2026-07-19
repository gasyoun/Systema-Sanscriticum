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
        $this->renderable(function (TokenMismatchException $e, Request $request) {
            if ($request->routeIs('payment.create', 'checkout.*')) {
                return redirect()->back()->with(
                    'error',
                    'Сессия обновилась — нажмите «К безопасной оплате» ещё раз.'
                );
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
