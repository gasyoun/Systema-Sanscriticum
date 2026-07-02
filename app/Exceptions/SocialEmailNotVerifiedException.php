<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Бросается, когда соц-провайдер отдал email, который уже принадлежит
 * существующему аккаунту, но НЕ подтвердил владение им. Автолинковка в этом
 * случае = захват чужого кабинета, поэтому вход отклоняется.
 */
class SocialEmailNotVerifiedException extends RuntimeException {}
