<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use RuntimeException;

/** Копирование отклонено до записи; сообщение показывается куратору как есть. */
final class LessonCopyRefused extends RuntimeException {}
