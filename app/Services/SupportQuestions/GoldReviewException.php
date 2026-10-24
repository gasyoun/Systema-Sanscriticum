<?php

namespace App\Services\SupportQuestions;

/**
 * H5773 — доменная ошибка gold-ревью (некорректная метка, чужой item,
 * незавершённая выборка, предзаполненный лист). Строка сообщения —
 * агрегатная, без текстов сообщений.
 */
class GoldReviewException extends \RuntimeException {}
