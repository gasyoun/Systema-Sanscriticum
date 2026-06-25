<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
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
    }
}
