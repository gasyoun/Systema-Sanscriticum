@extends('auth.two-factor-layout')

@section('title', 'Подключение двухфакторной защиты')

@section('content')
    <h1>Подключите двухфакторную защиту</h1>
    <p class="muted">Роль «{{ $roleLabel }}» даёт доступ к деньгам и данным учеников.
        Поэтому вход в панель подтверждается кодом из приложения-аутентификатора.</p>

    @if ($qr)
        <div class="qr">{!! $qr !!}</div>
    @endif

    <p class="muted">Откройте Google Authenticator, Aegis, 1Password или другое TOTP-приложение
        и отсканируйте QR. Если камера недоступна, введите ключ вручную:</p>
    <div class="key">{{ $groups }}</div>

    @error('code')
        <p class="error" role="alert">{{ $message }}</p>
    @enderror

    <form method="POST" action="{{ route('two-factor.verify') }}">
        @csrf
        <label for="code">Код из приложения (6 цифр)</label>
        <input id="code" name="code" value="{{ old('code') }}" inputmode="numeric"
               autocomplete="one-time-code" autofocus required>
        <button type="submit">Включить 2FA</button>
    </form>

    <form method="POST" action="{{ route('two-factor.logout') }}" class="actions">
        @csrf
        <button type="submit" class="secondary">Выйти</button>
    </form>
@endsection
